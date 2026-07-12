<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * promotional_discount_targets — explicit normalized target rows for a promotional
 * discount (Plan §53; Phase 20C). NO JSON target lists. Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * PLATFORM-OWNED (TenantOwnership::EXEMPT) — a target may point at a merchant, but the
 * offer itself is platform configuration. Each row sets EXACTLY ONE of `merchant_id` /
 * `subscription_plan_id` / `billing_mode`, matching `target_type` (DB CHECK). The
 * immutable unique `ulid` is the deterministic resolver tie-break key (Gate C1).
 * Duplicate parent/target combinations are rejected by three partial unique indexes
 * (a single composite unique would be defeated by the NULLs). Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_discount_targets', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('promotional_discount_id')->constrained('promotional_discounts')->restrictOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_mode', 56)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('promotional_discount_id', 'promotional_discount_targets_parent_index');
            $table->index('merchant_id', 'promotional_discount_targets_merchant_index');
            $table->index('subscription_plan_id', 'promotional_discount_targets_plan_index');
            $table->index('billing_mode', 'promotional_discount_targets_billing_mode_index');
        });

        DB::statement(
            "ALTER TABLE promotional_discount_targets ADD CONSTRAINT promotional_discount_targets_target_type_check
             CHECK (target_type IN ('merchant','plan','billing_mode'))"
        );
        DB::statement(
            "ALTER TABLE promotional_discount_targets ADD CONSTRAINT promotional_discount_targets_billing_mode_check
             CHECK (billing_mode IS NULL OR billing_mode IN ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'))"
        );
        // Exactly one target field set, matching target_type.
        DB::statement(
            "ALTER TABLE promotional_discount_targets ADD CONSTRAINT promotional_discount_targets_exactly_one_check
             CHECK (
                 (target_type = 'merchant' AND merchant_id IS NOT NULL AND subscription_plan_id IS NULL AND billing_mode IS NULL)
                 OR (target_type = 'plan' AND subscription_plan_id IS NOT NULL AND merchant_id IS NULL AND billing_mode IS NULL)
                 OR (target_type = 'billing_mode' AND billing_mode IS NOT NULL AND merchant_id IS NULL AND subscription_plan_id IS NULL)
             )"
        );

        // Duplicate parent/target rejection — one partial unique per target type.
        DB::statement(
            "CREATE UNIQUE INDEX promotional_discount_targets_unique_merchant
             ON promotional_discount_targets (promotional_discount_id, merchant_id)
             WHERE target_type = 'merchant'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX promotional_discount_targets_unique_plan
             ON promotional_discount_targets (promotional_discount_id, subscription_plan_id)
             WHERE target_type = 'plan'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX promotional_discount_targets_unique_billing_mode
             ON promotional_discount_targets (promotional_discount_id, billing_mode)
             WHERE target_type = 'billing_mode'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_discount_targets');
    }
};
