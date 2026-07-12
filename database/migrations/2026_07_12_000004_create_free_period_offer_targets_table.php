<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * free_period_offer_targets — explicit normalized target rows for a free-period offer
 * (Plan §53; Phase 20C). Same structure and protections as
 * promotional_discount_targets, parented by free_period_offer_id. NO JSON targets.
 * Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * PLATFORM-OWNED (TenantOwnership::EXEMPT). Exactly one of merchant_id /
 * subscription_plan_id / billing_mode set, matching target_type (DB CHECK). Immutable
 * unique `ulid` tie-break key. Duplicate parent/target rejected by three partial unique
 * indexes. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_period_offer_targets', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('free_period_offer_id')->constrained('free_period_offers')->restrictOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_mode', 56)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('free_period_offer_id', 'free_period_offer_targets_parent_index');
            $table->index('merchant_id', 'free_period_offer_targets_merchant_index');
            $table->index('subscription_plan_id', 'free_period_offer_targets_plan_index');
            $table->index('billing_mode', 'free_period_offer_targets_billing_mode_index');
        });

        DB::statement(
            "ALTER TABLE free_period_offer_targets ADD CONSTRAINT free_period_offer_targets_target_type_check
             CHECK (target_type IN ('merchant','plan','billing_mode'))"
        );
        DB::statement(
            "ALTER TABLE free_period_offer_targets ADD CONSTRAINT free_period_offer_targets_billing_mode_check
             CHECK (billing_mode IS NULL OR billing_mode IN ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'))"
        );
        DB::statement(
            "ALTER TABLE free_period_offer_targets ADD CONSTRAINT free_period_offer_targets_exactly_one_check
             CHECK (
                 (target_type = 'merchant' AND merchant_id IS NOT NULL AND subscription_plan_id IS NULL AND billing_mode IS NULL)
                 OR (target_type = 'plan' AND subscription_plan_id IS NOT NULL AND merchant_id IS NULL AND billing_mode IS NULL)
                 OR (target_type = 'billing_mode' AND billing_mode IS NOT NULL AND merchant_id IS NULL AND subscription_plan_id IS NULL)
             )"
        );

        DB::statement(
            "CREATE UNIQUE INDEX free_period_offer_targets_unique_merchant
             ON free_period_offer_targets (free_period_offer_id, merchant_id)
             WHERE target_type = 'merchant'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX free_period_offer_targets_unique_plan
             ON free_period_offer_targets (free_period_offer_id, subscription_plan_id)
             WHERE target_type = 'plan'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX free_period_offer_targets_unique_billing_mode
             ON free_period_offer_targets (free_period_offer_id, billing_mode)
             WHERE target_type = 'billing_mode'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('free_period_offer_targets');
    }
};
