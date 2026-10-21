<?php

namespace App\Domain\Codes;

use App\Domain\Settings\Settings;

/**
 * Dónde espera el archivo de códigos generados hasta su descarga única (SPEC-003 §8, `codes.generate`). Directorio 0700 fuera de `public/`,
 * un archivo por solicitud, y caducidad por el parámetro `admin.export_ttl_minutes`: pasado ese plazo se destruye aunque nadie lo descargue.
 * Reclamar es un renombrado, que es atómico: de dos descargas simultáneas solo una obtiene el archivo.
 */
final class CodeExportStore
{
    public function dir(): string
    {
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public function path(int $approvalId): string
    {
        return $this->dir()."/codes-$approvalId.csv";
    }

    /** @return string|null ruta del archivo ya reclamado (solo de quien lo reclamó) o null si no hay */
    public function claim(int $approvalId): ?string
    {
        $from = $this->path($approvalId);
        $to = $this->dir().'/codes-'.$approvalId.'.'.bin2hex(random_bytes(6)).'.served';

        return @rename($from, $to) ? $to : null;
    }

    public function discard(string $path): void
    {
        foreach ([$path, $path.'.partial'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** Destruye lo que pasó su plazo (y cualquier `.served` huérfano de más de un día). @return int archivos destruidos */
    public function purgeExpired(): int
    {
        $ttl = Settings::int('admin.export_ttl_minutes') * 60;
        $n = 0;
        foreach (glob($this->dir().'/codes-*') ?: [] as $f) {
            $age = time() - (int) filemtime($f);
            $limit = str_ends_with($f, '.served') ? 86400 : $ttl;
            if ($age > $limit && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
