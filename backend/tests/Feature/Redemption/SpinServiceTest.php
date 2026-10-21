<?php

namespace Tests\Feature\Redemption;

use App\Domain\Codes\CodeFormat;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemService;
use App\Domain\Redemption\SpinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DbTestCase;
use Tests\Support\RedeemFixtures;

/** Giros hijos de la reserva (SPEC-003 P6c). */
final class SpinServiceTest extends DbTestCase
{
    private RedeemFixtures $rf;

    private RedeemService $redeem;

    private SpinService $spins;

    /** @var array{batch: string, campaign: string, codes: list<string>, prizeOf: array<string, string>} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rf = new RedeemFixtures;
        $this->redeem = $this->rf->service();
        $this->spins = $this->rf->spinService();
        // 6 none + 3 p10k + 2 extra = 11 posiciones: 8 códigos + 3 de reserva (los 2 «extra» piden a lo sumo 2).
        $this->b = $this->rf->activeReserveBatch(
            [['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'extra', 'quantity' => 2]], 3,
        );
    }

    private function failure(callable $run): RedeemFailure
    {
        try {
            $run();
        } catch (RedeemFailure $e) {
            return $e;
        }
        $this->fail('Debía fallar con RedeemFailure.');
    }

    /** Canjea un código con el premio dado (si lo hay entre los códigos) y devuelve la redención padre. */
    private function parentWith(string $user, string $prize = 'extra'): ?string
    {
        $code = array_search($prize, $this->b['prizeOf'], true);

        return $code === false ? null : $this->redeem->redeem($user, 'canje-'.Str::random(10), $code)->redemptionId;
    }

    /** Un padre manual que concede `$granted` giros (la reserva solo cubre los que pide el pool: esto simula un exceso). */
    private function manualParent(string $user, int $granted): string
    {
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$this->b['batch']])->id;
        DB::update('UPDATE codes SET redeemed_at = now(), redeemed_by = ? WHERE id = ?', [$user, $code]);
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, ?, ?, 'extra', '{}'::json, ?)",
            [$id, $code, $this->b['batch'], $user, 'manual-'.Str::random(8), $granted],
        );

        return $id;
    }

    public function test_un_giro_consume_la_primera_casilla_y_entrega_su_premio(): void
    {
        $parent = $this->parentWith('host-a:ana') ?? $this->manualParent('host-a:ana', 1);
        $r = $this->spins->spin('host-a:ana', 'giro-0000001', $parent);

        $slot = DB::selectOne("SELECT idx, consumed_by, encode(prize_enc,'hex') AS p FROM reserve_slots WHERE batch_id = ? AND consumed_at IS NOT NULL", [$this->b['batch']]);
        $this->assertSame(0, (int) $slot->idx);
        $this->assertSame('host-a:ana', $slot->consumed_by);
        $expected = $this->rf->f->vault->decryptReservePrize($this->b['batch'], 8, hex2bin($slot->p));

        $body = $r->toArray();
        $this->assertFalse($r->replayed);
        $this->assertSame($expected, $body['outcome']['prize']['id']);
        $this->assertSame($parent, $body['parentRedemptionId']);
        $this->assertSame(1, $body['spinNo']);
        $this->assertSame(1, (int) DB::table('reserve_cursors')->where('batch_id', $this->b['batch'])->value('consumed'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'spin.redeemed')->count());
        $this->assertSame($expected !== 'none' ? 1 : 0, DB::table('prize_outbox')->where('redemption_id', $r->redemptionId)->count());
    }

    public function test_reintentar_con_la_misma_clave_devuelve_lo_mismo_sin_consumir_otra_casilla(): void
    {
        $parent = $this->parentWith('host-a:ana') ?? $this->manualParent('host-a:ana', 2);
        $first = $this->spins->spin('host-a:ana', 'giro-0000001', $parent);
        $again = $this->spins->spin('host-a:ana', 'giro-0000001', $parent);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->json, $again->json);
        $this->assertSame(1, DB::table('reserve_slots')->where('batch_id', $this->b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_sin_giros_pendientes_no_hay_mas_casillas_consumidas(): void
    {
        $parent = $this->manualParent('host-a:ana', 1);
        $this->spins->spin('host-a:ana', 'giro-0000001', $parent);
        $e = $this->failure(fn () => $this->spins->spin('host-a:ana', 'giro-0000002', $parent));
        $this->assertSame(RedeemFailure::NO_SPINS_LEFT, $e->reason);
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame(1, DB::table('reserve_slots')->where('batch_id', $this->b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_un_padre_de_otro_usuario_inexistente_o_mal_formado_responden_igual(): void
    {
        $parent = $this->manualParent('host-a:ana', 1);
        foreach ([[$parent, 'host-a:beto'], [(string) Str::uuid(), 'host-a:ana'], ['no-es-un-uuid', 'host-a:ana']] as $i => [$id, $user]) {
            $e = $this->failure(fn () => $this->spins->spin($user, 'giro-000000'.$i, $id));
            $this->assertSame(RedeemFailure::SPIN_NOT_FOUND, $e->reason);
            $this->assertSame(404, $e->httpStatus());
        }
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $this->b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_la_reserva_agotada_da_un_error_explicito_y_nunca_un_premio_inventado(): void
    {
        $parent = $this->manualParent('host-a:ana', 5); // concede más de lo que la reserva (3) puede cubrir
        for ($i = 1; $i <= 3; $i++) {
            $this->spins->spin('host-a:ana', 'giro-000000'.$i, $parent);
        }
        $e = $this->failure(fn () => $this->spins->spin('host-a:ana', 'giro-0000009', $parent));
        $this->assertSame(RedeemFailure::RESERVE_EXHAUSTED, $e->reason);
        $this->assertSame(3, DB::table('redemptions')->where('batch_id', $this->b['batch'])->whereNotNull('reserve_idx')->count());
    }

    public function test_al_cerrar_el_lote_los_giros_pendientes_caducan(): void
    {
        $parent = $this->manualParent('host-a:ana', 1);
        $this->rf->f->lifecycle()->close($this->b['batch'], 'tester');
        $e = $this->failure(fn () => $this->spins->spin('host-a:ana', 'giro-0000001', $parent));
        $this->assertSame(RedeemFailure::EXPIRED, $e->reason);
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $this->b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_vencida_la_ventana_de_redencion_los_giros_caducan(): void
    {
        // La ventana solo la comprueba la aplicación (la base solo mira el estado del lote).
        $b = $this->rf->activeReserveBatch([['prize_id' => 'none', 'quantity' => 8], ['prize_id' => 'p10k', 'quantity' => 2]], 2, 1, 'rejectOnly', now()->addSeconds(2));
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL LIMIT 1', [$b['batch']])->id;
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'host-a:ana' WHERE id = ?", [$code]);
        $parent = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, 'host-a:ana', 'padre-ventana1', 'extra', '{}'::json, 1)",
            [$parent, $code, $b['batch']],
        );
        sleep(3);
        $e = $this->failure(fn () => $this->spins->spin('host-a:ana', 'giro-0000001', $parent));
        $this->assertSame(RedeemFailure::EXPIRED, $e->reason);
        $this->assertSame(0, DB::table('reserve_slots')->where('batch_id', $b['batch'])->whereNotNull('consumed_at')->count());
    }

    public function test_volver_a_ver_un_resultado_ya_jugado_trae_los_giros_que_aun_quedan(): void
    {
        // Campaña showPrevious; todas las posiciones son del premio «extra» (1 giro cada una), así cada giro hijo concede otro.
        $b = $this->rf->activeReserveBatch([['prize_id' => 'extra', 'quantity' => 7]], 2, 1, 'showPrevious', null, asMoney: true);
        $code = $b['codes'][0];
        $first = $this->redeem->redeem('host-a:ana', 'canje-0000001', $code);
        $this->assertArrayNotHasKey('pendingSpins', $first->toArray(), 'la respuesta guardada del canje no cambia');

        // Sin haber girado: el padre tiene su giro pendiente.
        $again = $this->redeem->redeem('host-a:ana', 'canje-0000002', $code)->toArray();
        $this->assertTrue($again['alreadyPlayed']);
        $this->assertSame([['redemptionId' => $first->redemptionId, 'remaining' => 1, 'played' => 0]], $again['pendingSpins']);

        // Tras girar, el giro del padre ya se jugó y queda el que concedió el hijo.
        $child = $this->spins->spin('host-a:ana', $first->redemptionId.':1', $first->redemptionId);
        $this->assertSame([['redemptionId' => $child->redemptionId, 'remaining' => 1, 'played' => 0]],
            $this->redeem->redeem('host-a:ana', 'canje-0000003', $code)->toArray()['pendingSpins']);

        // Jugado el del hijo queda el que concede el nieto (todos los premios de este pool son «extra»); el padre y el hijo ya no deben nada.
        $grandchild = $this->spins->spin('host-a:ana', $child->redemptionId.':1', $child->redemptionId);
        $this->assertSame([['redemptionId' => $grandchild->redemptionId, 'remaining' => 1, 'played' => 0]],
            $this->redeem->redeem('host-a:ana', 'canje-0000004', $code)->toArray()['pendingSpins']);
    }

    public function test_una_clave_no_se_comparte_entre_un_canje_de_codigo_y_un_giro(): void
    {
        $parent = $this->manualParent('host-a:ana', 1);
        $this->spins->spin('host-a:ana', 'giro-0000001', $parent);
        $free = fn () => collect($this->b['codes'])->first(fn (string $c) => DB::table('codes')
            ->where('code_hash', DB::raw("decode('".bin2hex((new CodeHasher)->hash(CodeFormat::normalize($c)))."', 'hex')"))
            ->whereNull('redeemed_at')->exists());
        // La misma clave con un canje de código (el giro la usó): error de uso, no 500.
        $e = $this->failure(fn () => $this->redeem->redeem('host-a:ana', 'giro-0000001', $free()));
        $this->assertSame(RedeemFailure::KEY_REUSE, $e->reason);
        // Y la clave de un canje de código con un giro tampoco es un reintento.
        $this->redeem->redeem('host-a:ana', 'canje-0000001', $free());
        $e = $this->failure(fn () => $this->spins->spin('host-a:ana', 'canje-0000001', $parent));
        $this->assertSame(RedeemFailure::KEY_REUSE, $e->reason);
    }

    public function test_todo_el_recorrido_agota_el_pool_sin_fallos_y_el_lote_se_revela(): void
    {
        // Todos los códigos se canjean; cada «extra» (en códigos o en la reserva) gira. Con la reserva bien dimensionada ningún giro falla.
        $users = 0;
        $spins = 0;
        foreach ($this->b['codes'] as $code) {
            $user = 'host-a:u'.(++$users);
            $r = $this->redeem->redeem($user, 'canje-'.str_pad((string) $users, 6, '0', STR_PAD_LEFT), $code);
            $pending = [[$r->redemptionId, $r->toArray()['outcome']['extraSpins']]];
            while ($pending !== []) {
                [$parent, $granted] = array_pop($pending);
                for ($n = 0; $n < $granted; $n++) {
                    $child = $this->spins->spin($user, 'giro-'.(++$spins).str_repeat('x', 6), $parent);
                    $pending[] = [$child->redemptionId, $child->toArray()['outcome']['extraSpins']];
                }
            }
        }
        $this->assertLessThanOrEqual(3, $spins);
        $this->assertSame($spins, (int) DB::table('reserve_cursors')->where('batch_id', $this->b['batch'])->value('consumed'));
        $this->assertSame($spins, DB::table('redemptions')->where('batch_id', $this->b['batch'])->whereNotNull('reserve_idx')->count());

        $this->rf->f->lifecycle()->close($this->b['batch'], 'tester');
        $this->rf->f->lifecycle()->reveal($this->b['batch'], 'tester');
        $this->assertSame('revealed', DB::table('batches')->where('id', $this->b['batch'])->value('state'));
    }
}
