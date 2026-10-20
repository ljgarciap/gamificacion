<?php

namespace App\Domain\Codes;

use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Batches\InvalidBatch;
use App\Domain\Crypto\CodeHasher;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Genera los códigos de un lote en borrador y los EXPORTA una única vez (SPEC-002 §3 paso 2, RF-21).
 *
 *  - Cada código sale de 7 bytes del generador criptográfico del sistema operativo (50 bits, ver CodeFormat).
 *  - En la base se guarda SOLO la huella HMAC (con pepper). El texto en claro existe únicamente en el archivo exportado:
 *    si se pierde, no se puede recuperar y hay que recrear el lote.
 *  - Todo o nada: la base y el archivo se confirman juntos. Si algo falla, no queda ningún código en el lote ni archivo a medias.
 *  - La bitácora registra el SHA-256 del archivo exportado, para poder probar después que es el original.
 *
 * El orden de las filas del archivo es el de generación; no contiene el índice ni nada del premio.
 */
final class CodeGenerator
{
    /** Reintentos por tanda ante una colisión de huella (probabilidad ínfima: ver docs/spec-002). */
    private const MAX_COLLISION_RETRIES = 5;

    private readonly Closure $randomBytes;

    /**
     * @param  (callable(int): string)|null  $randomBytes  fuente de bytes; por defecto el CSPRNG del sistema operativo.
     *                                                    Inyectable SOLO para pruebas (determinismo y colisiones).
     */
    public function __construct(
        private readonly BatchLifecycle $lifecycle,
        private readonly CodeHasher $hasher,
        private readonly AuditLog $audit,
        ?callable $randomBytes = null,
    ) {
        $this->randomBytes = $randomBytes !== null ? Closure::fromCallable($randomBytes) : random_bytes(...);
    }

    /**
     * @param  string  $exportPath  archivo CSV a crear (no se sobrescribe uno existente; permisos 0600)
     *
     * @throws InvalidBatch
     */
    public function generate(string $batchId, string $prefix, string $exportPath, string $actor, int $chunk = 2000): GeneratedCodes
    {
        if (! CodeFormat::isValidPrefix($prefix)) {
            throw new InvalidBatch('Prefijo inválido: 2 a '.CodeFormat::MAX_PREFIX.' caracteres, mayúsculas y dígitos, empezando por letra.');
        }
        if ($chunk < 1) {
            throw new \InvalidArgumentException('el tamaño de tanda debe ser positivo');
        }
        if (file_exists($exportPath) || file_exists($exportPath.'.partial')) {
            throw new InvalidBatch("El archivo de exportación ya existe: $exportPath. No se sobrescribe.");
        }

        $batch = DB::selectOne('SELECT state, total_codes FROM batches WHERE id = ?', [$batchId])
            ?? throw new InvalidBatch("El lote $batchId no existe.");
        if ($batch->state !== 'draft') {
            throw new InvalidBatch("Solo se generan códigos para un lote en borrador (estado: {$batch->state}).");
        }
        $existing = (int) DB::selectOne('SELECT count(*) AS c FROM codes WHERE batch_id = ?', [$batchId])->c;
        if ($existing !== 0) {
            // El texto en claro de unos códigos ya generados no se puede recuperar: no se completa un lote a medias.
            throw new InvalidBatch("El lote ya tiene $existing códigos: la generación es de una sola vez.");
        }
        $total = (int) $batch->total_codes;

        $partial = $exportPath.'.partial';
        $fh = fopen($partial, 'xb'); // 'x': falla si existe
        if ($fh === false) {
            throw new InvalidBatch("No se pudo crear el archivo de exportación: $partial");
        }
        chmod($partial, 0600);
        fwrite($fh, "codigo\n");

        try {
            $sha = DB::transaction(function () use ($batchId, $prefix, $actor, $chunk, $total, $fh, $partial): string {
                $written = 0;
                while ($written < $total) {
                    $n = min($chunk, $total - $written);
                    $lines = $this->insertChunk($batchId, $prefix, $n, $actor);
                    fwrite($fh, implode("\n", $lines)."\n");
                    $written += $n;
                }
                fflush($fh);
                fsync($fh);
                fclose($fh);
                $sha = hash_file('sha256', $partial);

                $this->audit->append($actor, 'batch.codes_generated', 'batch', $batchId, [
                    'count' => $total, 'prefix' => $prefix, 'export_sha256' => $sha,
                ]);

                return $sha;
            });
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            @unlink($partial); // todo o nada: sin base confirmada no debe quedar un archivo con códigos
            throw $e;
        }

        // La base ya confirmó. Si este renombrado fallara, los códigos siguen íntegros en el .partial (no se borra).
        if (! rename($partial, $exportPath)) {
            throw new \RuntimeException("Los códigos se guardaron pero no se pudo renombrar $partial a $exportPath; el .partial es válido.");
        }

        return new GeneratedCodes($batchId, $total, $exportPath, $sha, $prefix);
    }

    /**
     * Genera una tanda de $n códigos únicos, guarda sus huellas y devuelve las líneas para exportar.
     * Reintenta la tanda completa si alguna huella ya existe (colisión entre lotes).
     *
     * @return list<string>
     */
    private function insertChunk(string $batchId, string $prefix, int $n, string $actor): array
    {
        for ($attempt = 1; $attempt <= self::MAX_COLLISION_RETRIES; $attempt++) {
            $codes = [];
            $guard = 0;
            while (count($codes) < $n) {
                if (++$guard > $n * 20 + 100) {
                    throw new \RuntimeException('el generador de bytes no produce códigos distintos');
                }
                $canonical = CodeFormat::canonicalFromBytes(($this->randomBytes)(CodeFormat::BYTES_NEEDED));
                $codes[$canonical] = true; // repetido dentro de la tanda: se descarta
            }
            // array_keys devuelve ENTEROS para las claves que parecen números (p. ej. un código de 11 dígitos sin ceros a la izquierda).
            $canon = array_map('strval', array_keys($codes));
            $hashes = array_map(fn (string $c): string => $this->hasher->hash($c), $canon);

            try {
                $this->lifecycle->addCodeHashes($batchId, $hashes, $actor, audit: false);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'codes_hash_unique') && $attempt < self::MAX_COLLISION_RETRIES) {
                    continue; // colisión con un código de otro lote: se vuelve a sortear la tanda
                }
                throw $e;
            }

            return array_map(static fn (string $c): string => CodeFormat::display($c, $prefix), $canon);
        }

        throw new \RuntimeException('no se pudo generar una tanda sin colisiones'); // inalcanzable: el bucle lanza antes
    }
}
