<?php

namespace Tests\Feature\Codes;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\CodeGenerator;
use App\Domain\Crypto\CodeHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class CodeGeneratorTest extends DbTestCase
{
    private Fixtures $f;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Fixtures;
        $this->dir = sys_get_temp_dir().'/codegen-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function generator(?callable $randomBytes = null): CodeGenerator
    {
        return new CodeGenerator($this->f->lifecycle(), new CodeHasher, $this->f->audit, $randomBytes);
    }

    private function out(string $name = 'codigos.csv'): string
    {
        return $this->dir.'/'.$name;
    }

    /** @return list<string> códigos exportados (sin la cabecera) */
    private function exported(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertSame('codigo', array_shift($lines), 'cabecera del CSV');

        return $lines;
    }

    /** Flujo de bytes determinista para pruebas. */
    private static function stream(string $label): \Closure
    {
        $i = 0;

        return static function (int $n) use ($label, &$i): string {
            return substr(hash_hmac('sha256', (string) $i++, $label, true), 0, $n);
        };
    }

    // --- Camino feliz ----------------------------------------------------------------------------------------------

    public function test_genera_exactamente_los_codigos_del_pool_y_los_exporta(): void
    {
        $id = $this->f->draft(); // 10 códigos
        $result = $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');

        $codes = $this->exported($this->out());
        $this->assertCount(10, $codes);
        $this->assertSame(10, $result->count);
        $this->assertSame(10, DB::table('codes')->where('batch_id', $id)->count());
        $this->assertCount(10, array_unique($codes), 'todos distintos');
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^RULETA-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{3}$/', $c);
        }
    }

    public function test_el_archivo_exportado_queda_con_permisos_0600_y_sin_residuos(): void
    {
        $id = $this->f->draft();
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->out())), -4));
        $this->assertFileDoesNotExist($this->out().'.partial');
    }

    public function test_cada_codigo_exportado_corresponde_a_una_huella_de_la_base_y_viceversa(): void
    {
        $id = $this->f->draft();
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');

        $hasher = new CodeHasher;
        $fromFile = array_map(fn (string $c) => bin2hex($hasher->hash(CodeFormat::normalize($c))), $this->exported($this->out()));
        $fromDb = DB::table('codes')->where('batch_id', $id)->select(DB::raw("encode(code_hash, 'hex') AS h"))->pluck('h')->all();

        sort($fromFile);
        sort($fromDb);
        $this->assertSame($fromDb, $fromFile, 'el conjunto de huellas de la base es exactamente el de los códigos exportados');
    }

    public function test_los_indices_quedan_contiguos_de_0_a_n_menos_1(): void
    {
        $id = $this->f->draft();
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana', chunk: 3);
        $this->assertSame(range(0, 9), DB::table('codes')->where('batch_id', $id)->orderBy('idx')->pluck('idx')->map(fn ($i) => (int) $i)->all());
    }

    public function test_el_tamano_de_tanda_no_cambia_el_resultado_esencial(): void
    {
        foreach ([1, 3, 10, 500] as $chunk) {
            $id = $this->f->draft();
            $this->generator()->generate($id, 'RASPA', $this->out("c$chunk.csv"), 'ana', $chunk);
            $this->assertCount(10, $this->exported($this->out("c$chunk.csv")), "tanda $chunk");
            $this->assertSame(10, DB::table('codes')->where('batch_id', $id)->count(), "tanda $chunk");
        }
    }

    // --- Lo que NO debe quedar en la base --------------------------------------------------------------------------

    public function test_el_texto_en_claro_no_aparece_en_ninguna_tabla(): void
    {
        $id = $this->f->draft();
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
        $codes = $this->exported($this->out());

        $dump = json_encode([
            DB::select('SELECT * FROM audit_log'),
            DB::select("SELECT id, batch_id, idx, encode(code_hash,'hex') h, encode(prize_enc,'hex') p FROM codes"),
            DB::select('SELECT id, name, state, pool::text FROM batches'),
        ]);
        foreach ($codes as $c) {
            $canonical = CodeFormat::normalize($c);
            $this->assertStringNotContainsString($canonical, $dump);
            $this->assertStringNotContainsString($c, $dump);
            $this->assertStringNotContainsString(substr($canonical, 0, 10), $dump);
        }
    }

    public function test_la_bitacora_registra_un_resumen_con_el_sha256_del_archivo(): void
    {
        $id = $this->f->draft();
        $result = $this->generator()->generate($id, 'RULETA', $this->out(), 'ana', chunk: 3);

        $this->assertSame(hash_file('sha256', $this->out()), $result->sha256);
        $entries = DB::table('audit_log')->where('subject_id', $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['batch.created', 'batch.codes_generated'], $entries, 'una sola entrada de resumen, no una por tanda');

        $data = json_decode(DB::selectOne("SELECT data::text AS d FROM audit_log WHERE action = 'batch.codes_generated'")->d, true);
        $expected = ['count' => 10, 'export_sha256' => $result->sha256, 'prefix' => 'RULETA'];
        ksort($data); // jsonb reordena las claves (por largo); lo que importa es el contenido
        ksort($expected);
        $this->assertSame($expected, $data);
        $this->assertNull($this->f->audit->verifyChain());
    }

    // --- Rechazos --------------------------------------------------------------------------------------------------

    public function test_rechazos_no_dejan_archivo_ni_codigos(): void
    {
        $id = $this->f->draft();
        file_put_contents($this->out('existente.csv'), 'no tocar');
        file_put_contents($this->out('parcial.csv.partial'), 'no tocar');

        $casos = [
            'prefijo en minúsculas' => fn () => $this->generator()->generate($id, 'ruleta', $this->out('a.csv'), 'ana'),
            'prefijo demasiado corto' => fn () => $this->generator()->generate($id, 'R', $this->out('b.csv'), 'ana'),
            'archivo ya existente' => fn () => $this->generator()->generate($id, 'RULETA', $this->out('existente.csv'), 'ana'),
            'parcial ya existente' => fn () => $this->generator()->generate($id, 'RULETA', $this->out('parcial.csv'), 'ana'),
            'lote inexistente' => fn () => $this->generator()->generate('00000000-0000-0000-0000-000000000000', 'RULETA', $this->out('c.csv'), 'ana'),
        ];
        foreach ($casos as $nombre => $caso) {
            try {
                $caso();
                $this->fail("debía rechazar: $nombre");
            } catch (InvalidBatch) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('no tocar', file_get_contents($this->out('existente.csv')), 'no se sobrescribe nada');
        $this->assertSame(0, DB::table('codes')->where('batch_id', $id)->count());
        foreach (['a.csv', 'b.csv', 'c.csv'] as $f) {
            $this->assertFileDoesNotExist($this->out($f));
        }
    }

    public function test_tamano_de_tanda_invalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator()->generate($this->f->draft(), 'RULETA', $this->out(), 'ana', 0);
    }

    public function test_solo_se_generan_codigos_para_un_borrador(): void
    {
        $id = $this->f->committed();
        $this->expectException(InvalidBatch::class);
        $this->expectExceptionMessage('committed');
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
    }

    public function test_la_generacion_es_de_una_sola_vez(): void
    {
        $id = $this->f->draft();
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
        $this->expectException(InvalidBatch::class);
        $this->expectExceptionMessage('una sola vez');
        $this->generator()->generate($id, 'RULETA', $this->out('otra.csv'), 'ana');
    }

    public function test_un_lote_con_codigos_a_medias_no_se_completa(): void
    {
        $id = $this->f->draft();
        $this->f->addCodes($id, 4); // 4 de 10 ya existen y su texto en claro no se puede recuperar
        $this->expectException(InvalidBatch::class);
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
    }

    // --- Todo o nada ------------------------------------------------------------------------------------------------

    public function test_si_falla_a_mitad_no_queda_nada(): void
    {
        $id = $this->f->draft();
        $calls = 0;
        $flaky = function (int $n) use (&$calls): string {
            // 10 códigos en tandas de 7: la primera tanda usa las llamadas 1-7 (y ya está guardada dentro de la transacción);
            // la 9.ª cae en plena segunda tanda.
            if (++$calls > 8) {
                throw new \RuntimeException('falla simulada del generador');
            }

            return substr(hash('sha256', "x$calls", true), 0, $n);
        };

        try {
            $this->generator($flaky)->generate($id, 'RULETA', $this->out(), 'ana', chunk: 7);
            $this->fail('debía fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame('falla simulada del generador', $e->getMessage());
        }

        $this->assertSame(0, DB::table('codes')->where('batch_id', $id)->count(), 'ninguna huella quedó guardada');
        $this->assertFileDoesNotExist($this->out());
        $this->assertFileDoesNotExist($this->out().'.partial', 'ningún archivo con códigos de un lote que no existe');
        $this->assertSame('draft', $this->f->batchRow($id)->state);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'batch.codes_generated')->count());
    }

    public function test_tras_un_fallo_se_puede_volver_a_intentar(): void
    {
        $id = $this->f->draft();
        try {
            $this->generator(fn () => throw new \RuntimeException('x'))->generate($id, 'RULETA', $this->out(), 'ana');
        } catch (\RuntimeException) {
        }
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
        $this->assertCount(10, $this->exported($this->out()));
    }

    // --- Colisiones ---------------------------------------------------------------------------------------------------

    public function test_los_repetidos_dentro_de_una_tanda_se_descartan(): void
    {
        $id = $this->f->draft();
        $seq = [];
        $stream = self::stream('repetidos');
        // Cada byte-fuente se entrega dos veces seguidas: la mitad de lo generado son repetidos.
        $source = function (int $n) use (&$seq, $stream): string {
            if ($seq === []) {
                $x = $stream($n);
                $seq = [$x, $x];
            }

            return array_shift($seq);
        };
        $this->generator($source)->generate($id, 'RULETA', $this->out(), 'ana');

        $this->assertCount(10, array_unique($this->exported($this->out())));
        $this->assertSame(10, DB::table('codes')->where('batch_id', $id)->count());
    }

    public function test_una_colision_con_un_codigo_de_otro_lote_se_resuelve_sorteando_de_nuevo(): void
    {
        $x = hash('sha256', 'colision', true);
        $x = substr($x, 0, 7);
        $existing = CodeFormat::canonicalFromBytes($x);

        // El código ya existe en OTRO lote.
        $other = $this->f->draft();
        $this->f->lifecycle()->addCodeHashes($other, [(new CodeHasher)->hash($existing)], 'ana');

        $id = $this->f->draft();
        $stream = self::stream('resolver');
        $first = true;
        $source = function (int $n) use (&$first, $x, $stream): string {
            if ($first) {
                $first = false;

                return $x; // el primer código de la tanda choca con el del otro lote
            }

            return $stream($n);
        };
        $this->generator($source)->generate($id, 'RULETA', $this->out(), 'ana');

        $codes = array_map(CodeFormat::normalize(...), $this->exported($this->out()));
        $this->assertCount(10, $codes);
        $this->assertNotContains($existing, $codes, 'el código en colisión no se reutiliza');
        $this->assertSame(10, DB::table('codes')->where('batch_id', $id)->count());
    }

    public function test_una_colision_que_no_se_resuelve_falla_sin_dejar_nada(): void
    {
        // Todos los códigos que puede producir la fuente ya existen en otro lote: nunca se logra una tanda sin colisión.
        $pool = [];
        $bytes = [];
        for ($i = 0; $i < 3; $i++) {
            $bytes[$i] = substr(hash('sha256', "fijo$i", true), 0, 7);
            $pool[] = (new CodeHasher)->hash(CodeFormat::canonicalFromBytes($bytes[$i]));
        }
        $other = $this->f->draft();
        $this->f->lifecycle()->addCodeHashes($other, $pool, 'ana');

        $id = $this->f->draft();
        $i = 0;
        $source = function () use (&$i, $bytes): string {
            return $bytes[$i++ % 3];
        };

        try {
            $this->generator($source)->generate($id, 'RULETA', $this->out(), 'ana', chunk: 3);
            $this->fail('debía fallar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('codes_hash_unique', $e->getMessage());
        }
        $this->assertSame(0, DB::table('codes')->where('batch_id', $id)->count());
        $this->assertFileDoesNotExist($this->out());
        $this->assertFileDoesNotExist($this->out().'.partial');
    }

    public function test_un_generador_de_bytes_constante_no_cuelga_el_proceso(): void
    {
        $id = $this->f->draft();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no produce códigos distintos');
        $this->generator(fn (int $n) => str_repeat("\x07", $n))->generate($id, 'RULETA', $this->out(), 'ana');
    }

    // --- De punta a punta ---------------------------------------------------------------------------------------------

    public function test_los_codigos_generados_alimentan_todo_el_ciclo_y_se_encuentran_por_su_huella(): void
    {
        $id = $this->f->draft(null, Fixtures::pool(none: 30, p10k: 15, p50k: 5));
        $this->generator()->generate($id, 'RULETA', $this->out(), 'ana');
        $this->f->lifecycle()->commit($id, 'ana');
        $this->f->activate($id);

        // Lo que hará la redención (B5): normalizar lo que escribió el usuario, hashear, buscar.
        $typed = strtolower(str_replace('-', ' ', $this->exported($this->out())[7])); // minúsculas y espacios
        $hash = (new CodeHasher)->hash(CodeFormat::normalize($typed));
        $row = DB::selectOne("SELECT idx, encode(prize_enc,'hex') AS p FROM codes WHERE code_hash = decode(?, 'hex')", [bin2hex($hash)]);

        $this->assertNotNull($row, 'el código tal como lo escribe el usuario se encuentra');
        $prize = $this->f->vault->decryptPrize($id, (int) $row->idx, hex2bin($row->p));
        $this->assertContains($prize, ['none', 'p10k', 'p50k']);
    }

    public function test_dos_lotes_con_la_fuente_real_no_comparten_codigos(): void
    {
        $a = $this->f->draft(null, Fixtures::pool(none: 300, p10k: 150, p50k: 50));
        $b = $this->f->draft(null, Fixtures::pool(none: 300, p10k: 150, p50k: 50));
        $this->generator()->generate($a, 'RULETA', $this->out('a.csv'), 'ana');
        $this->generator()->generate($b, 'RULETA', $this->out('b.csv'), 'ana');

        $all = array_merge($this->exported($this->out('a.csv')), $this->exported($this->out('b.csv')));
        $this->assertCount(1000, array_unique($all));
        $this->assertSame(1000, DB::table('codes')->count());
    }

    public function test_un_lote_de_20_mil_codigos_se_genera_en_segundos(): void
    {
        $id = $this->f->draft(null, [['prize_id' => 'none', 'quantity' => 19000], ['prize_id' => 'p10k', 'quantity' => 1000]]);
        $t = microtime(true);
        $this->generator()->generate($id, 'MASIVO', $this->out(), 'ana');
        $seconds = microtime(true) - $t;

        $this->assertCount(20000, array_unique($this->exported($this->out())));
        $this->assertSame(20000, DB::table('codes')->where('batch_id', $id)->count());
        $this->assertLessThan(60.0, $seconds);
    }
}
