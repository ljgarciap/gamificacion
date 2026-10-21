<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetros administrables (SPEC-003 P4). `settings` guarda solo lo que el superadmin sobrescribió (sin fila = vale el valor por defecto de
 * config). `settings_history` es de SOLO AGREGAR: cada cambio guarda valor anterior, nuevo, actor y hora (RF-47). Los rangos duros viven en el
 * registro de código (`SettingsRegistry`); la base impide además que el historial se altere.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE settings (
            key        varchar(80) PRIMARY KEY,
            value      jsonb       NOT NULL,
            updated_by varchar(80) NOT NULL,
            updated_at timestamptz(6) NOT NULL DEFAULT now()
        );

        CREATE TABLE settings_history (
            id         bigserial PRIMARY KEY,
            key        varchar(80) NOT NULL,
            old_value  jsonb,
            new_value  jsonb,
            actor      varchar(80) NOT NULL,
            changed_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
        );
        CREATE INDEX settings_history_key_idx ON settings_history (key, id DESC);

        CREATE OR REPLACE FUNCTION settings_history_append_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            RAISE EXCEPTION 'el historial de parámetros es de solo agregar' USING ERRCODE = '23000';
        END;
        $fn$;

        CREATE TRIGGER settings_history_append_only_trg BEFORE UPDATE OR DELETE ON settings_history
            FOR EACH ROW EXECUTE FUNCTION settings_history_append_only();
        CREATE TRIGGER settings_history_no_truncate_trg BEFORE TRUNCATE ON settings_history
            FOR EACH STATEMENT EXECUTE FUNCTION settings_history_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS settings_history;
        DROP TABLE IF EXISTS settings;
        DROP FUNCTION IF EXISTS settings_history_append_only();
        SQL);
    }
};
