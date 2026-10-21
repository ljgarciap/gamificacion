<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\SpinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ConcurrencyTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/** CA-39: bajo concurrencia real, K giros hijos consumen exactamente K casillas distintas, consecutivas, y nunca más de R. */
final class SpinConcurrencyTest extends ConcurrencyTestCase
{
    private RedeemFixtures $rf;

    private SpinService $spins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rf = new RedeemFixtures;
        $this->spins = $this->rf->spinService();
    }

    /** @return array<string, mixed> */
    private function attempt(string $user, string $key, string $parent): array
    {
        try {
            $r = $this->spins->spin($user, $key, $parent);

            return ['status' => $r->replayed ? 'replay' : 'ok', 'id' => $r->redemptionId, 'user' => $user];
        } catch (RedeemFailure $e) {
            return ['status' => $e->reason, 'user' => $user];
        }
    }

    /** Un padre que concede `$granted` giros (se reclama un código libre y se registra como lo haría el canje). */
    private function parent(string $batch, string $user, int $granted): string
    {
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$batch])->id;
        DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $code]);
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, ?, ?, 'extra', '{}'::json, ?)",
            [$id, $code, $batch, $user, 'padre-'.Str::random(10), $granted],
        );

        return $id;
    }

    public function test_ca39_seis_giros_simultaneos_consumen_seis_casillas_distintas_y_consecutivas(): void
    {
        // 14 posiciones: 6 códigos + 8 de reserva (la reserva sobra; el pool no tiene «extra», así que no pide nada).
        $b = $this->rf->activeReserveBatch(Fixtures::pool(none: 10, p10k: 4, p50k: 0), 8);
        $parents = [];
        for ($i = 0; $i < 6; $i++) {
            $parents[$i] = $this->parent($b['batch'], "host-a:u$i", 1);
        }

        // Por usuario: dos peticiones con la MISMA clave (una gana, la otra repite) y una tercera con otra clave (sin giros pendientes).
        $results = $this->storm(18, function (int $i) use ($parents) {
            $u = intdiv($i, 3);
            $key = $i % 3 === 2 ? "giro-otra-$u" : "giro-clave-$u";

            return $this->attempt("host-a:u$u", $key, $parents[$u]);
        });
        // Por usuario gana exactamente UNA petición (la de cualquiera de las dos claves); el resto repite o no tiene giros. Nada más.
        foreach (range(0, 5) as $u) {
            $mine = array_values(array_filter($results, fn ($r) => $r['user'] === "host-a:u$u"));
            $this->assertSame(1, count(array_filter($mine, fn ($r) => $r['status'] === 'ok')), "usuario $u: ".json_encode($mine));
            foreach ($mine as $r) {
                $this->assertContains($r['status'], ['ok', 'replay', 'noSpinsLeft'], json_encode($r));
            }
        }

        // Estado final: exactamente 6 casillas, las 0..5, ni una más, y el cursor al día.
        $slots = DB::table('reserve_slots')->where('batch_id', $b['batch'])->whereNotNull('consumed_at')->orderBy('idx')->pluck('idx')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([0, 1, 2, 3, 4, 5], $slots);
        $this->assertSame(6, (int) DB::table('reserve_cursors')->where('batch_id', $b['batch'])->value('consumed'));
        $children = DB::table('redemptions')->where('batch_id', $b['batch'])->whereNotNull('reserve_idx')->orderBy('created_at')->pluck('reserve_idx')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([0, 1, 2, 3, 4, 5], $children, 'el orden de las redenciones hijas es el de las casillas');
        $this->assertSame(6, DB::table('audit_log')->where('action', 'spin.redeemed')->count());
    }

    public function test_dos_peticiones_con_la_misma_clave_dan_un_giro_y_una_repeticion_nunca_un_sin_giros(): void
    {
        $b = $this->rf->activeReserveBatch(Fixtures::pool(none: 10, p10k: 4, p50k: 0), 8);
        $parents = [];
        for ($i = 0; $i < 6; $i++) {
            $parents[$i] = $this->parent($b['batch'], "host-a:u$i", 1);
        }

        $results = $this->storm(12, fn (int $i) => $this->attempt('host-a:u'.intdiv($i, 2), 'giro-clave-'.intdiv($i, 2), $parents[intdiv($i, 2)]));
        $tally = $this->tally($results, 'status');
        $this->assertSame(['ok' => 6, 'replay' => 6], $tally, json_encode($tally));
        $this->assertSame(6, DB::table('reserve_slots')->where('batch_id', $b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_nunca_se_consumen_mas_casillas_que_la_reserva(): void
    {
        // 2 casillas de reserva y un padre que (por exceso simulado) concede 6 giros: 6 procesos piden a la vez.
        $b = $this->rf->activeReserveBatch(Fixtures::pool(none: 8, p10k: 2, p50k: 0), 2);
        $parent = $this->parent($b['batch'], 'host-a:ana', 6);

        $results = $this->storm(6, fn (int $i) => $this->attempt('host-a:ana', "giro-clave-$i", $parent));
        $tally = $this->tally($results, 'status');
        $this->assertSame(['ok' => 2, 'spinReserveExhausted' => 4], $tally, json_encode($tally));
        $this->assertSame(2, DB::table('reserve_slots')->where('batch_id', $b['batch'])->whereNotNull('consumed_at')->count());
        $this->assertSame(2, (int) DB::table('reserve_cursors')->where('batch_id', $b['batch'])->value('consumed'));
    }
}
