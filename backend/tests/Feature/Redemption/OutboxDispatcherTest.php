<?php

namespace Tests\Feature\Redemption;

use App\Domain\Fulfillment\OutboxDispatcher;
use App\Domain\Fulfillment\PrizeFulfillment;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RecordingFulfillment;
use Tests\Support\RedeemFixtures;

final class OutboxDispatcherTest extends DbTestCase
{
    private RecordingFulfillment $host;

    private OutboxDispatcher $dispatcher;

    /** @var list<string> ids de redención que generaron aviso */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['gamification.outbox' => ['max_attempts' => 4, 'backoff_base_seconds' => 30, 'backoff_max_seconds' => 100]]);
        $this->host = new RecordingFulfillment;
        $this->dispatcher = new OutboxDispatcher($this->host);

        // 6 premios en dinero redimidos por 6 usuarios => 6 avisos pendientes.
        $rf = new RedeemFixtures;
        $b = $rf->activeBatch(Fixtures::pool(none: 4, p10k: 6, p50k: 0));
        $service = $rf->service();
        foreach (array_keys(array_filter($b['prizeOf'], fn ($p) => $p === 'p10k')) as $i => $code) {
            $this->ids[] = $service->redeem('host-a:u'.$i, 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $code)->redemptionId;
        }
    }

    private function row(string $id): object
    {
        return DB::selectOne('SELECT *, next_attempt_at > now() AS in_future FROM prize_outbox WHERE redemption_id = ?', [$id]);
    }

    /** Adelanta el reloj de la fila: las pruebas corren en una transacción donde now() no avanza. */
    private function makeDue(string $id): void
    {
        DB::update("UPDATE prize_outbox SET next_attempt_at = now() - interval '1 second' WHERE redemption_id = ?", [$id]);
    }

    private function backoffSeconds(string $id): int
    {
        return (int) round((float) DB::selectOne('SELECT extract(epoch FROM next_attempt_at - now()) AS s FROM prize_outbox WHERE redemption_id = ?', [$id])->s);
    }

    public function test_entrega_todo_lo_pendiente_y_lo_marca_como_enviado(): void
    {
        $r = $this->dispatcher->dispatchDue();

        $this->assertSame(['sent' => 6, 'retry' => 0, 'failed' => 0], $r);
        $this->assertCount(6, $this->host->delivered);
        foreach ($this->ids as $id) {
            $row = $this->row($id);
            $this->assertSame('sent', $row->status);
            $this->assertSame(1, (int) $row->attempts);
            $this->assertNotNull($row->sent_at);
            $this->assertNull($row->last_error);
        }
    }

    public function test_el_aviso_lleva_los_datos_necesarios_y_el_id_de_redencion_como_clave_de_idempotencia(): void
    {
        $this->dispatcher->dispatchDue();
        foreach ($this->host->delivered as $p) {
            $this->assertContains($p['redemption_id'], $this->ids);
            $this->assertSame(10000, $p['prize']['amount']);
            $this->assertSame('money', $p['prize']['type']);
            $this->assertStringStartsWith('host-a:', $p['user_id']);
        }
        $this->assertCount(6, array_unique(array_column($this->host->delivered, 'redemption_id')));
    }

    public function test_lo_ya_enviado_no_se_vuelve_a_enviar(): void
    {
        $this->dispatcher->dispatchDue();
        $this->host->delivered = [];
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0], $this->dispatcher->dispatchDue());
        $this->assertSame([], $this->host->delivered);
    }

    public function test_respeta_el_limite_por_ejecucion(): void
    {
        $this->assertSame(2, $this->dispatcher->dispatchDue(2)['sent']);
        $this->assertSame(2, DB::table('prize_outbox')->where('status', 'sent')->count());
        $this->assertSame(4, $this->dispatcher->dispatchDue(100)['sent']);
    }

    public function test_un_fallo_se_reintenta_con_espera_creciente_y_tope(): void
    {
        $id = $this->ids[0];
        $this->host->alwaysFail = true;

        $esperas = [];
        for ($intento = 1; $intento <= 3; $intento++) {
            $this->makeDue($id);
            $r = $this->dispatcher->dispatchDue(1);
            $this->assertSame(1, $r['retry'], "intento $intento");
            $row = $this->row($id);
            $this->assertSame('pending', $row->status);
            $this->assertSame($intento, (int) $row->attempts);
            $this->assertSame('el host no respondió', $row->last_error);
            $this->assertTrue($row->in_future, 'queda programado para más tarde');
            $esperas[] = $this->backoffSeconds($id);
        }
        $this->assertSame([30, 60, 100], $esperas, 'base 30 s, doble cada vez, con tope de 100 s');
    }

    public function test_un_aviso_que_aun_no_toca_no_se_procesa(): void
    {
        $id = $this->ids[0];
        $this->host->failTimes[$id] = 1;
        $this->dispatcher->dispatchDue(1); // falla y se programa a futuro
        $this->host->delivered = [];

        // Ya no toca (en el futuro): se procesan solo los otros.
        $r = $this->dispatcher->dispatchDue(100);
        $this->assertSame(5, $r['sent']);
        $this->assertNotContains($id, array_column($this->host->delivered, 'redemption_id'));
        $this->assertSame('pending', $this->row($id)->status);
    }

    public function test_tras_fallar_y_llegar_la_hora_se_entrega_y_queda_enviado(): void
    {
        $id = $this->ids[0];
        $this->host->failTimes[$id] = 2;
        foreach ([1, 2] as $_) {
            $this->makeDue($id);
            $this->dispatcher->dispatchDue(100);
        }
        $this->makeDue($id);
        $this->host->delivered = [];
        $this->dispatcher->dispatchDue(100);

        $row = $this->row($id);
        $this->assertSame('sent', $row->status);
        $this->assertSame(3, (int) $row->attempts);
        $this->assertNull($row->last_error, 'el error anterior se limpia al entregar');
        $this->assertContains($id, array_column($this->host->delivered, 'redemption_id'));
    }

    public function test_agotados_los_intentos_queda_fallido_con_el_motivo_y_no_se_reintenta(): void
    {
        $id = $this->ids[0];
        $this->host->alwaysFail = true;
        $this->host->failMessage = str_repeat('x', 800);

        $stats = ['retry' => 0, 'failed' => 0];
        for ($i = 0; $i < 4; $i++) {
            $this->makeDue($id);
            $r = $this->dispatcher->dispatchDue(1);
            $stats['retry'] += $r['retry'];
            $stats['failed'] += $r['failed'];
        }
        $this->assertSame(['retry' => 3, 'failed' => 1], $stats);

        $row = $this->row($id);
        $this->assertSame('failed', $row->status);
        $this->assertSame(4, (int) $row->attempts);
        $this->assertSame(500, mb_strlen($row->last_error), 'el motivo se acota');

        // Un aviso fallido no vuelve a procesarse solo: espera revisión.
        $this->makeDue($id);
        $this->assertSame(0, $this->dispatcher->dispatchDue(100)['failed'] + (in_array($id, array_column($this->host->delivered, 'redemption_id'), true) ? 1 : 0));
    }

    public function test_un_fallo_de_un_aviso_no_afecta_a_los_demas(): void
    {
        $this->host->failTimes[$this->ids[2]] = 99;
        $r = $this->dispatcher->dispatchDue(100);
        $this->assertSame(['sent' => 5, 'retry' => 1, 'failed' => 0], $r);
    }

    public function test_sin_avisos_no_hace_nada(): void
    {
        DB::update("UPDATE prize_outbox SET status = 'sent', sent_at = now()");
        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0], $this->dispatcher->dispatchDue());
    }

    // --- Comando ----------------------------------------------------------------------------------------------------------

    public function test_el_comando_informa_y_su_codigo_de_salida_refleja_los_fallidos(): void
    {
        $this->app->instance(PrizeFulfillment::class, $this->host);
        $this->artisan('gamification:outbox:dispatch', ['--limit' => 3])
            ->expectsOutputToContain('Enviados: 3')
            ->assertSuccessful();

        // Provoca un aviso fallido definitivo.
        config(['gamification.outbox.max_attempts' => 1]);
        $this->host->alwaysFail = true;
        $this->artisan('gamification:outbox:dispatch')
            ->expectsOutputToContain('fallidos: 3')
            ->assertFailed();
    }
}
