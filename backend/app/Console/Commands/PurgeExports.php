<?php

namespace App\Console\Commands;

use App\Domain\Codes\CodeExportStore;
use Illuminate\Console\Command;

final class PurgeExports extends Command
{
    protected $signature = 'admin:purge-exports';

    protected $description = 'Destruye los archivos de códigos generados cuya descarga caducó';

    public function handle(CodeExportStore $store): int
    {
        $this->info('Destruidos: '.$store->purgeExpired());

        return self::SUCCESS;
    }
}
