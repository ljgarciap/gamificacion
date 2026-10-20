<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutbox extends Command
{
    protected $signature = 'gamification:outbox:dispatch {--limit=100 : Máximo de avisos por ejecución}';

    protected $description = 'Entrega los avisos de acreditación pendientes a la app host (al menos una vez, con reintentos)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $r = $dispatcher->dispatchDue((int) $this->option('limit'));
        $this->info("Enviados: {$r['sent']} · a reintentar: {$r['retry']} · fallidos: {$r['failed']}");

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
