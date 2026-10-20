<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campañas y definición de premios. SQL explícito (no el Schema builder) porque las restricciones son parte del diseño.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE campaigns (
            id                  uuid PRIMARY KEY,
            name                varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
            game_type           varchar(40)  NOT NULL CHECK (game_type ~ '^[a-z][a-z0-9_]*$'),
            used_code_behavior  varchar(20)  NOT NULL DEFAULT 'rejectOnly'
                                CHECK (used_code_behavior IN ('showPrevious', 'rejectOnly')),
            title               varchar(120),
            theme               jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(theme) = 'object'),
            game_config         jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(game_config) = 'object'),
            created_at          timestamptz NOT NULL DEFAULT now(),
            updated_at          timestamptz NOT NULL DEFAULT now()
        );

        CREATE TABLE prizes (
            campaign_id  uuid         NOT NULL REFERENCES campaigns (id) ON DELETE RESTRICT,
            prize_id     varchar(64)  NOT NULL CHECK (length(prize_id) > 0),
            type         varchar(20)  NOT NULL CHECK (type IN ('money', 'coupon', 'points', 'extraSpin', 'none')),
            label        varchar(120) NOT NULL CHECK (length(btrim(label)) > 0),
            amount       bigint       CHECK (amount >= 0),
            meta         jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(meta) = 'object'),
            created_at   timestamptz NOT NULL DEFAULT now(),
            updated_at   timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (campaign_id, prize_id),
            -- dinero y puntos sin monto no tienen sentido
            CHECK (type NOT IN ('money', 'points') OR amount IS NOT NULL)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS prizes; DROP TABLE IF EXISTS campaigns;');
    }
};
