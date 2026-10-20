<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Genera los secretos del motor de premios. Sin --write solo los imprime; con --write los agrega a .env
 * únicamente si todavía no existen (nunca pisa un secreto existente: perder la clave del mapa de premios
 * dejaría ilegibles los lotes ya comprometidos).
 *
 * Con --dev agrega además la configuración de DESARROLLO LOCAL: el emisor de tokens de desarrollo (con su propia clave aleatoria), los
 * endpoints de desarrollo, la baliza local (no aleatoria, evita esperar a drand), los orígenes CORS de la web local y un saldo de demostración.
 * Nada de eso debe existir en un entorno real.
 */
class GenerateKeys extends Command
{
    protected $signature = 'gamification:keys
        {--write : Agrega las claves que falten al archivo .env}
        {--dev : Agrega también la configuración de desarrollo local (tokens de desarrollo, baliza local, CORS)}
        {--path= : Ruta del archivo .env (por defecto, el de la aplicación)}';

    protected $description = 'Genera GAMIF_PRIZE_MAP_KEY y GAMIF_CODE_PEPPER (32 bytes aleatorios en base64) y, con --dev, la configuración local';

    private const SECRETS = ['GAMIF_PRIZE_MAP_KEY', 'GAMIF_CODE_PEPPER'];

    /** Orígenes desde los que se sirve la web en desarrollo (docker compose: web en 8080, hot reload en 8081). */
    private const DEV_ORIGINS = 'http://localhost:8080,http://localhost:8081';

    public function handle(): int
    {
        $envPath = (string) ($this->option('path') ?: base_path('.env'));
        $current = $this->option('write') && is_file($envPath) ? (string) file_get_contents($envPath) : '';

        $wanted = [];
        foreach (self::SECRETS as $name) {
            $wanted[$name] = base64_encode(random_bytes(32));
        }
        if ($this->option('dev')) {
            $wanted += [
                // Comillas simples: el valor es JSON y el .env no debe interpretar sus llaves ni sus comillas dobles.
                'GAMIF_HOSTS' => "'".json_encode(['standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(random_bytes(32))]], JSON_UNESCAPED_SLASHES)."'",
                'GAMIF_DEV_ENDPOINTS' => 'true',
                'GAMIF_BEACON' => 'local',
                'GAMIF_CORS_ORIGINS' => self::DEV_ORIGINS,
                'GAMIF_DEMO_INITIAL_BALANCE' => '120000',
            ];
        }

        $append = '';
        foreach ($wanted as $name => $value) {
            $exists = preg_match('/^'.preg_quote($name, '/').'=\S+/m', $current) === 1;
            if ($exists) {
                $this->warn("$name ya existe: no se modifica.");

                continue;
            }
            $line = $name.'='.$value;
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
