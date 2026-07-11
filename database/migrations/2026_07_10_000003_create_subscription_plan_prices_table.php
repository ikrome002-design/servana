<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_plan_prices — the SOLE plan-price source (Plan §13.9, §47; ADR-011;
 * Phase 20A). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md;
 * lifecycle: docs/architecture/state-machines/plan-price.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). Effective-dated;
 * money is a non-negative integer minor unit; currency uppercase ISO; billing_interval
 * mirrors the BillingInterval enum (BillingEnumParityTest). A btree_gist EXCLUDE constraint
 * is the authoritative guard against overlapping effective ranges per
 * (plan_id, billing_interval, currency) — half-open daterange so adjacent [a,b)/[b,c) ranges
 * are allowed; the application also takes a plan row lock so a concurrent create fails
 * friendly rather than only at the constraint. Historical prices are never destructively
 * edited (a change is a new effective-dated row). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist supplies the GiST opclass for the scalar `=` (bigint plan_id + text
        // billing_interval/currency) used alongside the daterange overlap.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('subscription_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('billing_interval', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['plan_id', 'billing_interval', 'currency', 'effective_from'], 'subscription_plan_prices_resolution_index');
        });

        DB::statement(
            'ALTER TABLE subscription_plan_prices ADD CONSTRAINT subscription_plan_prices_amount_check
             CHECK (amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE subscription_plan_prices ADD CONSTRAINT subscription_plan_prices_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            "ALTER TABLE subscription_plan_prices ADD CONSTRAINT subscription_plan_prices_billing_interval_check
             CHECK (billing_interval IN ('weekly','bi_weekly','monthly','quarterly','annual'))"
        );
        DB::statement(
            'ALTER TABLE subscription_plan_prices ADD CONSTRAINT subscription_plan_prices_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );

        // Authoritative non-overlap guard per (plan, interval, currency). Half-open
        // daterange: adjacent ranges do NOT overlap; a null effective_to is unbounded.
        DB::statement(
            "ALTER TABLE subscription_plan_prices
             ADD CONSTRAINT subscription_plan_prices_no_overlap
             EXCLUDE USING gist (
                 plan_id WITH =,
                 billing_interval WITH =,
                 currency WITH =,
                 daterange(effective_from, effective_to, '[)') WITH &&
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_prices');
    }
};
