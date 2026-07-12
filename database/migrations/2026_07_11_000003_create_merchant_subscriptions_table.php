<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_subscriptions — merchant subscription record lifecycle (Plan §13.9, §22, §25.4,
 * §48; Phase 20B). Merchant-owned (merchant_id, no branch_id — subscriptions are merchant-
 * level). The record `status` is NOT the request-authorization authority; `merchants.
 * billing_status` is, projected transactionally from this row (§22). The ULID is the public
 * route key.
 *
 * Gate B1: `trial_started_at` = the original Merchant-Administrator creation time; the row is
 * created/bound during first-time setup once plan+price are chosen; `trial_days_snapshot`
 * captures the effective platform default at binding and is never rewritten by later settings.
 * See docs/architecture/state-machines/merchant-subscription.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('price_id')->constrained('subscription_plan_prices')->restrictOnDelete();
            $table->string('status', 20)->default('trialing');
            $table->string('billing_interval', 16);
            $table->integer('trial_days_snapshot');
            $table->timestampTz('trial_started_at');
            $table->timestampTz('trial_ends_at');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->bigInteger('high_value_payout_threshold_minor')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->index('merchant_id');
            $table->index('status');
            // Composite-FK target so child tables (scheduled_plan_changes,
            // billing_escalation_events) can enforce tenant consistency.
            $table->unique(['id', 'merchant_id'], 'merchant_subscriptions_id_merchant_id_unique');
        });

        // Literal values (parity with MerchantSubscriptionStatus/BillingInterval guarded by
        // Phase20BEnumParityTest).
        DB::statement(
            "ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_status_check
             CHECK (status IN ('trialing','active','read_only_grace','overdue','suspended_billing','cancelled','expired'))"
        );

        DB::statement(
            "ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_billing_interval_check
             CHECK (billing_interval IN ('weekly','bi_weekly','monthly','quarterly','annual'))"
        );

        DB::statement(
            'ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_trial_days_snapshot_check
             CHECK (trial_days_snapshot >= 0)'
        );
        DB::statement(
            'ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_trial_dates_check
             CHECK (trial_ends_at >= trial_started_at)'
        );
        DB::statement(
            'ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_period_dates_check
             CHECK (current_period_end > current_period_start)'
        );
        DB::statement(
            'ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_high_value_threshold_check
             CHECK (high_value_payout_threshold_minor IS NULL OR high_value_payout_threshold_minor >= 0)'
        );

        // Price belongs to plan (DB-authoritative composite FK; the repository's
        // established consistency pattern, not a trigger).
        DB::statement(
            'ALTER TABLE merchant_subscriptions
             ADD CONSTRAINT merchant_subscriptions_price_plan_foreign
             FOREIGN KEY (price_id, plan_id)
             REFERENCES subscription_plan_prices (id, plan_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // One current NON-TERMINAL subscription per merchant (terminal cancelled/expired
        // history retained). Literal non-terminal set (parity guarded by Phase20BEnumParityTest
        // + Phase20BSchemaTest one-current-subscription case).
        DB::statement(
            "CREATE UNIQUE INDEX merchant_subscriptions_one_current_per_merchant
             ON merchant_subscriptions (merchant_id)
             WHERE status IN ('trialing','active','read_only_grace','overdue','suspended_billing')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_subscriptions');
    }
};
