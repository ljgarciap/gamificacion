<?php

namespace App\Domain\Audit;

use App\Engine\Bytes;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora append-only encadenada (RF-25). Cada entrada guarda el hash de la anterior:
 *
 *   hash = SHA256("gamif/v1/audit" ‖ prev_hash ‖ lp(actor) ‖ lp(action) ‖ lp(subject_type) ‖ lp(subject_id)
 *                 ‖ lp(data_canónico) ‖ be64(microsegundos))
 *
 * Alterar, borrar o reordenar cualquier entrada rompe la cadena desde ahí. La base lo impide con triggers, y
 * [verifyChain] lo detecta aunque alguien los desactive. Debe llamarse DENTRO de la transacción del cambio que registra:
 * si la transacción falla, la entrada tampoco existe (no hay bitácora de cosas que no pasaron).
 */
final class AuditLog
{
    /** Candado de asesor: serializa los appends para que la cadena sea lineal aunque haya concurrencia. */
    private const LOCK_KEY = 7743001;

    public const GENESIS = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    /**
     * @param  array<string, mixed>  $data  Solo cadenas, enteros, booleanos y listas/mapas de esos. Sin decimales
     *                                      ni secretos (la bitácora es de lectura amplia).
     */
    public function append(string $actor, string $action, string $subjectType, string $subjectId, array $data = []): int
    {
        return DB::transaction(function () use ($actor, $action, $subjectType, $subjectId, $data): int {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $prev = DB::selectOne("SELECT encode(hash, 'hex') AS h FROM audit_log ORDER BY id DESC LIMIT 1");
            $prevHash = $prev === null ? self::GENESIS : hex2bin($prev->h);

            // Microsegundos desde la base: la misma fuente que se relee al verificar.
            $now = DB::selectOne("SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint AS micros");
            $micros = (int) $now->micros;

            $json = Canonical::json($data);
            $hash = self::entryHash($prevHash, $actor, $action, $subjectType, $subjectId, $json, $micros);

            $row = DB::selectOne(
                "INSERT INTO audit_log (occurred_at, actor, action, subject_type, subject_id, data, prev_hash, hash)
                 VALUES (to_timestamp(?::bigint / 1000000) + ((?::bigint % 1000000) * interval '1 microsecond'),
                         ?, ?, ?, ?, ?::jsonb, decode(?, 'hex'), decode(?, 'hex'))
                 RETURNING id",
                // Aritmética entera (segundos + microsegundos): to_timestamp(double) perdería el último dígito
                // a esta magnitud y el hash recomputado no coincidiría con el guardado.
                [$micros, $micros, $actor, $action, $subjectType, $subjectId, $json, bin2hex($prevHash), bin2hex($hash)],
            );

            return (int) $row->id;
        });
    }

    /**
     * Recorre la cadena completa. Devuelve null si es íntegra, o el id de la primera entrada que no cuadra.
     */
    public function verifyChain(): ?int
    {
        $expectedPrev = self::GENESIS;
        $rows = DB::cursor("SELECT id, actor, action, subject_type, subject_id, data::text AS data,
                                   encode(prev_hash, 'hex') AS prev_hash, encode(hash, 'hex') AS hash,
                                   (extract(epoch from occurred_at) * 1000000)::bigint AS micros
                            FROM audit_log ORDER BY id");
        foreach ($rows as $r) {
            $prev = hex2bin($r->prev_hash);
            if ($prev !== $expectedPrev) {
                return (int) $r->id;
            }
            $json = Canonical::json(json_decode($r->data, true, 512, JSON_THROW_ON_ERROR));
            $calc = self::entryHash($prev, $r->actor, $r->action, $r->subject_type, $r->subject_id, $json, (int) $r->micros);
            if ($calc !== hex2bin($r->hash)) {
                return (int) $r->id;
            }
            $expectedPrev = $calc;
        }

        return null;
    }

    /**
     * Verifica un tramo de la cadena (para recorrerla por partes desde el panel sin cargar toda la bitácora): las hasta `$limit` entradas con
     * id > `$afterId`. La primera debe colgar del hash de la entrada `$afterId` (0 = génesis); ese hash se toma de la base porque el tramo
     * anterior ya lo comprobó. Un tramo suelto NO prueba la bitácora entera: hay que recorrerla de 0 hasta `done`.
     *
     * @return array{checked: int, brokenAt: int|null, lastId: int, lastHash: string|null, done: bool}
     */
    public function verifySegment(int $afterId, int $limit): array
    {
        $expectedPrev = self::GENESIS;
        if ($afterId > 0) {
            $anchor = DB::selectOne("SELECT encode(hash, 'hex') AS h FROM audit_log WHERE id = ?", [$afterId]);
            if ($anchor === null) {
                throw new \InvalidArgumentException('afterId no existe en la bitácora.');
            }
            $expectedPrev = hex2bin($anchor->h);
        }
        $rows = DB::select("SELECT id, actor, action, subject_type, subject_id, data::text AS data,
                                   encode(prev_hash, 'hex') AS prev_hash, encode(hash, 'hex') AS hash,
                                   (extract(epoch from occurred_at) * 1000000)::bigint AS micros
                            FROM audit_log WHERE id > ? ORDER BY id LIMIT ?", [$afterId, $limit]);
        $lastId = $afterId;
        $lastHash = $afterId > 0 ? bin2hex($expectedPrev) : null;
        $checked = 0;
        foreach ($rows as $r) {
            $prev = hex2bin($r->prev_hash);
            $json = Canonical::json(json_decode($r->data, true, 512, JSON_THROW_ON_ERROR));
            $calc = self::entryHash($prev, $r->actor, $r->action, $r->subject_type, $r->subject_id, $json, (int) $r->micros);
            if ($prev !== $expectedPrev || $calc !== hex2bin($r->hash)) {
                return ['checked' => $checked, 'brokenAt' => (int) $r->id, 'lastId' => $lastId, 'lastHash' => $lastHash, 'done' => true];
            }
            $expectedPrev = $calc;
            $lastId = (int) $r->id;
            $lastHash = $r->hash;
            $checked++;
        }

        return ['checked' => $checked, 'brokenAt' => null, 'lastId' => $lastId, 'lastHash' => $lastHash, 'done' => count($rows) < $limit];
    }

    private static function entryHash(
        string $prev, string $actor, string $action, string $subjectType, string $subjectId, string $json, int $micros,
    ): string {
        return hash('sha256',
            'gamif/v1/audit'.$prev.Bytes::lp($actor).Bytes::lp($action).Bytes::lp($subjectType).Bytes::lp($subjectId)
            .Bytes::lp($json).Bytes::be64($micros),
            true);
    }
}
