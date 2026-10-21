<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Commitment;
use App\Engine\Drbg;
use App\Engine\Pool;
use App\Engine\PrizeAssigner;
use App\Engine\Shuffler;
use PHPUnit\Framework\TestCase;

/**
 * CA-24b: el motor PHP y el verificador Python (escritos por separado) producen los mismos bytes.
 * Si coinciden, la especificación es inequívoca; si no, una de las dos está mal o la spec es ambigua.
 *
 * Este test NO se omite en silencio si faltan los vectores: eso dejaría el motor sin contraste y nadie se enteraría.
 */
final class VectorsTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $v;

    public static function setUpBeforeClass(): void
    {
        $dir = getenv('VERIFIER_DIR') ?: '/verifier';
        $path = $dir.'/vectors.json';
        if (! is_file($path)) {
            self::fail("No se encuentran los vectores del verificador en {$path}. Defina VERIFIER_DIR o monte ./verifier.");
        }
        self::$v = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_la_version_de_los_vectores_es_la_esperada(): void
    {
        $this->assertSame(1, self::$v['version']);
    }

    public function test_drbg_produce_los_mismos_bytes_cruzando_varios_bloques(): void
    {
        $d = new Drbg(hex2bin(self::$v['drbg']['key']));
        $this->assertSame(self::$v['drbg']['first_100_bytes'], bin2hex($d->nextBytes(100)));
    }

    public function test_drbg_da_lo_mismo_leido_de_a_pedazos_que_de_una_vez(): void
    {
        $key = hex2bin(self::$v['drbg']['key']);
        $d = new Drbg($key);
        $parts = '';
        foreach ([1, 7, 31, 1, 40, 20] as $n) {
            $parts .= $d->nextBytes($n);
        }
        $this->assertSame(self::$v['drbg']['first_100_bytes'], bin2hex($parts));
    }

    public function test_muestreo_uniforme_coincide_incluidos_los_descartes(): void
    {
        foreach (self::$v['uniform'] as $case) {
            $d = new Drbg(hex2bin($case['key']));
            $got = [];
            for ($i = 0; $i < count($case['draws']); $i++) {
                $got[] = $d->uniform($case['n']);
            }
            $this->assertSame($case['draws'], $got, "n={$case['n']}");
        }
    }

    public function test_baraja_coincide(): void
    {
        $s = self::$v['shuffle'];
        $this->assertSame($s['result'], Shuffler::permute($s['items'], new Drbg(hex2bin($s['key']))));
    }

    public function test_pool_hash_y_expansion_coinciden_sin_importar_el_orden_de_entrada(): void
    {
        $p = self::$v['pool'];
        foreach (['entries', 'entries_unsorted'] as $key) {
            $pool = Pool::fromEntries($p[$key]);
            $this->assertSame($p['hash'], bin2hex($pool->hash()), $key);
            $this->assertSame($p['expanded'], $pool->expanded(), $key);
        }
    }

    public function test_mezcla_de_semilla_coincide(): void
    {
        $m = self::$v['mix'];
        $this->assertSame(
            $m['result'],
            bin2hex(Commitment::mixSeed(hex2bin($m['seed']), hex2bin($m['beacon']), $m['batch_id'])),
        );
    }

    public function test_resumen_de_huellas_coincide(): void
    {
        $c = self::$v['codes_digest'];
        $this->assertSame($c['result'], bin2hex(Commitment::codesDigest(array_map('hex2bin', $c['hashes']))));
    }

    public function test_compromiso_coincide(): void
    {
        $c = self::$v['commitment'];
        $pool = Pool::fromEntries(self::$v['pool']['entries']);
        $got = Commitment::commit(
            $c['batch_id'], $pool, hex2bin($c['codes_digest']), hex2bin($c['chain_hash']), $c['beacon_round'], hex2bin($c['seed']),
        );
        $this->assertSame($c['result'], bin2hex($got));
    }

    public function test_asignacion_pequena_coincide(): void
    {
        $a = self::$v['assign_small'];
        $pool = Pool::fromEntries(self::$v['pool']['entries']);
        $this->assertSame($a['result'], PrizeAssigner::assign($pool, hex2bin($a['seed']), hex2bin($a['beacon']), $a['batch_id']));
    }

    public function test_asignacion_grande_de_1000_codigos_coincide(): void
    {
        $a = self::$v['assign_big'];
        $pool = Pool::fromEntries($a['entries']);
        $out = PrizeAssigner::assign($pool, hex2bin($a['seed']), hex2bin($a['beacon']), $a['batch_id']);

        $this->assertCount($a['n'], $out);
        $this->assertSame($a['first_20'], array_slice($out, 0, 20));
        $this->assertSame($a['sha256_of_result'], hash('sha256', implode("\n", $out)));
    }

    /** CA-38: con R = 0 nada de lo ya publicado cambia. La sección v1 de los vectores queda fijada por huella. */
    public function test_la_seccion_v1_de_los_vectores_no_cambia_ni_un_byte(): void
    {
        $v1 = self::$v;
        unset($v1['v2']);
        ksort($v1);
        $this->assertSame(
            '18c7d27fea668447f8da5a5654c650a20057b755fabc4b86aa853fd10bb33d2c',
            hash('sha256', json_encode($v1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'cambió algo del algoritmo v1: los lotes ya comprometidos dejarían de verificar',
        );
    }

    public function test_v2_mezcla_de_semilla_coincide(): void
    {
        $m = self::$v['v2']['mix'];
        $this->assertSame(
            $m['result'],
            bin2hex(Commitment::mixSeedV2(hex2bin($m['seed']), hex2bin($m['beacon']), $m['batch_id'])),
        );
    }

    public function test_v2_compromiso_coincide(): void
    {
        $c = self::$v['v2']['commitment'];
        $got = Commitment::commitV2(
            $c['batch_id'], Pool::fromEntries($c['entries']), $c['reserve'], hex2bin($c['codes_digest']),
            hex2bin($c['chain_hash']), $c['beacon_round'], hex2bin($c['seed']),
        );
        $this->assertSame($c['result'], bin2hex($got));
    }

    public function test_v2_asignacion_pequena_coincide(): void
    {
        $a = self::$v['v2']['assign_small'];
        $deck = PrizeAssigner::assignDeck(Pool::fromEntries($a['entries']), $a['reserve'], hex2bin($a['seed']), hex2bin($a['beacon']), $a['batch_id']);
        $this->assertSame($a['codes'], $deck['codes']);
        $this->assertSame($a['reserve_slots'], $deck['reserve']);
    }

    public function test_v2_asignacion_grande_coincide(): void
    {
        $a = self::$v['v2']['assign_big'];
        $deck = PrizeAssigner::assignDeck(Pool::fromEntries($a['entries']), $a['reserve'], hex2bin($a['seed']), hex2bin($a['beacon']), $a['batch_id']);
        $this->assertCount($a['n'], $deck['codes']);
        $this->assertCount($a['reserve'], $deck['reserve']);
        $this->assertSame($a['first_reserve_10'], array_slice($deck['reserve'], 0, 10));
        $this->assertSame($a['sha256_of_codes'], hash('sha256', implode("\n", $deck['codes'])));
        $this->assertSame($a['sha256_of_reserve'], hash('sha256', implode("\n", $deck['reserve'])));
    }
}
