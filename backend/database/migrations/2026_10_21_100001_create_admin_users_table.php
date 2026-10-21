<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Usuarios del panel de administración (SPEC-003 P1). Las invariantes viven en la base (como en SPEC-002): el rol es uno de los tres, el correo
 * va en minúsculas y sin duplicados, un TOTP confirmado siempre tiene secreto, y NUNCA puede quedar el sistema sin un superadmin activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE admin_users (
            id                   bigserial PRIMARY KEY,
            email                varchar(190) NOT NULL,
            name                 varchar(120) NOT NULL,
            role                 varchar(20)  NOT NULL CHECK (role IN ('superadmin', 'operator', 'auditor')),
            password_hash        varchar(255) NOT NULL,
            must_change_password boolean      NOT NULL DEFAULT true,
            totp_secret_enc      bytea,
            totp_confirmed_at    timestamptz(6),
            totp_last_step       bigint,
            recovery_codes       jsonb        NOT NULL DEFAULT '[]'::jsonb,
            failed_attempts      integer      NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
            locked_until         timestamptz(6),
            active               boolean      NOT NULL DEFAULT true,
            last_login_at        timestamptz(6),
            remember_token       varchar(100), -- lo rota el guardia de Laravel al cerrar sesión; el panel NO ofrece "recordarme"
            created_at           timestamptz(6) NOT NULL DEFAULT now(),
            updated_at           timestamptz(6) NOT NULL DEFAULT now(),
            CONSTRAINT admin_users_email_unique UNIQUE (email),
            CONSTRAINT admin_users_email_lower  CHECK (email = lower(email)),
            CONSTRAINT admin_users_totp_has_secret CHECK (totp_confirmed_at IS NULL OR totp_secret_enc IS NOT NULL)
        );

        CREATE OR REPLACE FUNCTION admin_users_keep_a_superadmin() RETURNS trigger LANGUAGE plpgsql AS $fn$
        BEGIN
            -- Quien deja de ser superadmin activo (se desactiva, cambia de rol o se borra) no puede ser el último.
            IF OLD.role = 'superadmin' AND OLD.active
               AND (TG_OP = 'DELETE' OR NEW.role <> 'superadmin' OR NOT NEW.active)
               AND NOT EXISTS (SELECT 1 FROM admin_users WHERE role = 'superadmin' AND active AND id <> OLD.id) THEN
                RAISE EXCEPTION 'debe quedar al menos un superadmin activo' USING ERRCODE = '23000';
            END IF;
            RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
        END;
        $fn$;

        CREATE TRIGGER admin_users_keep_a_superadmin_trg BEFORE UPDATE OR DELETE ON admin_users
            FOR EACH ROW EXECUTE FUNCTION admin_users_keep_a_superadmin();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS admin_users;
        DROP FUNCTION IF EXISTS admin_users_keep_a_superadmin();
        SQL);
    }
};
