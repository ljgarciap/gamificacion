<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Apps host registradas (SPEC-003 RF-49, amenazas T18–T20). `issuer` es el mismo emisor del JWT (prefijo de `user_id`) y no cambia nunca.
 * Los secretos se guardan CIFRADOS (SecretBox, ligados a la fila) y nunca salen por la API; durante una rotación conviven el actual y el anterior.
 * Un host no se borra: se desactiva (los avisos pendientes de sus usuarios necesitan seguir encontrándolo).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE hosts (
            id                  bigserial PRIMARY KEY,
            issuer              varchar(190) NOT NULL CHECK (length(issuer) > 0 AND issuer !~ '[[:cntrl:][:space:]]'),
            callback_url        varchar(2048) NOT NULL CHECK (callback_url <> ''),
            balance_url         varchar(2048) NOT NULL CHECK (balance_url <> ''),
            secret_current_enc  bytea NOT NULL CHECK (octet_length(secret_current_enc) > 40),
            secret_previous_enc bytea CHECK (octet_length(secret_previous_enc) > 40),
            active              boolean NOT NULL DEFAULT true,
            created_at          timestamptz(6) NOT NULL DEFAULT now(),
            updated_at          timestamptz(6) NOT NULL DEFAULT now(),
            CONSTRAINT hosts_issuer_unique UNIQUE (issuer)
        );

        CREATE OR REPLACE FUNCTION hosts_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                RAISE EXCEPTION 'un host no se borra: se desactiva' USING ERRCODE = '23000';
            END IF;
            IF NEW.issuer IS DISTINCT FROM OLD.issuer THEN
                RAISE EXCEPTION 'el emisor de un host no cambia' USING ERRCODE = '23000';
            END IF;
            RETURN NEW;
        END;
        $fn$;

        CREATE TRIGGER hosts_guard_trg BEFORE UPDATE OR DELETE ON hosts FOR EACH ROW EXECUTE FUNCTION hosts_guard();
        CREATE TRIGGER hosts_no_truncate_trg BEFORE TRUNCATE ON hosts FOR EACH STATEMENT EXECUTE FUNCTION hosts_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS hosts; DROP FUNCTION IF EXISTS hosts_guard();');
    }
};
