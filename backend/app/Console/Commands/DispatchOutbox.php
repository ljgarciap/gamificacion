<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutbox extends Command
{
    protected $signature = 'gamification:outbox:dispatch
        {--limit= : Máximo de avisos por ejecución (por defecto GAMIF_OUTBOX_DISPATCH_LIMIT, 100)}
        {--max-seconds= : Tope de tiempo por ejecución en segundos; 0 = sin tope (por defecto GAMIF_OUTBOX_DISPATCH_MAX_SECONDS, 0)}';

    protected $description = 'Entrega los avisos de acreditación pendientes a la app host (al menos una vez, con reintentos)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = $this->option('limit') ?? config('gamification.outbox.dispatch_limit', 100);
        $max = (float) ($this->option('max-seconds') ?? config('gamification.outbox.dispatch_max_seconds', 0));
        $r = $dispatcher->dispatchDue(max(1, (int) $limit), $max > 0 ? $max : null);
        $this->info("Enviados: {$r['sent']} · a reintentar: {$r['retry']} · fallidos: {$r['failed']}");

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
