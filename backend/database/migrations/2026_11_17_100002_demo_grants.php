<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turnos fuera de una compra (SPEC-007 M12, diseno-spec-007 §4): bienvenida y reactivación. La unicidad del turno de bienvenida por cuenta la
 * impone la BASE (índice único parcial), no solo el PHP; la tabla es de solo agregar. `demo_accounts.activity_at` es la «marca de actividad»
 * (última compra, reactivación otorgada o creación): una sola columna decide la reactivación.
 */
return new class extends Migration
{
    private const IMMUTABLE = <<<'SQL'
        CREATE OR REPLACE FUNCTION demo_pool_code_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.code_enc IS DISTINCT FROM OLD.code_enc OR NEW.code_hash IS DISTINCT FROM OLD.code_hash THEN
                RAISE EXCEPTION 'el código del pool de demostración no se modifica' USING ERRCODE = '23000';
            END IF;
            IF NEW.plan IS DISTINCT FROM OLD.plan THEN
                RAISE EXCEPTION 'el plan de un código del pool de demostración no se modifica' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'retired' AND NEW.state <> 'retired' THEN
                RAISE EXCEPTION 'un código retirado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'assigned' AND NEW.state = 'available' THEN
                RAISE EXCEPTION 'un código asignado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            IF OLD.state <> 'available' AND (NEW.assigned_to IS DISTINCT FROM OLD.assigned_to OR NEW.order_id IS DISTINCT FROM OLD.order_id OR NEW.grant_id IS DISTINCT FROM OLD.grant_id) THEN
                RAISE EXCEPTION 'un código asignado no se reasigna' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;
        SQL;

    private const IMMUTABLE_BEFORE = <<<'SQL'
        CREATE OR REPLACE FUNCTION demo_pool_code_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF NEW.batch_id IS DISTINCT FROM OLD.batch_id OR NEW.code_enc IS DISTINCT FROM OLD.code_enc OR NEW.code_hash IS DISTINCT FROM OLD.code_hash THEN
                RAISE EXCEPTION 'el código del pool de demostración no se modifica' USING ERRCODE = '23000';
            END IF;
            IF NEW.plan IS DISTINCT FROM OLD.plan THEN
                RAISE EXCEPTION 'el plan de un código del pool de demostración no se modifica' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'retired' AND NEW.state <> 'retired' THEN
                RAISE EXCEPTION 'un código retirado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            IF OLD.state = 'assigned' AND NEW.state = 'available' THEN
                RAISE EXCEPTION 'un código asignado no vuelve al pool' USING ERRCODE = '23000';
            END IF;
            IF OLD.state <> 'available' AND (NEW.assigned_to IS DISTINCT FROM OLD.assigned_to OR NEW.order_id IS DISTINCT FROM OLD.order_id) THEN
                RAISE EXCEPTION 'un código asignado no se reasigna' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE demo_grants (
            id         bigserial PRIMARY KEY,
            user_id    varchar(160) NOT NULL,
            kind       varchar(12) NOT NULL CHECK (kind IN ('welcome', 'reactivation')),
            created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
        );
        CREATE UNIQUE INDEX demo_grants_one_welcome_idx ON demo_grants (user_id) WHERE kind = 'welcome';
        CREATE INDEX demo_grants_user_idx ON demo_grants (user_id);

        CREATE OR REPLACE FUNCTION demo_grants_append_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            RAISE EXCEPTION 'los turnos otorgados de la demostración son de solo agregar' USING ERRCODE = '23000';
        END;
        $fn$;
        CREATE TRIGGER demo_grants_append_only_trg BEFORE UPDATE OR DELETE ON demo_grants
            FOR EACH ROW EXECUTE FUNCTION demo_grants_append_only();
        CREATE TRIGGER demo_grants_no_truncate_trg BEFORE TRUNCATE ON demo_grants
            FOR EACH STATEMENT EXECUTE FUNCTION demo_grants_append_only();

        ALTER TABLE demo_pool ADD COLUMN grant_id bigint REFERENCES demo_grants (id);
        ALTER TABLE demo_pool ADD CONSTRAINT demo_pool_one_origin CHECK (order_id IS NULL OR grant_id IS NULL);
        CREATE INDEX demo_pool_grant_idx ON demo_pool (grant_id) WHERE grant_id IS NOT NULL;

        ALTER TABLE demo_accounts ADD COLUMN activity_at timestamptz(6) NOT NULL DEFAULT now();
        SQL);
        DB::unprepared(self::IMMUTABLE);
    }

    public function down(): void
    {
        DB::unprepared(self::IMMUTABLE_BEFORE);
        DB::unprepared(<<<'SQL'
        ALTER TABLE demo_accounts DROP COLUMN IF EXISTS activity_at;
        ALTER TABLE demo_pool DROP CONSTRAINT IF EXISTS demo_pool_one_origin;
        DROP INDEX IF EXISTS demo_pool_grant_idx;
        ALTER TABLE demo_pool DROP COLUMN IF EXISTS grant_id;
        DROP TABLE IF EXISTS demo_grants;
        DROP FUNCTION IF EXISTS demo_grants_append_only();
        SQL);
    }
};
