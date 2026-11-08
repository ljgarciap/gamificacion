<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPEC-006A — Cuentas persistentes de la muestra. La cuenta es solo la LLAVE a un `subject` que ya existe (saldo, pedidos, códigos y barrera de
 * canje siguen ligados a `emisor:subject`). El correo se guarda cifrado (SecretBox) y con una huella HMAC para buscarlo; borrar la cuenta destruye
 * ambos y deja el saldo huérfano e inalcanzable (el libro de saldo sigue siendo de solo agregar).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE demo_accounts (
            id            uuid PRIMARY KEY,
            email_hash    bytea NOT NULL UNIQUE CHECK (octet_length(email_hash) = 32),
            email_enc     bytea NOT NULL,
            subject       varchar(80) NOT NULL UNIQUE REFERENCES demo_sessions(subject),
            created_at    timestamptz(6) NOT NULL DEFAULT now(),
            last_login_at timestamptz(6) NOT NULL DEFAULT now()
        );
        CREATE INDEX demo_accounts_last_login_idx ON demo_accounts (last_login_at);

        -- Códigos de ingreso: se guarda HMAC(pepper, código), nunca el código. Un solo código ACTIVO por correo.
        CREATE TABLE demo_login_codes (
            id          bigserial PRIMARY KEY,
            email_hash  bytea NOT NULL CHECK (octet_length(email_hash) = 32),
            code_hash   bytea NOT NULL CHECK (octet_length(code_hash) = 32),
            attempts    smallint NOT NULL DEFAULT 0,
            created_at  timestamptz(6) NOT NULL DEFAULT now(),
            expires_at  timestamptz(6) NOT NULL,
            consumed_at timestamptz(6)
        );
        CREATE UNIQUE INDEX demo_login_codes_one_active_idx ON demo_login_codes (email_hash) WHERE consumed_at IS NULL;
        CREATE INDEX demo_login_codes_expires_idx ON demo_login_codes (expires_at);

        -- Registro de envíos para los límites (por correo, por IP y global). Se purga solo.
        CREATE TABLE demo_mail_log (
            id         bigserial PRIMARY KEY,
            email_hash bytea NOT NULL,
            ip         varchar(64),
            created_at timestamptz(6) NOT NULL DEFAULT now()
        );
        CREATE INDEX demo_mail_log_email_idx ON demo_mail_log (email_hash, created_at);
        CREATE INDEX demo_mail_log_ip_idx ON demo_mail_log (ip, created_at);
        CREATE INDEX demo_mail_log_created_idx ON demo_mail_log (created_at);

        -- «Reiniciar» pide además qué hacer con las cuentas ('keep' | 'delete').
        ALTER TABLE demo_runs ADD COLUMN options jsonb NOT NULL DEFAULT '{}'::jsonb;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE demo_runs DROP COLUMN IF EXISTS options;
        DROP TABLE IF EXISTS demo_mail_log;
        DROP TABLE IF EXISTS demo_login_codes;
        DROP TABLE IF EXISTS demo_accounts;
        SQL);
    }
};
