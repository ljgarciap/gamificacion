<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reserva del giro extra (SPEC-003 P6b, docs/seguridad-p6.md). Un lote puede reservar R casillas del mazo (N + R) para los giros
 * hijos. Igual que con los códigos, las invariantes viven en la base:
 *
 *  - la reserva es parte de la definición y se congela al comprometer (`reserve_size`);
 *  - el premio de cada casilla es de escritura única y solo se asigna al activar;
 *  - las casillas se consumen en ORDEN ESTRICTO y sin huecos: el cursor del lote solo avanza si `consumed = idx`, de modo que la
 *    k-ésima redención hija recibe siempre la casilla k, sin importar la carrera (H1: no se usa SKIP LOCKED);
 *  - una redención hija debe tener padre del mismo usuario y lote, y no excede los giros concedidos (H4).
 *
 * Con `reserve_size = 0` (todos los lotes anteriores) nada de esto interviene.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE batches ADD COLUMN reserve_size integer NOT NULL DEFAULT 0 CHECK (reserve_size >= 0 AND reserve_size <= 1000000);

        CREATE TABLE reserve_slots (
            batch_id     uuid NOT NULL REFERENCES batches (id) ON DELETE RESTRICT,
            idx          integer NOT NULL CHECK (idx >= 0),
            -- Cifrado y ligado a (lote, posición GLOBAL N+idx) con un contexto de TIPO distinto al de los códigos (H2).
            prize_enc    bytea,
            consumed_at  timestamptz,
            consumed_by  varchar(190),
            PRIMARY KEY (batch_id, idx),
            CONSTRAINT reserve_slots_consumed_pair CHECK ((consumed_at IS NULL) = (consumed_by IS NULL)),
            CONSTRAINT reserve_slots_consumed_has_prize CHECK (consumed_at IS NULL OR prize_enc IS NOT NULL)
        );

        -- Cuántas casillas se han consumido: serializa el consumo por lote y hace cumplir el orden.
        CREATE TABLE reserve_cursors (
            batch_id  uuid PRIMARY KEY REFERENCES batches (id) ON DELETE RESTRICT,
            consumed  integer NOT NULL DEFAULT 0 CHECK (consumed >= 0)
        );

        ALTER TABLE redemptions
            ALTER COLUMN code_id DROP NOT NULL,
            ADD COLUMN reserve_idx integer,
            ADD COLUMN parent_redemption_id uuid REFERENCES redemptions (id) ON DELETE RESTRICT,
            ADD COLUMN spin_no integer CHECK (spin_no >= 1),
            -- Cuántos giros hijos concede ESTA redención, fijado al canjear (no se relee de la campaña, que puede cambiar).
            ADD COLUMN spins_granted integer NOT NULL DEFAULT 0 CHECK (spins_granted >= 0),
            ADD CONSTRAINT redemptions_one_source CHECK ((code_id IS NULL) <> (reserve_idx IS NULL)),
            ADD CONSTRAINT redemptions_child_shape CHECK (
                (reserve_idx IS NULL) = (parent_redemption_id IS NULL) AND (parent_redemption_id IS NULL) = (spin_no IS NULL)),
            ADD CONSTRAINT redemptions_slot_unique UNIQUE (batch_id, reserve_idx),
            ADD CONSTRAINT redemptions_spin_unique UNIQUE (parent_redemption_id, spin_no),
            ADD CONSTRAINT redemptions_slot_fk FOREIGN KEY (batch_id, reserve_idx) REFERENCES reserve_slots (batch_id, idx) ON DELETE RESTRICT;
        SQL);

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION reserve_slots_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            b_state text;
            b_reserve integer;
            advanced integer;
        BEGIN
            IF TG_OP = 'INSERT' THEN
                SELECT state, reserve_size INTO b_state, b_reserve FROM batches WHERE id = NEW.batch_id;
                IF b_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'solo se agregan casillas de reserva a un lote en borrador (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
                IF NEW.idx >= b_reserve THEN
                    RAISE EXCEPTION 'idx % fuera de la reserva del lote (% casillas)', NEW.idx, b_reserve USING ERRCODE = '23000';
                END IF;
                IF NEW.prize_enc IS NOT NULL OR NEW.consumed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'una casilla nueva no puede traer premio ni consumo' USING ERRCODE = '23000';
                END IF;
                RETURN NEW;
            END IF;

            IF TG_OP = 'DELETE' THEN
                SELECT state INTO b_state FROM batches WHERE id = OLD.batch_id;
                IF b_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'no se borran casillas de reserva de un lote comprometido (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
                RETURN OLD;
            END IF;

            IF NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.idx IS DISTINCT FROM OLD.idx THEN
                RAISE EXCEPTION 'la identidad de una casilla de reserva es inmutable' USING ERRCODE = '23000';
            END IF;
            SELECT state INTO b_state FROM batches WHERE id = OLD.batch_id;

            IF NEW.prize_enc IS DISTINCT FROM OLD.prize_enc THEN
                IF OLD.prize_enc IS NOT NULL THEN
                    RAISE EXCEPTION 'el premio asignado a una casilla de reserva es inmutable' USING ERRCODE = '23000';
                END IF;
                IF b_state <> 'committed' THEN
                    RAISE EXCEPTION 'el premio solo se asigna al activar el lote (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
            END IF;

            IF NEW.consumed_at IS DISTINCT FROM OLD.consumed_at OR NEW.consumed_by IS DISTINCT FROM OLD.consumed_by THEN
                IF OLD.consumed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'la casilla de reserva ya fue consumida' USING ERRCODE = '23000';
                END IF;
                IF b_state <> 'active' THEN
                    RAISE EXCEPTION 'solo se consumen casillas de lotes activos (estado: %)', b_state USING ERRCODE = '23000';
                END IF;
                -- Orden estricto: el cursor solo avanza si su valor es exactamente el índice de esta casilla. Esta sentencia además
                -- bloquea la fila del cursor hasta el final de la transacción, así que dos consumos del mismo lote se serializan.
                UPDATE reserve_cursors SET consumed = consumed + 1 WHERE batch_id = NEW.batch_id AND consumed = NEW.idx;
                GET DIAGNOSTICS advanced = ROW_COUNT;
                IF advanced <> 1 THEN
                    RAISE EXCEPTION 'las casillas de reserva se consumen en orden: la % no es la siguiente', NEW.idx USING ERRCODE = '23000';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER reserve_slots_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON reserve_slots
            FOR EACH ROW EXECUTE FUNCTION reserve_slots_guard();

        CREATE OR REPLACE FUNCTION reserve_cursors_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            b_state text;
            b_reserve integer;
        BEGIN
            SELECT state, reserve_size INTO b_state, b_reserve FROM batches WHERE id = COALESCE(NEW.batch_id, OLD.batch_id);
            IF TG_OP = 'INSERT' THEN
                IF b_state IS DISTINCT FROM 'draft' OR NEW.consumed <> 0 OR b_reserve = 0 THEN
                    RAISE EXCEPTION 'el cursor de reserva nace en 0, en un borrador con reserva' USING ERRCODE = '23000';
                END IF;
                RETURN NEW;
            END IF;
            IF TG_OP = 'DELETE' THEN
                IF b_state IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'no se borra el cursor de un lote comprometido' USING ERRCODE = '23000';
                END IF;
                RETURN OLD;
            END IF;
            IF NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.consumed <> OLD.consumed + 1 OR NEW.consumed > b_reserve THEN
                RAISE EXCEPTION 'el cursor de reserva solo avanza de uno en uno y sin pasar de la reserva' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER reserve_cursors_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON reserve_cursors
            FOR EACH ROW EXECUTE FUNCTION reserve_cursors_guard();

        -- La reserva es parte de lo comprometido; y no se compromete ni se activa un lote con la reserva incompleta.
        CREATE OR REPLACE FUNCTION batches_reserve_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            slots integer;
            unassigned integer;
            cursors integer;
        BEGIN
            IF NEW.reserve_size IS DISTINCT FROM OLD.reserve_size AND OLD.state <> 'draft' THEN
                RAISE EXCEPTION 'la reserva de un lote comprometido es inmutable' USING ERRCODE = '23000';
            END IF;

            IF OLD.state = 'draft' AND NEW.state = 'committed' THEN
                IF (SELECT COALESCE(sum((e ->> 'quantity')::bigint), 0) FROM jsonb_array_elements(NEW.pool) e) <> NEW.total_codes + NEW.reserve_size THEN
                    RAISE EXCEPTION 'el pool debe tener exactamente códigos + reserva posiciones' USING ERRCODE = '23000';
                END IF;
            END IF;

            IF (OLD.state = 'draft' AND NEW.state = 'committed') OR (OLD.state = 'committed' AND NEW.state = 'active') THEN
                SELECT count(*), count(*) FILTER (WHERE prize_enc IS NULL) INTO slots, unassigned FROM reserve_slots WHERE batch_id = OLD.id;
                SELECT count(*) INTO cursors FROM reserve_cursors WHERE batch_id = OLD.id;
                IF slots <> NEW.reserve_size OR cursors <> (CASE WHEN NEW.reserve_size > 0 THEN 1 ELSE 0 END) THEN
                    RAISE EXCEPTION 'el lote % no tiene su reserva completa', OLD.id USING ERRCODE = '23000';
                END IF;
                IF OLD.state = 'committed' AND unassigned > 0 THEN
                    RAISE EXCEPTION 'el lote % tiene casillas de reserva sin premio asignado', OLD.id USING ERRCODE = '23000';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER batches_reserve_guard_trg BEFORE UPDATE ON batches
            FOR EACH ROW EXECUTE FUNCTION batches_reserve_guard();

        CREATE OR REPLACE FUNCTION redemptions_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            c_user text;
            c_batch uuid;
            c_redeemed timestamptz;
            s_user text;
            s_at timestamptz;
            p_user text;
            p_batch uuid;
            p_granted integer;
        BEGIN
            IF TG_OP <> 'INSERT' THEN
                RAISE EXCEPTION 'las redenciones son inmutables (% bloqueado)', TG_OP USING ERRCODE = '23000';
            END IF;

            IF NEW.code_id IS NOT NULL THEN
                SELECT redeemed_by, batch_id, redeemed_at INTO c_user, c_batch, c_redeemed FROM codes WHERE id = NEW.code_id;
                IF c_redeemed IS NULL OR c_user IS DISTINCT FROM NEW.user_id OR c_batch IS DISTINCT FROM NEW.batch_id THEN
                    RAISE EXCEPTION 'la redención no corresponde al código reclamado por ese usuario' USING ERRCODE = '23000';
                END IF;
                RETURN NEW;
            END IF;

            -- Redención hija: la casilla fue consumida por este usuario, y el padre es suyo, del mismo lote y aún le debe giros.
            SELECT consumed_by, consumed_at INTO s_user, s_at FROM reserve_slots WHERE batch_id = NEW.batch_id AND idx = NEW.reserve_idx;
            IF s_at IS NULL OR s_user IS DISTINCT FROM NEW.user_id THEN
                RAISE EXCEPTION 'la redención no corresponde a la casilla de reserva consumida por ese usuario' USING ERRCODE = '23000';
            END IF;
            SELECT user_id, batch_id, spins_granted INTO p_user, p_batch, p_granted FROM redemptions WHERE id = NEW.parent_redemption_id;
            IF NOT FOUND OR p_user IS DISTINCT FROM NEW.user_id OR p_batch IS DISTINCT FROM NEW.batch_id THEN
                RAISE EXCEPTION 'la redención hija no corresponde a una redención padre del mismo usuario y lote' USING ERRCODE = '23000';
            END IF;
            IF NEW.spin_no > p_granted THEN
                RAISE EXCEPTION 'la redención padre solo concede % giros', p_granted USING ERRCODE = '23000';
            END IF;
            IF NEW.spin_no <> (SELECT count(*) FROM redemptions WHERE parent_redemption_id = NEW.parent_redemption_id) + 1 THEN
                RAISE EXCEPTION 'los giros de un padre se numeran en orden' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS batches_reserve_guard_trg ON batches;
        DROP FUNCTION IF EXISTS batches_reserve_guard();
        ALTER TABLE redemptions
            DROP CONSTRAINT IF EXISTS redemptions_slot_fk, DROP CONSTRAINT IF EXISTS redemptions_spin_unique,
            DROP CONSTRAINT IF EXISTS redemptions_slot_unique, DROP CONSTRAINT IF EXISTS redemptions_child_shape,
            DROP CONSTRAINT IF EXISTS redemptions_one_source,
            DROP COLUMN IF EXISTS spins_granted, DROP COLUMN IF EXISTS spin_no,
            DROP COLUMN IF EXISTS parent_redemption_id, DROP COLUMN IF EXISTS reserve_idx;
        DROP TABLE IF EXISTS reserve_cursors;
        DROP TABLE IF EXISTS reserve_slots;
        DROP FUNCTION IF EXISTS reserve_cursors_guard();
        DROP FUNCTION IF EXISTS reserve_slots_guard();
        ALTER TABLE batches DROP COLUMN IF EXISTS reserve_size;
        SQL);
    }
};
