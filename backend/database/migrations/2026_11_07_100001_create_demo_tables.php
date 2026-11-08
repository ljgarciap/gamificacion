<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Muestra real / modo demo (SPEC-006, diseno-spec-006 A2). Todo vive aparte del núcleo: `demo_pool` guarda los códigos en claro CIFRADOS y solo
 * puede apuntar a lotes de campañas `is_demo` (lo impone un trigger, no solo el código PHP); el libro de saldo es de solo agregar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE campaigns ADD COLUMN is_demo boolean NOT NULL DEFAULT false;

        CREATE TABLE demo_config (
            id            smallint PRIMARY KEY CHECK (id = 1),
            enabled       boolean NOT NULL DEFAULT false,
            enabled_until timestamptz(6),
            config        jsonb CHECK (config IS NULL OR jsonb_typeof(config) = 'object'),
            version       integer NOT NULL DEFAULT 0 CHECK (version >= 0),
            updated_by    varchar(80),
            updated_at    timestamptz(6) NOT NULL DEFAULT now(),
            CHECK (NOT enabled OR config IS NOT NULL)
        );
        INSERT INTO demo_config (id) VALUES (1);

        CREATE TABLE demo_pool (
            id          bigserial PRIMARY KEY,
            batch_id    uuid NOT NULL REFERENCES batches (id) ON DELETE RESTRICT,
            code_enc    bytea NOT NULL,
            code_hash   bytea NOT NULL UNIQUE,
            game        varchar(40) NOT NULL,
            description varchar(160) NOT NULL,
            state       varchar(10) NOT NULL DEFAULT 'available' CHECK (state IN ('available', 'assigned', 'retired')),
            assigned_to varchar(160),
            assigned_at timestamptz(6),
            generation  integer NOT NULL DEFAULT 1 CHECK (generation >= 1),
            created_at  timestamptz(6) NOT NULL DEFAULT now(),
            CHECK (state <> 'available' OR assigned_to IS NULL),
            CHECK (state <> 'assigned' OR (assigned_to IS NOT NULL AND assigned_at IS NOT NULL))
        );
        CREATE INDEX demo_pool_available_idx ON demo_pool (id) WHERE state = 'available';

        CREATE OR REPLACE FUNCTION demo_pool_only_demo_batches() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM batches b JOIN campaigns c ON c.id = b.campaign_id WHERE b.id = NEW.batch_id AND c.is_demo) THEN
                RAISE EXCEPTION 'demo_pool solo admite lotes de campañas de demostración' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;
        CREATE TRIGGER demo_pool_only_demo_batches_trg BEFORE INSERT ON demo_pool
            FOR EACH ROW EXECUTE FUNCTION demo_pool_only_demo_batches();

        CREATE OR REPLACE FUNCTION demo_pool_code_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.code_enc IS DISTINCT FROM OLD.code_enc OR NEW.code_hash IS DISTINCT FROM OLD.code_hash THEN
                RAISE EXCEPTION 'el código del pool de demostración no se modifica' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'retired' AND NEW.state <> 'retired' THEN
                RAISE EXCEPTION 'un código retirado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'assigned' AND NEW.state = 'available' THEN
                RAISE EXCEPTION 'un código asignado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;
        CREATE TRIGGER demo_pool_code_immutable_trg BEFORE UPDATE ON demo_pool
            FOR EACH ROW EXECUTE FUNCTION demo_pool_code_immutable();

        CREATE TABLE demo_orders (
            id              uuid PRIMARY KEY,
            user_id         varchar(160) NOT NULL,
            items           jsonb NOT NULL,
            total           bigint NOT NULL CHECK (total > 0),
            credited        bigint NOT NULL CHECK (credited >= 0),
            codes_granted   integer NOT NULL CHECK (codes_granted >= 0),
            idempotency_key varchar(80) NOT NULL,
            created_at      timestamptz(6) NOT NULL DEFAULT now(),
            UNIQUE (user_id, idempotency_key)
        );

        CREATE TABLE demo_wallet_ledger (
            id         bigserial PRIMARY KEY,
            user_id    varchar(160) NOT NULL,
            kind       varchar(20) NOT NULL CHECK (kind IN ('purchase_credit')),
            amount     bigint NOT NULL CHECK (amount > 0),
            order_id   uuid NOT NULL REFERENCES demo_orders (id),
            created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
        );
        CREATE INDEX demo_wallet_ledger_user_idx ON demo_wallet_ledger (user_id);

        CREATE OR REPLACE FUNCTION demo_ledger_append_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            RAISE EXCEPTION 'el libro de saldo de la demostración es de solo agregar' USING ERRCODE = '23000';
        END;
        $fn$;
        CREATE TRIGGER demo_ledger_append_only_trg BEFORE UPDATE OR DELETE ON demo_wallet_ledger
            FOR EACH ROW EXECUTE FUNCTION demo_ledger_append_only();
        CREATE TRIGGER demo_ledger_no_truncate_trg BEFORE TRUNCATE ON demo_wallet_ledger
            FOR EACH STATEMENT EXECUTE FUNCTION demo_ledger_append_only();

        CREATE TABLE demo_runs (
            id           bigserial PRIMARY KEY,
            kind         varchar(10) NOT NULL CHECK (kind IN ('seed', 'reset')),
            state        varchar(10) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'running', 'done', 'failed')),
            error        text,
            requested_by varchar(80) NOT NULL,
            created_at   timestamptz(6) NOT NULL DEFAULT now(),
            started_at   timestamptz(6),
            finished_at  timestamptz(6)
        );
        CREATE UNIQUE INDEX demo_runs_single_active_idx ON demo_runs ((true)) WHERE state IN ('pending', 'running');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS demo_runs;
        DROP TABLE IF EXISTS demo_wallet_ledger;
        DROP TABLE IF EXISTS demo_orders;
        DROP TABLE IF EXISTS demo_pool;
        DROP TABLE IF EXISTS demo_config;
        ALTER TABLE campaigns DROP COLUMN IF EXISTS is_demo;
        DROP FUNCTION IF EXISTS demo_pool_only_demo_batches();
        DROP FUNCTION IF EXISTS demo_pool_code_immutable();
        DROP FUNCTION IF EXISTS demo_ledger_append_only();
        SQL);
    }
};
