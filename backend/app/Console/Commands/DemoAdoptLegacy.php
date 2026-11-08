<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca como campañas de la muestra (`is_demo`) las que ya existían en un servidor de DEMOSTRACIÓN antes de SPEC-006 (la siembra anterior no
 * llevaba la marca). Sin la marca, la barrera de canje las rechaza para los usuarios del emisor abierto y el interruptor de la muestra se
 * niega a encender ("hay campañas reales activas").
 *
 * Es deliberadamente manual y explícito: solo corre en un servidor declarado de demostración (`GAMIF_DEMO_ALLOWED=true`), lista lo que va a
 * marcar y exige `--yes`. Quedan en la bitácora. NUNCA correrlo en un servidor con campañas reales.
 */
final class DemoAdoptLegacy extends Command
{
    protected $signature = 'gamification:demo:adopt-legacy {--yes : Confirmo que TODAS las campañas listadas son de demostración}';

    protected $description = 'Marca como de la muestra las campañas anteriores de un servidor de demostración (SPEC-006)';

    public function handle(AuditLog $audit): int
    {
        if (! (bool) config('gamification.demo.allowed')) {
            $this->error('Este comando solo corre en un servidor de demostración (GAMIF_DEMO_ALLOWED=true).');

            return self::FAILURE;
        }
        $rows = DB::select('SELECT id, name FROM campaigns WHERE NOT is_demo ORDER BY created_at');
        if ($rows === []) {
            $this->info('No hay campañas por adoptar.');

            return self::SUCCESS;
        }
        foreach ($rows as $r) {
            $this->line("  - {$r->name} ({$r->id})");
        }
        if (! $this->option('yes')) {
            $this->warn(count($rows).' campaña(s) se marcarían como de demostración. Repite con --yes si TODAS lo son (nunca en un servidor con campañas reales).');

            return self::FAILURE;
        }
        DB::transaction(function () use ($rows, $audit): void {
            DB::update('UPDATE campaigns SET is_demo = true WHERE NOT is_demo');
            $audit->append('system:demo-adopt', 'demo.legacy_adopted', 'demo', 'campaigns', ['campaigns' => array_map(fn ($r) => $r->id, $rows)]);
        });
        $this->info(count($rows).' campaña(s) marcadas como de demostración.');

        return self::SUCCESS;
    }
}
