<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Planes de premio de la muestra (SPEC-007 M11, diseno-spec-007 §4). Un plan es una etiqueta sobre un conjunto de códigos del pool: `plan` NULL
 * es el modo anterior («steps»). La etiqueta nunca cambia una vez puesta (la impone el trigger, no solo PHP): si se pudiera mover un código de
 * plan se rompería la probabilidad exacta que el panel muestra.
 */
return new class extends Migration
{
    private const IMMUTABLE_WITH_PLAN = <<<'SQL'
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

    private const IMMUTABLE_BEFORE = <<<'SQL'
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
        ALTER TABLE demo_pool ADD COLUMN plan varchar(32) CHECK (plan IS NULL OR plan ~ '^[a-z0-9_-]{1,32}$');
        CREATE INDEX demo_pool_available_plan_idx ON demo_pool (plan, id) WHERE state = 'available';
        SQL);
        DB::unprepared(self::IMMUTABLE_WITH_PLAN);
    }

    public function down(): void
    {
        DB::unprepared(self::IMMUTABLE_BEFORE);
        DB::unprepared(<<<'SQL'
        DROP INDEX IF EXISTS demo_pool_available_plan_idx;
        ALTER TABLE demo_pool DROP COLUMN IF EXISTS plan;
        SQL);
    }
};
