<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ejecuta un trabajo en N PROCESOS reales (pcntl_fork), cada uno con su propia conexión a PostgreSQL, y los arranca a la vez.
 *
 * Es la única forma de probar carreras de verdad: dentro de una sola conexión (o de la transacción envolvente de los tests) PostgreSQL
 * nunca ve dos transacciones compitiendo. Cada hijo abre su conexión, espera la hora de salida común y ejecuta el trabajo.
 *
 * Detalles que importan:
 *  - Antes de bifurcar se cierra la conexión del padre: si hijo y padre compartieran el mismo socket, cerrar uno mataría la sesión del otro.
 *  - El hijo termina con SIGKILL, para que no corran los manejadores de cierre de PHPUnit/Laravel heredados del padre.
 */
final class Parallel
{
    /**
     * @param  \Closure(int): mixed  $work  recibe el índice del trabajador; su resultado debe poder serializarse
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string}>
     */
    public static function run(int $workers, \Closure $work, float $startDelay = 1.0): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            throw new \RuntimeException('Hacen falta las extensiones pcntl y posix para las pruebas de concurrencia.');
        }

        DB::disconnect();
        $goAt = microtime(true) + $startDelay;
        $sockets = [];
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('no se pudo bifurcar el proceso');
            }
            if ($pid === 0) { // --- hijo
                fclose($pair[0]);
                try {
                    DB::reconnect(); // conexión PROPIA
                    while (microtime(true) < $goAt) {
                        usleep(100);
                    }
                    $result = ['ok' => true, 'value' => $work($i)];
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()];
                }
                fwrite($pair[1], serialize($result));
                fclose($pair[1]);
                posix_kill(posix_getpid(), SIGKILL);
                exit(0); // inalcanzable
            }
            fclose($pair[1]);
            $sockets[$i] = $pair[0];
            $pids[$i] = $pid;
        }

        $results = [];
        foreach ($sockets as $i => $socket) {
            $raw = stream_get_contents($socket);
            fclose($socket);
            $results[$i] = $raw === '' || $raw === false
                ? ['ok' => false, 'class' => 'NoResult', 'message' => 'el proceso hijo no devolvió resultado (¿murió?)']
                : unserialize($raw);
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect(); // el padre vuelve a tener su propia conexión

        return $results;
    }
}
