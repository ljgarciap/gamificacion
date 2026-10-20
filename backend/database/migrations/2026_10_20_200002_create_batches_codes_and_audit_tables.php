<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lotes, códigos y bitácora (SPEC-002 §3, RF-22, RF-25, CA-28).
 *
 * La inmutabilidad se hace cumplir EN LA BASE DE DATOS, no solo en la aplicación: aunque un bug, un script o un
 * operador con acceso SQL intente cambiar un lote comprometido, PostgreSQL lo rechaza. Estados y transiciones:
 *
 *   draft ──▶ committed ──▶ active ──▶ closed ──▶ revealed
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE batches (
            id                uuid PRIMARY KEY,
            campaign_id       uuid NOT NULL REFERENCES campaigns (id) ON DELETE RESTRICT,
            name              varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
            state             varchar(12) NOT NULL DEFAULT 'draft'
                              CHECK (state IN ('draft', 'committed', 'active', 'closed', 'revealed')),

            -- Definición del lote: se congela al comprometer.
            pool              jsonb  NOT NULL CHECK (jsonb_typeof(pool) = 'array' AND jsonb_array_length(pool) > 0),
            pool_hash         bytea  NOT NULL CHECK (octet_length(pool_hash) = 32),
            total_codes       integer NOT NULL CHECK (total_codes > 0 AND total_codes <= 1000000),
            redeemable_until  timestamptz,

            -- Compromiso (público al comprometer, excepto la semilla, que va cifrada hasta revelarse).
            codes_digest      bytea CHECK (octet_length(codes_digest) = 32),
            chain_hash        bytea CHECK (octet_length(chain_hash) > 0),
            beacon_round      bigint CHECK (beacon_round > 0),
            commitment        bytea CHECK (octet_length(commitment) = 32),
            seed_enc          bytea,

            -- Se completan en etapas posteriores (escritura única).
            beacon_value      bytea CHECK (octet_length(beacon_value) > 0),
            seed_revealed     bytea CHECK (octet_length(seed_revealed) = 32),

            committed_at      timestamptz,
            activated_at      timestamptz,
            closed_at         timestamptz,
            revealed_at       timestamptz,
            created_by        varchar(190) NOT NULL,
            created_at        timestamptz NOT NULL DEFAULT now(),
            updated_at        timestamptz NOT NULL DEFAULT now(),

            -- Un lote en cada estado debe tener TODO lo que ese estado promete.
            CONSTRAINT batches_committed_complete CHECK (
                state = 'draft' OR (commitment IS NOT NULL AND seed_enc IS NOT NULL AND codes_digest IS NOT NULL
                                    AND chain_hash IS NOT NULL AND beacon_round IS NOT NULL AND committed_at IS NOT NULL)),
            CONSTRAINT batches_active_complete CHECK (
                state NOT IN ('active', 'closed', 'revealed') OR (beacon_value IS NOT NULL AND activated_at IS NOT NULL)),
            CONSTRAINT batches_closed_complete CHECK (
                state NOT IN ('closed', 'revealed') OR closed_at IS NOT NULL),
            CONSTRAINT batches_revealed_complete CHECK (
                state <> 'revealed' OR (seed_revealed IS NOT NULL AND revealed_at IS NOT NULL)),
            -- Y un borrador no puede traer nada de lo que solo existe tras comprometer.
            CONSTRAINT batches_draft_clean CHECK (
                state <> 'draft' OR (commitment IS NULL AND seed_enc IS NULL AND codes_digest IS NULL
                                     AND beacon_value IS NULL AND seed_revealed IS NULL))
        );
        CREATE INDEX batches_campaign_idx ON batches (campaign_id);

        CREATE TABLE codes (
            id           bigserial PRIMARY KEY,
            batch_id     uuid NOT NULL REFERENCES batches (id) ON DELETE RESTRICT,
            idx          integer NOT NULL CHECK (idx >= 0),
            -- HMAC con pepper: la base nunca guarda el código en claro (R2).
            code_hash    bytea NOT NULL CHECK (octet_length(code_hash) = 32),
            -- Premio asignado, CIFRADO y ligado a (lote, idx) (R3). Se escribe una sola vez, al activar el lote.
            prize_enc    bytea,
            redeemed_at  timestamptz,
            redeemed_by  varchar(190),
            CONSTRAINT codes_batch_idx_unique UNIQUE (batch_id, idx),
            CONSTRAINT codes_hash_unique UNIQUE (code_hash),
            CONSTRAINT codes_redemption_pair CHECK ((redeemed_at IS NULL) = (redeemed_by IS NULL)),
            CONSTRAINT codes_redeemed_has_prize CHECK (redeemed_at IS NULL OR prize_enc IS NOT NULL)
        );

        -- Bitácora append-only encadenada (RF-25): cada entrada incluye el hash de la anterior.
        CREATE TABLE audit_log (
            id            bigserial PRIMARY KEY,
            occurred_at   timestamptz(6) NOT NULL,
            actor         varchar(190) NOT NULL,
            action        varchar(60)  NOT NULL,
            subject_type  varchar(60)  NOT NULL,
            subject_id    varchar(64)  NOT NULL,
            data          jsonb NOT NULL DEFAULT '{}'::jsonb,
            prev_hash     bytea NOT NULL CHECK (octet_length(prev_hash) = 32),
            hash          bytea NOT NULL CHECK (octet_length(hash) = 32),
            CONSTRAINT audit_hash_unique UNIQUE (hash),
            -- Dos entradas no pueden colgar de la misma anterior: la cadena no se puede bifurcar.
            CONSTRAINT audit_prev_unique UNIQUE (prev_hash)
        );
        SQL);

        $this->installGuards();
    }

    private function installGuards(): void
    {
        // --- Lotes ---------------------------------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION batches_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF OLD.state <> 'draft' THEN
                    RAISE EXCEPTION 'el lote % no se puede borrar en estado %', OLD.id, OLD.state USING ERRCODE = '23000';
                END IF;
                RETURN OLD;
            END IF;

            IF NEW.id IS DISTINCT FROM OLD.id THEN
                RAISE EXCEPTION 'el id de un lote no se puede cambiar' USING ERRCODE = '23000';
            END IF;

            -- Borrador: editable, y solo puede pasar a comprometido.
            IF OLD.state = 'draft' THEN
                IF NEW.state NOT IN ('draft', 'committed') THEN
                    RAISE EXCEPTION 'transición inválida: % -> %', OLD.state, NEW.state USING ERRCODE = '23000';
                END IF;
                RETURN NEW;
            END IF;

            -- Desde que se compromete, lo comprometido queda congelado.
            IF NEW.campaign_id IS DISTINCT FROM OLD.campaign_id OR NEW.name IS DISTINCT FROM OLD.name
               OR NEW.pool IS DISTINCT FROM OLD.pool OR NEW.pool_hash IS DISTINCT FROM OLD.pool_hash
               OR NEW.total_codes IS DISTINCT FROM OLD.total_codes OR NEW.redeemable_until IS DISTINCT FROM OLD.redeemable_until
               OR NEW.codes_digest IS DISTINCT FROM OLD.codes_digest OR NEW.chain_hash IS DISTINCT FROM OLD.chain_hash
               OR NEW.beacon_round IS DISTINCT FROM OLD.beacon_round OR NEW.commitment IS DISTINCT FROM OLD.commitment
               OR NEW.seed_enc IS DISTINCT FROM OLD.seed_enc OR NEW.committed_at IS DISTINCT FROM OLD.committed_at
               OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                RAISE EXCEPTION 'el lote % está comprometido: su definición es inmutable', OLD.id USING ERRCODE = '23000';
            END IF;

            -- Transiciones válidas, solo hacia adelante.
            IF NEW.state <> OLD.state AND NOT (
                   (OLD.state = 'committed' AND NEW.state = 'active')
                OR (OLD.state = 'active'    AND NEW.state = 'closed')
                OR (OLD.state = 'closed'    AND NEW.state = 'revealed')) THEN
                RAISE EXCEPTION 'transición inválida: % -> %', OLD.state, NEW.state USING ERRCODE = '23000';
            END IF;

            -- Campos de escritura única, cada uno solo en su momento.
            IF OLD.beacon_value IS NOT NULL AND NEW.beacon_value IS DISTINCT FROM OLD.beacon_value THEN
                RAISE EXCEPTION 'beacon_value es de escritura única' USING ERRCODE = '23000';
            END IF;
            IF OLD.beacon_value IS NULL AND NEW.beacon_value IS NOT NULL AND NOT (OLD.state = 'committed' AND NEW.state = 'active') THEN
                RAISE EXCEPTION 'beacon_value solo se fija al activar el lote' USING ERRCODE = '23000';
            END IF;
            IF OLD.activated_at IS NOT NULL AND NEW.activated_at IS DISTINCT FROM OLD.activated_at THEN
                RAISE EXCEPTION 'activated_at es de escritura única' USING ERRCODE = '23000';
            END IF;
            IF OLD.closed_at IS NOT NULL AND NEW.closed_at IS DISTINCT FROM OLD.closed_at THEN
                RAISE EXCEPTION 'closed_at es de escritura única' USING ERRCODE = '23000';
            END IF;
            IF OLD.seed_revealed IS NOT NULL AND NEW.seed_revealed IS DISTINCT FROM OLD.seed_revealed THEN
                RAISE EXCEPTION 'la semilla revelada es de escritura única' USING ERRCODE = '23000';
            END IF;
            IF OLD.seed_revealed IS NULL AND NEW.seed_revealed IS NOT NULL AND NEW.state <> 'revealed' THEN
                RAISE EXCEPTION 'la semilla solo se revela al pasar a revealed' USING ERRCODE = '23000';
            END IF;
            IF OLD.revealed_at IS NOT NULL AND NEW.revealed_at IS DISTINCT FROM OLD.revealed_at THEN
                RAISE EXCEPTION 'revealed_at es de escritura única' USING ERRCODE = '23000';
            END IF;

            -- No se activa un lote sin que CADA código tenga su premio asignado.
            IF OLD.state = 'committed' AND NEW.state = 'active' THEN
                IF (SELECT count(*) FROM codes WHERE batch_id = OLD.id) <> OLD.total_codes THEN
                    RAISE EXCEPTION 'el lote % no tiene todos sus códigos', OLD.id USING ERRCODE = '23000';
                END IF;
                IF EXISTS (SELECT 1 FROM codes WHERE batch_id = OLD.id AND prize_enc IS NULL) THEN
                    RAISE EXCEPTION 'el lote % tiene códigos sin premio asignado', OLD.id USING ERRCODE = '23000';
                END IF;
            END IF;

            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER batches_guard_trg BEFORE UPDATE OR DELETE ON batches
            FOR EACH ROW EXECUTE FUNCTION batches_guard();
        SQL);

        // --- Códigos -------------------------------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION codes_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            b_state text;
            b_total integer;
        BEGIN
            IF TG_OP = 'INSERT' THEN
                SELECT state, total_codes INTO b_state, b_total FROM batches WHERE id = NEW.batch_id;
                IF b_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'solo se agregan códigos a un lote en borrador (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
                IF NEW.idx >= b_total THEN
                    RAISE EXCEPTION 'idx % fuera del rango del lote (% códigos)', NEW.idx, b_total USING ERRCODE = '23000';
                END IF;
                IF NEW.prize_enc IS NOT NULL OR NEW.redeemed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'un código nuevo no puede traer premio ni redención' USING ERRCODE = '23000';
                END IF;
                RETURN NEW;
            END IF;

            IF TG_OP = 'DELETE' THEN
                SELECT state INTO b_state FROM batches WHERE id = OLD.batch_id;
                IF b_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'no se borran códigos de un lote comprometido (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
                RETURN OLD;
            END IF;

            -- UPDATE
            IF NEW.id IS DISTINCT FROM OLD.id OR NEW.batch_id IS DISTINCT FROM OLD.batch_id
               OR NEW.idx IS DISTINCT FROM OLD.idx OR NEW.code_hash IS DISTINCT FROM OLD.code_hash THEN
                RAISE EXCEPTION 'la identidad de un código es inmutable' USING ERRCODE = '23000';
            END IF;

            SELECT state INTO b_state FROM batches WHERE id = OLD.batch_id;

            IF NEW.prize_enc IS DISTINCT FROM OLD.prize_enc THEN
                IF OLD.prize_enc IS NOT NULL THEN
                    RAISE EXCEPTION 'el premio asignado a un código es inmutable' USING ERRCODE = '23000';
                END IF;
                IF b_state <> 'committed' THEN
                    RAISE EXCEPTION 'el premio solo se asigna al activar el lote (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
            END IF;

            IF NEW.redeemed_at IS DISTINCT FROM OLD.redeemed_at OR NEW.redeemed_by IS DISTINCT FROM OLD.redeemed_by THEN
                IF OLD.redeemed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'el código ya fue redimido' USING ERRCODE = '23000';
                END IF;
                IF b_state <> 'active' THEN
                    RAISE EXCEPTION 'solo se redimen códigos de lotes activos (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
            END IF;

            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER codes_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON codes
            FOR EACH ROW EXECUTE FUNCTION codes_guard();
        SQL);

        // --- Premios: la definición no cambia una vez que un lote comprometido la usa ----------------------------
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION prizes_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF EXISTS (
                SELECT 1 FROM batches b
                WHERE b.campaign_id = OLD.campaign_id AND b.state <> 'draft'
                  AND b.pool @> jsonb_build_array(jsonb_build_object('prize_id', OLD.prize_id))
            ) THEN
                RAISE EXCEPTION 'el premio % lo usa un lote comprometido: no se puede modificar ni borrar', OLD.prize_id
                    USING ERRCODE = '23000';
            END IF;
            IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER prizes_guard_trg BEFORE UPDATE OR DELETE ON prizes
            FOR EACH ROW EXECUTE FUNCTION prizes_guard();
        SQL);

        // --- Bitácora: solo se agrega ---------------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            RAISE EXCEPTION 'la bitácora es de solo agregar (% bloqueado)', TG_OP USING ERRCODE = '23000';
        END;
        $fn$;

        CREATE TRIGGER audit_log_no_update_delete BEFORE UPDATE OR DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();
        CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
            FOR EACH STATEMENT EXECUTE FUNCTION audit_log_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS prizes_guard_trg ON prizes;
        DROP TABLE IF EXISTS audit_log;
        DROP TABLE IF EXISTS codes;
        DROP TABLE IF EXISTS batches;
        DROP FUNCTION IF EXISTS audit_log_append_only();
        DROP FUNCTION IF EXISTS prizes_guard();
        DROP FUNCTION IF EXISTS codes_guard();
        DROP FUNCTION IF EXISTS batches_guard();
        SQL);
    }
};
