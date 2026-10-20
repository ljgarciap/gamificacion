<?php

namespace App\Domain\Dev;

use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\InvalidCodeFormat;
use App\Domain\Crypto\CodeHasher;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de demostración (SOLO desarrollo). El texto en claro de un código no está en la base de datos (por diseño); el comando de demo lo
 * deja en un archivo local, fuera de git, para que la app independiente pueda mostrarlo como sugerencia. En producción este archivo no existe.
 */
final class DemoCodes
{
    public static function path(): string
    {
        return storage_path('app/demo-codes.json');
    }

    /** @return list<array{code: string, game: string, description: string, batch_id: string}> */
    public static function load(): array
    {
        if (! is_file(self::path())) {
            return [];
        }
        $data = json_decode((string) file_get_contents(self::path()), true);

        return is_array($data) ? array_values($data) : [];
    }

    /** @param list<array{code: string, game: string, description: string, batch_id: string}> $entries */
    public static function save(array $entries): void
    {
        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(self::path(), json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Los códigos que todavía se pueden canjear (el lote sigue activo y nadie los usó).
     *
     * @return list<array{code: string, game: string, description: string, batch_id: string}>
     */
    public static function available(): array
    {
        $hasher = new CodeHasher;
        $out = [];
        foreach (self::load() as $entry) {
            try {
                $hash = bin2hex($hasher->hash(CodeFormat::normalize($entry['code'])));
            } catch (InvalidCodeFormat) {
                continue;
            }
            $row = DB::selectOne(
                "SELECT c.redeemed_at IS NOT NULL AS redeemed, b.state FROM codes c JOIN batches b ON b.id = c.batch_id WHERE c.code_hash = decode(?, 'hex')",
                [$hash],
            );
            if ($row !== null && ! $row->redeemed && $row->state === 'active') {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
