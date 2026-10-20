<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Redenciones y outbox de acreditación (SPEC-002 §5, RF-24, CA-25, CA-26).
 *
 * Una redención es un registro INMUTABLE: ya hay un código reclamado atómicamente (codes.redeemed_at, de escritura única) y esta
 * tabla guarda exactamente lo que se le respondió al usuario, para repetirlo idéntico ante un reintento. La base garantiza:
 *  - un código solo puede tener UNA redención (code_id único);
 *  - (usuario, Idempotency-Key) es único: el mismo reintento nunca crea una segunda;
 *  - una redención solo existe si ESE usuario reclamó ESE código (trigger de integridad cruzada).
 *
 * La outbox registra, en la MISMA transacción que la redención, el aviso de acreditación al host: o existen ambos o ninguno,
 * y hay a lo sumo un aviso por redención (redemption_id único), así que un reintento nunca acredita dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE redemptions (
            id               uuid PRIMARY KEY,
            code_id          bigint NOT NULL REFERENCES codes (id) ON DELETE RESTRICT,
            batch_id         uuid   NOT NULL REFERENCES batches (id) ON DELETE RESTRICT,
            user_id          varchar(190) NOT NULL CHECK (length(user_id) > 0),
            idempotency_key  varchar(128) NOT NULL CHECK (length(idempotency_key) BETWEEN 8 AND 128),
            prize_id         varchar(64)  NOT NULL CHECK (length(prize_id) > 0),
            -- Exactamente lo que se respondió: así un reintento devuelve lo mismo aunque la campaña cambie después.
            -- Es `json` y NO `jsonb` A PROPÓSITO: jsonb normaliza (reordena claves, agrega espacios) y el reintento ya no sería
            -- idéntico byte a byte a la primera respuesta.
            response         json NOT NULL CHECK (json_typeof(response) = 'object'),
            created_at       timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT redemptions_code_unique UNIQUE (code_id),
            CONSTRAINT redemptions_user_key_unique UNIQUE (user_id, idempotency_key)
        );
        CREATE INDEX redemptions_user_idx ON redemptions (user_id);
        CREATE INDEX redemptions_batch_idx ON redemptions (batch_id);

        CREATE TABLE prize_outbox (
            id               bigserial PRIMARY KEY,
            redemption_id    uuid NOT NULL REFERENCES redemptions (id) ON DELETE RESTRICT,
            payload          jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
            status           varchar(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed')),
            attempts         integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
            next_attempt_at  timestamptz NOT NULL DEFAULT now(),
            last_error       text,
            created_at       timestamptz NOT NULL DEFAULT now(),
            sent_at          timestamptz,
            CONSTRAINT prize_outbox_redemption_unique UNIQUE (redemption_id),
            CONSTRAINT prize_outbox_sent_consistent CHECK ((status = 'sent') = (sent_at IS NOT NULL))
        );
        -- Índice parcial: el despachador solo mira lo pendiente.
        CREATE INDEX prize_outbox_due_idx ON prize_outbox (next_attempt_at) WHERE status = 'pending';
        SQL);

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION redemptions_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        DECLARE
            c_user text;
            c_batch uuid;
            c_redeemed timestamptz;
        BEGIN
            IF TG_OP <> 'INSERT' THEN
                RAISE EXCEPTION 'las redenciones son inmutables (% bloqueado)', TG_OP USING ERRCODE = '23000';
            END IF;

            SELECT redeemed_by, batch_id, redeemed_at INTO c_user, c_batch, c_redeemed FROM codes WHERE id = NEW.code_id;
            IF c_redeemed IS NULL OR c_user IS DISTINCT FROM NEW.user_id OR c_batch IS DISTINCT FROM NEW.batch_id THEN
                RAISE EXCEPTION 'la redención no corresponde al código reclamado por ese usuario' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER redemptions_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON redemptions
            FOR EACH ROW EXECUTE FUNCTION redemptions_guard();

        CREATE OR REPLACE FUNCTION prize_outbox_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'la outbox no se borra' USING ERRCODE = '23000';
            END IF;
            IF TG_OP = 'UPDATE' THEN
                IF NEW.redemption_id IS DISTINCT FROM OLD.redemption_id OR NEW.payload IS DISTINCT FROM OLD.payload
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'el aviso de acreditación es inmutable: solo cambia su estado de entrega' USING ERRCODE = '23000';
                END IF;
                IF OLD.status = 'sent' AND (NEW.status <> 'sent' OR NEW.sent_at IS DISTINCT FROM OLD.sent_at) THEN
                    RAISE EXCEPTION 'un aviso ya entregado no se puede modificar' USING ERRCODE = '23000';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER prize_outbox_guard_trg BEFORE UPDATE OR DELETE ON prize_outbox
            FOR EACH ROW EXECUTE FUNCTION prize_outbox_guard();

        -- Las reglas de juego de una campaña se congelan en cuanto un lote suyo se compromete: cambiar la ruleta, el tipo de
        -- juego o qué pasa con un código usado a mitad de campaña alteraría lo prometido y lo que se validó al comprometer.
        -- (El tema y el título sí se pueden ajustar.)
        CREATE OR REPLACE FUNCTION campaigns_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF (NEW.game_type IS DISTINCT FROM OLD.game_type OR NEW.game_config IS DISTINCT FROM OLD.game_config
                OR NEW.used_code_behavior IS DISTINCT FROM OLD.used_code_behavior)
               AND EXISTS (SELECT 1 FROM batches WHERE campaign_id = OLD.id AND state <> 'draft') THEN
                RAISE EXCEPTION 'la campaña % tiene un lote comprometido: sus reglas de juego son inmutables', OLD.id
                    USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER campaigns_guard_trg BEFORE UPDATE ON campaigns
            FOR EACH ROW EXECUTE FUNCTION campaigns_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS campaigns_guard_trg ON campaigns;
        DROP FUNCTION IF EXISTS campaigns_guard();
        DROP TABLE IF EXISTS prize_outbox;
        DROP TABLE IF EXISTS redemptions;
        DROP FUNCTION IF EXISTS prize_outbox_guard();
        DROP FUNCTION IF EXISTS redemptions_guard();
        SQL);
    }
};
