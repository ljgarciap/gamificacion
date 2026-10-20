<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditAnchor;
use App\Domain\Audit\AuditExporter;
use App\Domain\Batches\InvalidBatch;
use Illuminate\Console\Command;

/**
 * Exporta la auditoría de un lote revelado y, con --anchor, el ancla de la bitácora para publicarla fuera de la base.
 */
class AuditExport extends Command
{
    protected $signature = 'gamification:audit:export
        {batch : Id del lote (revelado)}
        {--out= : Archivo JSON de la exportación (no se sobrescribe)}
        {--anchor= : Archivo JSON del ancla de la bitácora (no se sobrescribe); publíquelo fuera de la base}';

    protected $description = 'Exporta la auditoría de un lote revelado (para el verificador independiente) y el ancla de la bitácora';

    public function handle(AuditExporter $exporter, AuditAnchor $anchor): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            $this->error('Indique --out.');

            return self::INVALID;
        }
        $anchorOut = (string) $this->option('anchor');
        foreach (array_filter([$out, $anchorOut]) as $path) {
            if (file_exists($path)) {
                $this->error("$path ya existe: no se sobrescribe.");

                return self::FAILURE;
            }
        }

        try {
            $export = $exporter->export((string) $this->argument('batch'));
        } catch (InvalidBatch $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        file_put_contents($out, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->info('Exportación escrita en '.$out.' ('.count($export['audit_log']).' entradas de bitácora).');

        if ($anchorOut !== '') {
            $a = $anchor->current();
            if ($a === null) {
                $this->error('La bitácora está vacía: no hay nada que anclar.');

                return self::FAILURE;
            }
            file_put_contents($anchorOut, json_encode($a, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            $this->info("Ancla escrita en $anchorOut (entrada {$a['head_id']}, hash {$a['head_hash']}).");
            $this->warn('Publique el ancla FUERA de la base y de la máquina del operador: es lo que permite detectar que se borró el final de la bitácora.');
        }

        return self::SUCCESS;
    }
}
