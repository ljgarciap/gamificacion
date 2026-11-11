<?php

namespace Tests\Support;

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Fulfillment\OutboxDispatcher;
use App\Domain\Fulfillment\PrizeFulfillment;
use App\Domain\Hosts\HostService;
use App\Domain\Hosts\HostUrlGuard;
use Illuminate\Support\Facades\DB;

/** Arma un host registrado contra HostSimProcess y N avisos pendientes REALES (canjes con premio de dinero) para probar el despachador. */
trait OutboxHostFixture
{
    protected function extraEnv(): array
    {
        return ['APP_ENV' => 'local', 'GAMIF_HOST_ALLOW_LOCAL' => 'true', 'GAMIF_FULFILLMENT' => 'http'];
    }

    protected function registerHost(HostSimProcess $sim): void
    {
        config(['gamification.hosts' => ['bench-host' => ['alg' => 'HS256', 'key' => 'k']]]);
        $svc = new HostService(new AuditLog, new SecretBox, HostUrlGuard::fromConfig());
        $h = $svc->create(['issuer' => 'bench-host', 'callbackUrl' => $sim->base.'/callback', 'balanceUrl' => $sim->base.'/balance'], 'bench');
        DB::update("UPDATE hosts SET secret_current_enc = decode(?, 'hex') WHERE id = ?",
            [bin2hex((new SecretBox)->seal(HostSimProcess::SECRET, "hosts|{$h['id']}|secret_current_enc")), $h['id']]);
    }

    /** Deja $n avisos pendientes (cada uno de $10.000 para un usuario distinto). */
    protected function seedPendingNotices(int $n): void
    {
        $rf = new RedeemFixtures;
        $b = $rf->activeBatch(Fixtures::pool(none: 0, p10k: $n, p50k: 0));
        $svc = $rf->service();
        foreach ($b['codes'] as $i => $code) {
            $svc->redeem("bench-host:u$i", 'clave-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), $code);
        }
        $this->assertSame($n, DB::table('prize_outbox')->where('status', 'pending')->count());
    }

    protected function newDispatcher(): OutboxDispatcher
    {
        return new OutboxDispatcher(app(PrizeFulfillment::class));
    }
}
