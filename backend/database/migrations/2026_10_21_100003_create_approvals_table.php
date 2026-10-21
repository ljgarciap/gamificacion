<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Control dual (SPEC-003 P3, RF-43/44, amenazas T11–T15). Las invariantes viven en la base: el aprobador NO puede ser el solicitante, los
 * parámetros aprobados son inmutables, aprobación y ejecución son write-once, el estado solo avanza, nada se borra, y hay a lo sumo UNA
 * solicitud abierta por acción y objeto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE approvals (
            id           bigserial PRIMARY KEY,
            action       varchar(40) NOT NULL CHECK (action IN ('batch.activate', 'codes.generate', 'audit.export', 'setting.change')),
            subject_type varchar(40) NOT NULL,
            subject_id   varchar(120) NOT NULL,
            params       jsonb NOT NULL,
            status       varchar(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'executed', 'expired')),
            requested_by bigint NOT NULL REFERENCES admin_users (id),
            requested_at timestamptz(6) NOT NULL DEFAULT now(),
            expires_at   timestamptz(6) NOT NULL,
            approved_by  bigint REFERENCES admin_users (id),
            approved_at  timestamptz(6),
            executed_at  timestamptz(6),
            CONSTRAINT approvals_no_self_approval CHECK (approved_by IS NULL OR approved_by <> requested_by),
            CONSTRAINT approvals_approved_has_approver CHECK (status NOT IN ('approved', 'executed') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
            CONSTRAINT approvals_executed_consistent CHECK ((status = 'executed') = (executed_at IS NOT NULL))
        );
        CREATE UNIQUE INDEX approvals_one_open ON approvals (action, subject_type, subject_id) WHERE status IN ('pending', 'approved');

        CREATE OR REPLACE FUNCTION approvals_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                RAISE EXCEPTION 'las aprobaciones no se borran' USING ERRCODE = '23000';
            END IF;
            IF NEW.action <> OLD.action OR NEW.subject_type <> OLD.subject_type OR NEW.subject_id <> OLD.subject_id
               OR NEW.params <> OLD.params OR NEW.requested_by <> OLD.requested_by OR NEW.requested_at <> OLD.requested_at
               OR NEW.expires_at <> OLD.expires_at THEN
                RAISE EXCEPTION 'los datos de una aprobación son inmutables' USING ERRCODE = '23000';
            END IF;
            IF OLD.approved_by IS NOT NULL AND (NEW.approved_by IS DISTINCT FROM OLD.approved_by OR NEW.approved_at IS DISTINCT FROM OLD.approved_at) THEN
                RAISE EXCEPTION 'la aprobación es write-once' USING ERRCODE = '23000';
            END IF;
            IF OLD.executed_at IS NOT NULL AND NEW.executed_at IS DISTINCT FROM OLD.executed_at THEN
                RAISE EXCEPTION 'la ejecución es write-once' USING ERRCODE = '23000';
            END IF;
            IF NEW.status <> OLD.status AND NOT (
                (OLD.status = 'pending' AND NEW.status IN ('approved', 'expired'))
                OR (OLD.status = 'approved' AND NEW.status IN ('executed', 'expired'))) THEN
                RAISE EXCEPTION 'transición de estado no permitida' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER approvals_guard_trg BEFORE UPDATE OR DELETE ON approvals FOR EACH ROW EXECUTE FUNCTION approvals_guard();
        CREATE TRIGGER approvals_no_truncate_trg BEFORE TRUNCATE ON approvals FOR EACH STATEMENT EXECUTE FUNCTION approvals_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS approvals;
        DROP FUNCTION IF EXISTS approvals_guard();
        SQL);
    }
};
