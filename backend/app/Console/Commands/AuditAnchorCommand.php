<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditAnchor;
use Illuminate\Console\Command;

/**
 * Escribe el ancla actual de la bitácora (id + hash de la última entrada). Pensado para correr y PUBLICAR fuera de la base cada vez que
 * se compromete un lote, y de forma periódica: cada ancla publicada fija la historia hasta ese punto.
 */
class AuditAnchorCommand extends Command
{
    protected $signature = 'gamification:audit:anchor {--out= : Archivo JSON del ancla (no se sobrescribe)}';

    protected $description = 'Escribe el ancla de la bitácora (hash de la última entrada) para publicarla fuera de la base';

    public function handle(AuditAnchor $anchor): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            $this->error('Indique --out.');

            return self::INVALID;
        }
        if (file_exists($out)) {
            $this->error("$out ya existe: no se sobrescribe.");

            return self::FAILURE;
        }
        $a = $anchor->current();
        if ($a === null) {
            $this->error('La bitácora está vacía: no hay nada que anclar.');

            return self::FAILURE;
        }
        file_put_contents($out, json_encode($a, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->info("Ancla escrita en $out (entrada {$a['head_id']}, hash {$a['head_hash']}).");
        $this->warn('Publíquela FUERA de la base y de la máquina del operador.');

        return self::SUCCESS;
    }
}
