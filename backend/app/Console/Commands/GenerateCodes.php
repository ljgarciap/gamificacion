<?php

namespace App\Console\Commands;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Codes\CodeGenerator;
use Illuminate\Console\Command;

/**
 * Genera y exporta los códigos de un lote en borrador. El archivo es la ÚNICA copia en claro: guárdelo y protéjalo.
 */
class GenerateCodes extends Command
{
    protected $signature = 'gamification:codes:generate
        {batch : Id del lote (en borrador)}
        {--prefix= : Prefijo visible del código, p. ej. RULETA}
        {--out= : Archivo CSV de salida (no se sobrescribe)}
        {--actor=cli : Quién lo ejecuta, para la bitácora}';

    protected $description = 'Genera los códigos de un lote y los exporta una única vez (en la base solo quedan sus huellas)';

    public function handle(CodeGenerator $generator): int
    {
        $prefix = (string) $this->option('prefix');
        $out = (string) $this->option('out');
        if ($prefix === '' || $out === '') {
            $this->error('Indique --prefix y --out.');

            return self::INVALID;
        }

        try {
            $result = $generator->generate((string) $this->argument('batch'), $prefix, $out, (string) $this->option('actor'));
        } catch (InvalidBatch $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Generados {$result->count} códigos para el lote {$result->batchId}.");
        $this->line("Archivo: {$result->path} (permisos 0600)");
        $this->line("SHA-256: {$result->sha256}");
        $this->warn('Esta es la ÚNICA copia en claro de los códigos: la base solo guarda sus huellas.');

        return self::SUCCESS;
    }
}
