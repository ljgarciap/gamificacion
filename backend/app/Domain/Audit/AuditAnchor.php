<?php

namespace App\Domain\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Ancla externa de la bitácora (SPEC-002 R4). La cadena detecta cualquier alteración o borrado EN EL MEDIO, pero quien borre solo las últimas
 * entradas deja una cadena internamente válida. La defensa es publicar el último hash FUERA de la base (junto al compromiso, en un sitio
 * que el operador no controle por sí solo): después, un export al que le falte esa entrada no puede coincidir con el ancla.
 *
 * El ancla no es secreta: es el id y el hash de la última entrada. Con ella y una exportación, el verificador comprueba que el ancla sigue
 * en la cadena y con el mismo hash.
 */
final class AuditAnchor
{
    public const VERSION = 1;

    /** @return array{version: int, head_id: int, head_hash: string, created_at: string}|null  null si la bitácora está vacía */
    public function current(): ?array
    {
        $head = DB::selectOne("SELECT id, encode(hash,'hex') AS hash, to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS now
                               FROM audit_log ORDER BY id DESC LIMIT 1");
        if ($head === null) {
            return null;
        }

        return ['version' => self::VERSION, 'head_id' => (int) $head->id, 'head_hash' => $head->hash, 'created_at' => $head->now];
    }
}
