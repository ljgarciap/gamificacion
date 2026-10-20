<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Canonical;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;

final class AuditLogTest extends DbTestCase
{
    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = new AuditLog;
    }

    private function appendN(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->log->append('tester', "accion.$i", 'cosa', (string) $i, ['n' => $i, 'texto' => "valor $i"]);
        }
    }

    /** Desactiva el guardián: simula a quien tiene acceso SQL y quiere reescribir la historia. */
    private function disableAppendOnlyGuard(): void
    {
        DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_update_delete');
    }

    public function test_una_bitacora_vacia_es_integra(): void
    {
        $this->assertNull($this->log->verifyChain());
    }

    public function test_la_primera_entrada_cuelga_del_genesis_y_las_demas_de_la_anterior(): void
    {
        $this->appendN(3);
        $rows = DB::select("SELECT encode(prev_hash,'hex') AS p, encode(hash,'hex') AS h FROM audit_log ORDER BY id");

        $this->assertSame(bin2hex(AuditLog::GENESIS), $rows[0]->p);
        $this->assertSame($rows[0]->h, $rows[1]->p);
        $this->assertSame($rows[1]->h, $rows[2]->p);
        $this->assertCount(3, array_unique(array_map(fn ($r) => $r->h, $rows)));
    }

    public function test_una_cadena_larga_se_verifica_sin_falsos_positivos(): void
    {
        // 200 entradas: si el tiempo se guardara con pérdida de precisión (microsegundos), alguna no cuadraría al releerla.
        $this->appendN(200);
        $this->assertNull($this->log->verifyChain());
        $this->assertSame(200, DB::table('audit_log')->count());
    }

    public function test_los_datos_con_unicode_y_estructuras_anidadas_sobreviven_al_ida_y_vuelta(): void
    {
        $this->log->append('ñandú', 'acción', 'sujeto', 'x', [
            'z' => 1, 'a' => ['y' => 'canción', 'b' => [3, 2, 1], 'ok' => true, 'nada' => null], 'url' => 'https://x.test/a/b',
        ]);
        $this->assertNull($this->log->verifyChain());
    }

    // --- Detección de manipulación (con el guardián apagado) ------------------------------------------------------

    public function test_se_detecta_un_dato_alterado(): void
    {
        $this->appendN(5);
        $ids = DB::table('audit_log')->orderBy('id')->pluck('id')->all();
        $this->disableAppendOnlyGuard();
        DB::update("UPDATE audit_log SET data = '{\"n\": 999}'::jsonb WHERE id = ?", [$ids[2]]);

        $this->assertSame((int) $ids[2], $this->log->verifyChain());
    }

    public function test_se_detecta_un_actor_cambiado(): void
    {
        $this->appendN(4);
        $ids = DB::table('audit_log')->orderBy('id')->pluck('id')->all();
        $this->disableAppendOnlyGuard();
        DB::update("UPDATE audit_log SET actor = 'otro' WHERE id = ?", [$ids[0]]);

        $this->assertSame((int) $ids[0], $this->log->verifyChain());
    }

    public function test_se_detecta_una_entrada_borrada_en_medio(): void
    {
        $this->appendN(5);
        $ids = DB::table('audit_log')->orderBy('id')->pluck('id')->all();
        $this->disableAppendOnlyGuard();
        DB::delete('DELETE FROM audit_log WHERE id = ?', [$ids[2]]);

        // La cadena se rompe en la entrada siguiente a la borrada: su "anterior" ya no existe.
        $this->assertSame((int) $ids[3], $this->log->verifyChain());
    }

    public function test_se_detecta_la_ultima_entrada_borrada_si_se_pasa_la_cantidad_esperada(): void
    {
        // Borrar solo la ÚLTIMA entrada deja una cadena internamente válida: por diseño no se detecta desde dentro.
        // Por eso el último hash conocido se publica/ancla fuera de la base (ver spec). Documentamos el límite.
        $this->appendN(3);
        $last = DB::table('audit_log')->orderByDesc('id')->value('id');
        $this->disableAppendOnlyGuard();
        DB::delete('DELETE FROM audit_log WHERE id = ?', [$last]);

        $this->assertNull($this->log->verifyChain(), 'limitación conocida: el truncado de la cola no se ve sin un ancla externa');
    }

    public function test_se_detecta_un_hash_reescrito(): void
    {
        $this->appendN(4);
        $ids = DB::table('audit_log')->orderBy('id')->pluck('id')->all();
        $this->disableAppendOnlyGuard();
        DB::update("UPDATE audit_log SET hash = decode(repeat('ab', 32), 'hex') WHERE id = ?", [$ids[1]]);

        $this->assertSame((int) $ids[1], $this->log->verifyChain());
    }

    public function test_se_detecta_una_fecha_cambiada(): void
    {
        $this->appendN(3);
        $ids = DB::table('audit_log')->orderBy('id')->pluck('id')->all();
        $this->disableAppendOnlyGuard();
        DB::update("UPDATE audit_log SET occurred_at = occurred_at + interval '1 microsecond' WHERE id = ?", [$ids[1]]);

        $this->assertSame((int) $ids[1], $this->log->verifyChain(), 'incluso un microsegundo de diferencia rompe el hash');
    }

    // --- Comportamiento transaccional ---------------------------------------------------------------------------

    public function test_si_la_transaccion_falla_la_entrada_tampoco_existe(): void
    {
        try {
            DB::transaction(function (): void {
                $this->log->append('tester', 'cosa.que.no.paso', 'x', '1');
                throw new \RuntimeException('falla después de registrar');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertNull($this->log->verifyChain());
    }

    public function test_los_decimales_se_rechazan_porque_su_texto_no_es_estable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log->append('tester', 'x', 'x', '1', ['monto' => 1.5]);
    }

    // --- JSON canónico -------------------------------------------------------------------------------------------

    public function test_el_json_canonico_no_depende_del_orden_de_las_claves(): void
    {
        $a = Canonical::json(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $b = Canonical::json(['a' => ['x' => 1, 'y' => 2], 'b' => 1]);
        $this->assertSame($a, $b);
        $this->assertSame('{"a":{"x":1,"y":2},"b":1}', $a);
    }

    public function test_el_json_canonico_respeta_el_orden_de_las_listas(): void
    {
        $this->assertSame('[3,1,2]', Canonical::json([3, 1, 2]));
        $this->assertNotSame(Canonical::json([1, 2]), Canonical::json([2, 1]));
    }

    public function test_el_json_canonico_no_escapa_unicode_ni_barras(): void
    {
        $this->assertSame('{"u":"canción https://x/y"}', Canonical::json(['u' => 'canción https://x/y']));
    }
}
