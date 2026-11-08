<?php

namespace App\Console\Commands;

use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoRuns;
use App\Domain\Demo\DemoSeeder;
use App\Domain\Demo\DemoSwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tarea periódica de la muestra (SPEC-006): apaga la muestra caducada, da por fallidas las corridas abandonadas y ejecuta la corrida
 * pendiente (sembrar o reiniciar). La planifica el servicio `worker` cada minuto, solo en servidores de demostración.
 */
final class DemoProcess extends Command
{
    protected $signature = 'gamification:demo:process';

    protected $description = 'Apaga la muestra caducada y ejecuta la corrida pendiente de sembrado/reinicio (SPEC-006)';

    public function handle(DemoSwitch $switch, DemoRuns $runs): int
    {
        if ($switch->expireIfDue()) {
            $this->info('La muestra caducó y se apagó.');
        }
        $stale = $runs->failStale();
        $stale > 0 && $this->warn("$stale corrida(s) abandonada(s) marcadas como fallidas.");

        $run = $runs->claim();
        if ($run === null) {
            return self::SUCCESS;
        }
        $actor = $run['requested_by'];
        try {
            $config = $switch->row()['config'];
            if ($config === null) {
                throw new \RuntimeException('No hay configuración guardada.');
            }
            $config = DemoConfig::validate($config);
            $seeder = $this->laravel->make(DemoSeeder::class);
            if ($run['kind'] === 'reset') {
                $switch->disable('system', 'reset');
                $seeder->retire($actor);
            } elseif ((int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n > 0) {
                throw new \RuntimeException('Ya hay códigos disponibles: usa «Reiniciar» para empezar de cero.');
            }
            $r = $seeder->seed($config, $actor);
            $runs->finish($run['id']);
            $this->info("Corrida {$run['id']} ({$run['kind']}): {$r['codes']} códigos en {$r['games']} juego(s).");
        } catch (\Throwable $e) {
            $runs->finish($run['id'], class_basename($e).': '.$e->getMessage());
            $this->error("Corrida {$run['id']} falló: ".$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
