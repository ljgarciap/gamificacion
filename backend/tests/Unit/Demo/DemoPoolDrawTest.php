<?php

namespace Tests\Unit\Demo;

use App\Domain\Admin\SecretBox;
use App\Domain\Demo\DemoPoolDraw;
use App\Domain\Demo\DemoPoolExhausted;
use App\Domain\Demo\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

/** El sorteo de la muestra: posición elegida con el CSPRNG, sin repetir, todo o nada y uniforme (SPEC-006 CA-6, T-D5). */
final class DemoPoolDrawTest extends DbTestCase
{
    /** @return list<string> huellas hex de las filas, en orden de id */
    private function pool(int $n): array
    {
        $f = new Fixtures;
        $campaign = $f->campaign();
        DB::update('UPDATE campaigns SET is_demo = true WHERE id = ?', [$campaign]);
        $batch = $f->draft($campaign);
        $box = new SecretBox;
        $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            $hash = bin2hex(random_bytes(8));
            $hashes[] = $hash;
            DB::insert("INSERT INTO demo_pool (batch_id, code_enc, code_hash, game, description) VALUES (?, decode(?, 'hex'), decode(?, 'hex'), 'roulette', 'x')",
                [$batch, bin2hex($box->seal("CODIGO-$i", DemoSeeder::sealContext($hash))), $hash]);
        }

        return $hashes;
    }

    private function order(): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::insert("INSERT INTO demo_orders (id, user_id, items, total, credited, codes_granted, idempotency_key, request_hash) VALUES (?, 'u', '[]'::jsonb, 1000, 0, 0, ?, 'h')", [$id, bin2hex(random_bytes(6))]);

        return $id;
    }

    public function test_exige_una_transaccion(): void
    {
        $this->pool(2);
        DB::rollBack(); // DbTestCase envuelve cada prueba en una; se la quita para comprobar la guarda
        try {
            $this->expectException(\LogicException::class);
            (new DemoPoolDraw)->draw('u', (string) \Illuminate\Support\Str::uuid(), 1);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_la_posicion_sale_de_la_fuente_aleatoria_y_se_recorre_sin_repetir(): void
    {
        $this->pool(5); // CODIGO-0..4 en orden de id
        $asked = [];
        $draw = new DemoPoolDraw(randomInt: function (int $min, int $max) use (&$asked): int {
            $asked[] = [$min, $max];

            return $max; // siempre la última disponible
        });
        $got = $draw->draw('u', $this->order(), 3);
        $this->assertSame(['CODIGO-4', 'CODIGO-3', 'CODIGO-2'], array_column($got, 'code'));
        $this->assertSame([[0, 4], [0, 3], [0, 2]], $asked, 'el rango se achica con cada código ya asignado');

        $first = new DemoPoolDraw(randomInt: fn (int $min, int $max): int => $min);
        $this->assertSame(['CODIGO-0'], array_column($first->draw('u', $this->order(), 1), 'code'));
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n, '5 − 3 − 1');
    }

    public function test_si_no_alcanzan_los_codigos_no_asigna_ninguno(): void
    {
        $this->pool(3);
        DB::table('demo_pool')->limit(1)->get();
        try {
            DB::transaction(fn () => (new DemoPoolDraw)->draw('u', $this->order(), 4));
            $this->fail('debía agotarse');
        } catch (DemoPoolExhausted $e) {
            $this->assertStringContainsString('Quedan 3', $e->getMessage());
        }
        $this->assertSame(3, (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n);
        $this->assertSame([], (new DemoPoolDraw)->draw('u', $this->order(), 0));
    }

    public function test_cada_codigo_sorteado_es_el_de_su_fila_y_queda_ligado_a_la_orden_y_a_la_persona(): void
    {
        $hashes = $this->pool(4);
        $order = $this->order();
        $got = (new DemoPoolDraw)->draw('persona-1', $order, 4);
        $this->assertCount(4, array_unique(array_column($got, 'code')));
        $this->assertEqualsCanonicalizing(['CODIGO-0', 'CODIGO-1', 'CODIGO-2', 'CODIGO-3'], array_column($got, 'code'));
        $rows = DB::select("SELECT assigned_to, order_id, state FROM demo_pool");
        foreach ($rows as $r) {
            $this->assertSame(['persona-1', $order, 'assigned'], [$r->assigned_to, $r->order_id, $r->state]);
        }
        $this->assertCount(4, $hashes);
    }

    public function test_la_distribucion_es_uniforme_chi_cuadrado(): void
    {
        $this->pool(10);
        $ids = array_column(DB::select('SELECT id FROM demo_pool ORDER BY id'), 'id');
        $counts = array_fill_keys($ids, 0);
        $n = 3000;
        $draw = new DemoPoolDraw; // el CSPRNG real
        for ($i = 0; $i < $n; $i++) {
            DB::beginTransaction();
            $draw->draw('u', $this->order(), 1);
            $counts[DB::selectOne("SELECT id FROM demo_pool WHERE state = 'assigned'")->id]++;
            DB::rollBack(); // cada sorteo parte del pool completo
        }
        $expected = $n / 10;
        $chi = array_sum(array_map(fn (int $c): float => ($c - $expected) ** 2 / $expected, $counts));
        // 9 grados de libertad; 27,88 es el valor crítico a p = 0,001: un sorteo uniforme falla esta prueba una de cada mil veces.
        $this->assertLessThan(27.88, $chi, 'chi² = '.round($chi, 2).' sobre '.json_encode(array_values($counts)));
    }
}
