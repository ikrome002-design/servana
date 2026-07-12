<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive expand (Phase 20B; ADR-004 expand-and-contract): adds a `UNIQUE(id, plan_id)`
 * constraint to the shipped Phase 20A `subscription_plan_prices` table so that Phase 20B
 * tables (`merchant_subscriptions`, `scheduled_plan_changes`, `subscription_invoices`) can
 * enforce **price-belongs-to-plan** at the database level through a composite foreign key
 * `(price_id, plan_id) → subscription_plan_prices(id, plan_id)` — the repository's
 * established composite-key consistency pattern (cf. invoices → clients (id, merchant_id)),
 * not a trigger.
 *
 * `id` is already the primary key (unique); this adds the two-column unique required as the
 * composite-FK target. No shipped migration is edited; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE subscription_plan_prices
             ADD CONSTRAINT subscription_plan_prices_id_plan_id_unique UNIQUE (id, plan_id)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE subscription_plan_prices
             DROP CONSTRAINT IF EXISTS subscription_plan_prices_id_plan_id_unique'
        );
    }
};
