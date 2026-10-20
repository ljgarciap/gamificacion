<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Audit\AuditLog;
use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemService;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/**
 * Concurrencia REAL contra PostgreSQL (procesos con conexiones propias que arrancan a la vez).
 * Cada prueba comprueba, además del resultado de las peticiones, el ESTADO FINAL de la base: es ahí donde se ve una carrera mal resuelta.
 */
final class RedeemConcurrencyTest extends ConcurrencyTestCase
{
    private RedeemFixtures $rf;

    private RedeemService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rf = new RedeemFixtures;
        $this->svc = $this->rf->service();
    }

    /** Un intento de canje como lo haría una petición: devuelve un resumen serializable. */
    private function attempt(string $user, string $key, string $code): array
    {
        try {
            $r = $this->svc->redeem($user, $key, $code);

            return ['status' => $r->replayed ? 'replay' : 'ok', 'id' => $r->redemptionId, 'json' => $r->json, 'user' => $user];
        } catch (RedeemFailure $e) {
            return ['status' => $e->reason, 'user' => $user];
        }
    }

    // --- CA-25 -----------------------------------------------------------------------------------------------------

    public function test_ca25_cincuenta_usuarios_canjean_el_mismo_codigo_a_la_vez_y_solo_uno_lo_logra(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $code = array_search('p50k', $b['prizeOf'], true);

        $results = $this->storm(50, fn (int $i) => $this->attempt("host-a:u$i", 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $code));
        $tally = $this->tally($results, 'status');

        $this->assertSame(['alreadyUsed' => 49, 'ok' => 1], $tally, 'exactamente 1 éxito y 49 "ya usado", sin errores de ningún otro tipo: '.json_encode($tally));

        // Estado final de la base:
        $this->assertSame(1, DB::table('codes')->whereNotNull('redeemed_at')->count());
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(1, DB::table('prize_outbox')->count(), 'una sola acreditación');
        $winner = array_values(array_filter($results, fn ($r) => $r['status'] === 'ok'))[0];
        $this->assertSame($winner['user'], DB::table('codes')->whereNotNull('redeemed_by')->value('redeemed_by'), 'el código quedó a nombre del ganador');
        $this->assertSame($winner['id'], DB::table('redemptions')->value('id'));
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_ca25_se_repite_con_varios_codigos_para_no_depender_de_la_suerte(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 30, p10k: 15, p50k: 5));
        $codes = array_slice($b['codes'], 0, 3);

        foreach ($codes as $round => $code) {
            $results = $this->storm(40, fn (int $i) => $this->attempt("host-a:r{$round}u$i", "clave-r{$round}-".str_pad((string) $i, 6, '0', STR_PAD_LEFT), $code));
            $this->assertSame(['alreadyUsed' => 39, 'ok' => 1], $this->tally($results, 'status'), "ronda $round");
        }
        $this->assertSame(3, DB::table('redemptions')->count());
    }

    // --- CA-26 -----------------------------------------------------------------------------------------------------

    public function test_ca26_cuarenta_reintentos_identicos_a_la_vez_dan_la_misma_respuesta_y_una_sola_acreditacion(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 6, p10k: 3, p50k: 1));
        $code = array_search('p50k', $b['prizeOf'], true);

        // El MISMO usuario con la MISMA clave: es el caso de una app que reintenta (red lenta, doble toque, timeout).
        $results = $this->storm(40, fn (int $i) => $this->attempt('host-a:ana', 'clave-0000001', $code));
        $tally = $this->tally($results, 'status');

        $this->assertSame(['ok' => 1, 'replay' => 39], $tally, json_encode($tally));
        $this->assertCount(1, array_unique(array_column($results, 'json')), 'las 40 respuestas son idénticas byte a byte');
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(1, DB::table('prize_outbox')->count(), 'un solo aviso de acreditación aunque lleguen 40 peticiones');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'code.redeemed')->count());
    }

    public function test_el_mismo_usuario_con_claves_distintas_y_rejectOnly_solo_gana_una(): void
    {
        $b = $this->rf->activeBatch(usedCodeBehavior: 'rejectOnly');
        $results = $this->storm(30, fn (int $i) => $this->attempt('host-a:ana', 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $b['codes'][0]));

        $this->assertSame(['alreadyUsed' => 29, 'ok' => 1], $this->tally($results, 'status'));
        $this->assertSame(1, DB::table('redemptions')->count());
    }

    public function test_el_mismo_usuario_con_claves_distintas_y_showPrevious_ve_siempre_el_mismo_resultado(): void
    {
        $b = $this->rf->activeBatch(usedCodeBehavior: 'showPrevious');
        $results = $this->storm(30, fn (int $i) => $this->attempt('host-a:ana', 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $b['codes'][0]));

        $this->assertSame(['ok' => 1, 'replay' => 29], $this->tally($results, 'status'));
        $this->assertCount(1, array_unique(array_column($results, 'id')), 'todos ven la MISMA redención');
        $this->assertSame(1, DB::table('redemptions')->count());
        $this->assertSame(1, DB::table('prize_outbox')->count() + DB::table('redemptions')->where('prize_id', 'none')->count(), 'a lo sumo una acreditación');
        $played = array_unique(array_map(fn ($r) => json_decode($r['json'], true)['outcome']['prize']['id'], $results));
        $this->assertCount(1, $played, 'el premio mostrado es siempre el mismo');
    }

    public function test_la_misma_clave_con_codigos_distintos_a_la_vez_consume_un_solo_codigo(): void
    {
        $b = $this->rf->activeBatch();
        [$a, $c] = [$b['codes'][0], $b['codes'][1]];

        // 24 peticiones del MISMO usuario y la MISMA clave, la mitad con el código A y la mitad con el B.
        $results = $this->storm(24, fn (int $i) => $this->attempt('host-a:ana', 'clave-0000001', $i % 2 === 0 ? $a : $c));
        $tally = $this->tally($results, 'status');

        $this->assertSame(1, DB::table('redemptions')->count(), 'una sola redención para (usuario, clave): '.json_encode($tally));
        $this->assertSame(1, DB::table('codes')->whereNotNull('redeemed_at')->count(), 'el código que perdió NO quedó consumido');
        $this->assertSame([], array_diff(array_keys($tally), ['ok', 'replay', 'idempotencyKeyReuse']), 'solo éxito, repetición o error de uso: '.json_encode($tally));
        $this->assertSame(1, $tally['ok'] ?? 0);
    }

    // --- Invariantes de stock ------------------------------------------------------------------------------------------------

    public function test_ca20_cien_usuarios_canjean_los_cien_codigos_a_la_vez_y_se_entrega_exactamente_el_stock(): void
    {
        $pool = Fixtures::pool(none: 60, p10k: 30, p50k: 9, p100k: 1);
        $b = $this->rf->activeBatch($pool);
        $codes = $b['codes'];

        // 50 procesos, cada uno canjea 2 códigos distintos: 100 canjes en total, solapados.
        $results = $this->storm(50, function (int $i) use ($codes): array {
            $out = [];
            foreach ([$i, $i + 50] as $n) {
                $out[] = $this->attempt("host-a:u$n", 'clave-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), $codes[$n]);
            }

            return ['status' => 'batch', 'items' => $out];
        });

        $this->assertSame(['batch' => 50], $this->tally($results, 'status'));
        $flat = array_merge(...array_column($results, 'items'));
        $this->assertSame(['ok' => 100], $this->tally($flat, 'status'));

        $delivered = [];
        foreach ($flat as $r) {
            $id = json_decode($r['json'], true)['outcome']['prize']['id'];
            $delivered[$id] = ($delivered[$id] ?? 0) + 1;
        }
        ksort($delivered);
        $this->assertSame(['none' => 60, 'p100k' => 1, 'p10k' => 30, 'p50k' => 9], $delivered, 'bajo concurrencia se entrega EXACTAMENTE el stock definido');

        $this->assertSame(100, DB::table('redemptions')->count());
        $this->assertSame(100, DB::table('codes')->whereNotNull('redeemed_at')->count());
        $this->assertSame(40, DB::table('prize_outbox')->count());
        $this->assertSame(100, DB::table('audit_log')->where('action', 'code.redeemed')->count());
        $this->assertNull((new AuditLog)->verifyChain(), 'la cadena de la bitácora quedó íntegra tras 100 escrituras concurrentes');
    }

    public function test_tormenta_mixta_200_intentos_sobre_20_codigos_dan_exactamente_20_exitos(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 30, p10k: 15, p50k: 5));
        $codes = array_slice($b['codes'], 0, 20);

        $results = $this->storm(50, function (int $i) use ($codes): array {
            $out = [];
            for ($k = 0; $k < 4; $k++) {
                $n = $i * 4 + $k;
                $out[] = $this->attempt("host-a:u$n", 'clave-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT), $codes[$n % 20]);
            }

            return ['status' => 'batch', 'items' => $out];
        });
        $flat = array_merge(...array_column($results, 'items'));
        $tally = $this->tally($flat, 'status');

        $this->assertSame(['alreadyUsed' => 180, 'ok' => 20], $tally, json_encode($tally));
        $this->assertSame(20, DB::table('redemptions')->count());
        $this->assertSame(20, DB::table('codes')->whereNotNull('redeemed_at')->count());
        $this->assertSame(20, DB::table('redemptions')->distinct()->count('code_id'), 'cada código, redimido por exactamente una persona');
    }

    // --- Cierre del lote durante la redención ---------------------------------------------------------------------------

    public function test_cerrar_el_lote_en_medio_de_los_canjes_nunca_deja_un_canje_posterior_al_cierre(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 30, p10k: 15, p50k: 5));
        $codes = $b['codes'];
        $lifecycle = $this->rf->f->lifecycle();
        $batch = $b['batch'];

        $results = $this->storm(40, function (int $i) use ($codes, $lifecycle, $batch): array {
            if ($i === 0) {
                usleep(15_000); // cierra "en medio" de la tormenta
                try {
                    $lifecycle->close($batch, 'tester');

                    return ['status' => 'closed'];
                } catch (\Throwable $e) {
                    return ['status' => 'close-failed: '.$e->getMessage()];
                }
            }

            return $this->attempt("host-a:u$i", 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $codes[$i]);
        });
        $tally = $this->tally($results, 'status');

        $this->assertSame(1, $tally['closed'] ?? 0, json_encode($tally));
        $this->assertSame([], array_diff(array_keys($tally), ['closed', 'ok', 'expired']), 'solo éxito o "vencido", nunca un error inesperado: '.json_encode($tally));

        $closedAt = DB::selectOne("SELECT closed_at FROM batches WHERE id = ?", [$batch])->closed_at;
        $late = DB::selectOne('SELECT count(*) c FROM redemptions WHERE created_at > ?', [$closedAt])->c;
        $this->assertSame(0, (int) $late, 'ninguna redención se registró después del cierre');
        $this->assertSame($tally['ok'] ?? 0, DB::table('redemptions')->count(), 'cada éxito reportado tiene su redención, y no hay redenciones fantasma');
        $this->assertSame($tally['ok'] ?? 0, DB::table('codes')->whereNotNull('redeemed_at')->count());
    }

    // --- Ciclo de vida concurrente ---------------------------------------------------------------------------------------

    public function test_dos_activaciones_simultaneas_del_mismo_lote_solo_una_prospera(): void
    {
        $f = $this->rf->f;
        $campaign = $f->campaign();
        $batch = $f->lifecycle()->createDraft($campaign, 'L', Fixtures::pool(), 'tester');
        $this->rf->generateCodes($batch);
        $f->lifecycle()->commit($batch, 'tester');
        $round = (int) DB::table('batches')->where('id', $batch)->value('beacon_round');
        $f->beacon->publish($round);
        $lifecycle = $f->lifecycle();

        $results = $this->storm(8, function () use ($lifecycle, $batch): array {
            try {
                $lifecycle->activate($batch, 'tester');

                return ['status' => 'ok'];
            } catch (\Throwable $e) {
                return ['status' => class_basename($e)];
            }
        });
        $tally = $this->tally($results, 'status');

        $this->assertSame(1, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame([], array_diff(array_keys($tally), ['ok', 'InvalidTransition']), 'el resto debe recibir un rechazo limpio, no un error: '.json_encode($tally));
        $this->assertSame('active', DB::table('batches')->where('id', $batch)->value('state'));
        $this->assertSame(10, DB::table('codes')->where('batch_id', $batch)->whereNotNull('prize_enc')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'batch.activated')->count());
    }

    public function test_dos_compromisos_simultaneos_del_mismo_lote_solo_uno_prospera(): void
    {
        $f = $this->rf->f;
        $batch = $f->draftWithCodes();
        $lifecycle = $f->lifecycle();

        $results = $this->storm(8, function () use ($lifecycle, $batch): array {
            try {
                $lifecycle->commit($batch, 'tester');

                return ['status' => 'ok'];
            } catch (\Throwable $e) {
                return ['status' => class_basename($e)];
            }
        });
        $tally = $this->tally($results, 'status');

        $this->assertSame(1, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame([], array_diff(array_keys($tally), ['ok', 'InvalidTransition']), json_encode($tally));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'batch.committed')->count(), 'un solo compromiso publicado');
    }

    // --- Límite de intentos con la caché REAL ---------------------------------------------------------------------------------

    public function test_los_intentos_fallidos_se_cuentan_aunque_la_transaccion_se_revierta(): void
    {
        // La caché de base de datos comparte conexión con la transacción de canje: si el fallo se anotara DENTRO de ella, se perdería al
        // revertirse. Aquí se usa la caché real (no `array`) y se prueba que quien intenta códigos ajenos SÍ queda bloqueado.
        config(['cache.default' => 'database', 'gamification.redeem.user.max_failures' => 3, 'gamification.redeem.user.lockout_base_seconds' => 60]);
        $b = $this->rf->activeBatch();
        foreach ([0, 1, 2] as $i) {
            $this->svc->redeem("host-a:victima$i", "clave-v000000$i", $b['codes'][$i]);
        }

        // El atacante prueba 3 códigos que existen pero ya son de otros: cada uno es "alreadyUsed" (transacción revertida).
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(RedeemFailure::ALREADY_USED, $this->attempt('host-a:atacante', "clave-a000000$i", $b['codes'][$i])['status']);
        }

        $this->assertSame(RedeemFailure::RATE_LIMITED, $this->attempt('host-a:atacante', 'clave-a0000099', $b['codes'][5])['status'], 'el bloqueo se aplicó: los fallos NO se perdieron con la transacción');
    }

    // --- Entrega de avisos desde varios procesos --------------------------------------------------------------------------------

    public function test_cuatro_despachadores_a_la_vez_entregan_cada_aviso_exactamente_una_vez(): void
    {
        $b = $this->rf->activeBatch(Fixtures::pool(none: 0, p10k: 60, p50k: 0));
        foreach ($b['codes'] as $i => $code) {
            $this->svc->redeem("host-a:u$i", 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $code);
        }
        $this->assertSame(60, DB::table('prize_outbox')->where('status', 'pending')->count());

        $file = sys_get_temp_dir().'/outbox-'.bin2hex(random_bytes(5)).'.log';
        try {
            $results = $this->storm(4, function () use ($file): array {
                $dispatcher = new \App\Domain\Fulfillment\OutboxDispatcher(new \Tests\Support\FileFulfillment($file));
                $sent = 0;
                for ($round = 0; $round < 20; $round++) {
                    $r = $dispatcher->dispatchDue(5);
                    $sent += $r['sent'];
                    if ($r['sent'] === 0) {
                        break;
                    }
                }

                return ['status' => 'done', 'sent' => $sent];
            });
            $this->assertSame(['done' => 4], $this->tally($results, 'status'));

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $this->assertCount(60, $lines, 'se entregaron 60 avisos en total');
            $this->assertCount(60, array_unique($lines), 'ninguno se entregó dos veces (SKIP LOCKED reparte el trabajo)');
            $this->assertSame(60, array_sum(array_column($results, 'sent')));
            $this->assertSame(60, DB::table('prize_outbox')->where('status', 'sent')->where('attempts', 1)->count());
        } finally {
            @unlink($file);
        }
    }
}
