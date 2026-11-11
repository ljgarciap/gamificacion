<?php

namespace Tests\Support;

/**
 * Lanza el simulador del host (host-sim/index.php) como un proceso LOCAL y desechable, con su propio archivo de estado y una latencia
 * fija opcional por aviso (HOST_SIM_LATENCY_MS). No toca el simulador del compose ni ningún dato de desarrollo.
 * Necesita la carpeta del simulador: HOST_SIM_DIR o /host-sim (docker-compose.yml la monta en el servicio `backend`).
 */
final class HostSimProcess
{
    public const SECRET = 'secreto-de-prueba-del-despachador';

    /** @var resource */
    private $proc;

    private function __construct(public readonly string $base, private readonly string $stateFile, $proc)
    {
        $this->proc = $proc;
    }

    public static function dir(): ?string
    {
        $dir = getenv('HOST_SIM_DIR') ?: '/host-sim';

        return is_file($dir.'/index.php') ? $dir : null;
    }

    public static function start(int $latencyMs = 0, int $workers = 4): self
    {
        $dir = self::dir() ?? throw new \RuntimeException('No se encontró host-sim/index.php (HOST_SIM_DIR).');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);
        $stateFile = sys_get_temp_dir().'/host-sim-'.bin2hex(random_bytes(4)).'.json';
        $env = ['PATH' => (string) getenv('PATH'), 'HOST_SIM_SECRETS' => self::SECRET, 'HOST_SIM_STATE' => $stateFile,
            'HOST_SIM_LATENCY_MS' => (string) $latencyMs, 'PHP_CLI_SERVER_WORKERS' => (string) $workers];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir.'/index.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, $env);
        $sim = new self("http://127.0.0.1:$port", $stateFile, $proc);
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents($sim->base.'/__sim/state') !== false) {
                return $sim;
            }
            usleep(100_000);
        }
        $sim->stop();
        throw new \RuntimeException('el simulador no arrancó');
    }

    /** @return array{wallet: array<string, int>, events: array<string, array{duplicates: int}>, rejected: list<mixed>} */
    public function state(): array
    {
        return json_decode((string) file_get_contents($this->base.'/__sim/state'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{credited: int, events: int, duplicates: int, rejected: int} total acreditado, eventos distintos, reenvíos detectados, firmas rechazadas */
    public function summary(): array
    {
        $s = $this->state();

        return [
            'credited' => array_sum($s['wallet']), 'events' => count($s['events']),
            'duplicates' => array_sum(array_column($s['events'], 'duplicates')), 'rejected' => count($s['rejected']),
        ];
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running']) {
                // `php -S` con PHP_CLI_SERVER_WORKERS crea hijos: se matan todos con el grupo del proceso.
                @exec('pkill -P '.(int) $status['pid'].' 2>/dev/null');
                proc_terminate($this->proc, 9);
            }
            proc_close($this->proc);
        }
        @unlink($this->stateFile);
    }
}
