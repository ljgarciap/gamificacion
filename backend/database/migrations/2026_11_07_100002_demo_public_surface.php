<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Superficie pública de la muestra (SPEC-006 M3): cada código asignado queda ligado a su orden (y no se puede reasignar), la orden guarda la
 * huella de lo pedido (idempotencia: la misma clave con otro pedido es un error de uso), el libro admite el saldo inicial del usuario simulado y
 * las sesiones simuladas se registran para poder reanudarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        ALTER TABLE demo_orders ADD COLUMN request_hash char(64) NOT NULL DEFAULT '';
        ALTER TABLE demo_orders ALTER COLUMN request_hash DROP DEFAULT;

        ALTER TABLE demo_pool ADD COLUMN order_id uuid REFERENCES demo_orders (id);
        CREATE INDEX demo_pool_order_idx ON demo_pool (order_id) WHERE order_id IS NOT NULL;

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

        ALTER TABLE demo_wallet_ledger ALTER COLUMN order_id DROP NOT NULL;
        ALTER TABLE demo_wallet_ledger DROP CONSTRAINT demo_wallet_ledger_kind_check;
        ALTER TABLE demo_wallet_ledger ADD CONSTRAINT demo_wallet_ledger_kind_check CHECK (kind IN ('purchase_credit', 'initial'));
        ALTER TABLE demo_wallet_ledger ADD CONSTRAINT demo_wallet_ledger_order_for_purchase CHECK ((kind = 'purchase_credit') = (order_id IS NOT NULL));

        CREATE TABLE demo_sessions (
            subject    varchar(80) PRIMARY KEY CHECK (subject ~ '^[a-z0-9-]{1,32}\.[0-9a-f]{24}$'),
            ip         varchar(64),
            created_at timestamptz(6) NOT NULL DEFAULT now()
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS demo_sessions;
        ALTER TABLE demo_wallet_ledger DROP CONSTRAINT IF EXISTS demo_wallet_ledger_order_for_purchase;
        DELETE FROM demo_wallet_ledger WHERE kind = 'initial';
        ALTER TABLE demo_wallet_ledger DROP CONSTRAINT IF EXISTS demo_wallet_ledger_kind_check;
        ALTER TABLE demo_wallet_ledger ADD CONSTRAINT demo_wallet_ledger_kind_check CHECK (kind IN ('purchase_credit'));
        ALTER TABLE demo_wallet_ledger ALTER COLUMN order_id SET NOT NULL;
        DROP INDEX IF EXISTS demo_pool_order_idx;
        ALTER TABLE demo_pool DROP COLUMN IF EXISTS order_id;
        ALTER TABLE demo_orders DROP COLUMN IF EXISTS request_hash;
        SQL);
    }
};
