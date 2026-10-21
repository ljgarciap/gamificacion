<?php

namespace App\Domain\Audit;

use App\Domain\Batches\InvalidBatch;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\DB;

/**
 * Exportación de auditoría de un lote (RF-26): todo lo que un tercero necesita para comprobar el sorteo SIN confiar en nuestro servidor
 * (lo recorre el verificador independiente, `verifier/gamif_verify.py`).
 *
 * Solo se exporta un lote REVELADO: antes, la semilla es secreta y el mapa código→premio no debe salir de la base.
 *
 * Incluye la bitácora encadenada COMPLETA hasta el momento de exportar (no solo las entradas del lote): la cadena es global, así que sin
 * ella no se puede recomputar. No lleva datos personales: los canjes se registran con un seudónimo (`user_ref`). Cada entrada lleva su
 * `data` como el texto canónico exacto que se hasheó, para que quien verifica no tenga que reproducir la canonicalización de JSON.
 */
final class AuditExporter
{
    public const VERSION = 2;

    public function __construct(private readonly PrizeVault $vault = new PrizeVault) {}

    /** @return array<string, mixed> */
    public function export(string $batchId): array
    {
        $b = DB::selectOne(
            "SELECT state, encode(chain_hash,'hex') AS chain, beacon_round, encode(beacon_value,'hex') AS beacon, pool::text AS pool,
                    encode(codes_digest,'hex') AS digest, encode(commitment,'hex') AS commitment, encode(seed_revealed,'hex') AS seed, reserve_size
             FROM batches WHERE id = ?", [$batchId]);
        if ($b === null) {
            throw new InvalidBatch("El lote $batchId no existe.");
        }
        if ($b->state !== 'revealed') {
            throw new InvalidBatch("Solo se exporta un lote revelado; el lote $batchId está en estado «{$b->state}».");
        }

        $assignments = [];
        $codeHashes = [];
        $rows = DB::cursor("SELECT idx, encode(code_hash,'hex') AS h, encode(prize_enc,'hex') AS p FROM codes WHERE batch_id = ? ORDER BY idx", [$batchId]);
        foreach ($rows as $r) {
            $assignments[] = $this->vault->decryptPrize($batchId, (int) $r->idx, hex2bin($r->p));
            $codeHashes[] = $r->h;
        }

        // Reserva del giro extra: sus casillas, en orden, y la versión del compromiso (v2). Sin reserva no se agrega nada: la exportación
        // es byte a byte la de siempre.
        $reserve = [];
        if ((int) $b->reserve_size > 0) {
            $codes = count($assignments);
            $slots = DB::cursor("SELECT idx, encode(prize_enc,'hex') AS p FROM reserve_slots WHERE batch_id = ? ORDER BY idx", [$batchId]);
            foreach ($slots as $r) {
                $reserve[] = $this->vault->decryptReservePrize($batchId, $codes + (int) $r->idx, hex2bin($r->p));
            }
        }

        // El lote ya es inmutable (revelado), así que leer la cadena después de él no puede contradecirlo. Una sola sentencia = una sola foto.
        $entries = [];
        $chain = DB::cursor("SELECT id, actor, action, subject_type, subject_id, data::text AS data,
                                    (extract(epoch from occurred_at) * 1000000)::bigint AS micros,
                                    encode(prev_hash,'hex') AS prev_hash, encode(hash,'hex') AS hash
                             FROM audit_log ORDER BY id");
        foreach ($chain as $r) {
            $entries[] = [
                'id' => (int) $r->id, 'actor' => $r->actor, 'action' => $r->action, 'subject_type' => $r->subject_type,
                'subject_id' => $r->subject_id,
                'data' => Canonical::json(json_decode($r->data, true, 512, JSON_THROW_ON_ERROR)),
                'at_micros' => (int) $r->micros, 'prev_hash' => $r->prev_hash, 'hash' => $r->hash,
            ];
        }

        return [
            'version' => self::VERSION, 'batch_id' => $batchId, 'chain_hash' => $b->chain, 'beacon_round' => (int) $b->beacon_round,
            'beacon_value' => $b->beacon, 'pool' => json_decode($b->pool, true), 'codes_digest' => $b->digest,
            'commitment' => $b->commitment, 'seed' => $b->seed, 'code_hashes' => $codeHashes, 'assignments' => $assignments,
            'audit_log' => $entries,
        ] + ($reserve === [] ? [] : ['commit_version' => 2, 'reserve_assignments' => $reserve]);
    }
}
