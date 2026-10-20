<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Genera los secretos del motor de premios. Sin --write solo los imprime; con --write los agrega a .env
 * únicamente si todavía no existen (nunca pisa un secreto existente: perder la clave del mapa de premios
 * dejaría ilegibles los lotes ya comprometidos).
 */
class GenerateKeys extends Command
{
    protected $signature = 'gamification:keys
        {--write : Agrega las claves que falten al archivo .env}
        {--path= : Ruta del archivo .env (por defecto, el de la aplicación)}';

    protected $description = 'Genera GAMIF_PRIZE_MAP_KEY y GAMIF_CODE_PEPPER (32 bytes aleatorios en base64)';

    private const NAMES = ['GAMIF_PRIZE_MAP_KEY', 'GAMIF_CODE_PEPPER'];

    public function handle(): int
    {
        $envPath = (string) ($this->option('path') ?: base_path('.env'));
        $current = $this->option('write') && is_file($envPath) ? (string) file_get_contents($envPath) : '';

        $append = '';
        foreach (self::NAMES as $name) {
            $exists = preg_match('/^'.preg_quote($name, '/').'=\S+/m', $current) === 1;
            if ($exists) {
                $this->warn("$name ya existe: no se modifica.");

                continue;
            }
            $line = $name.'='.base64_encode(random_bytes(32));
            if ($this->option('write')) {
                $append .= $line."\n";
                $this->info("$name agregada a .env");
            } else {
                $this->line($line);
            }
        }

        if ($append !== '') {
            $sep = $current !== '' && ! str_ends_with($current, "\n") ? "\n" : '';
            file_put_contents($envPath, $current.$sep.$append);
        }

        return self::SUCCESS;
    }
}
