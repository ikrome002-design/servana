<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_plans — platform-scoped plan catalogue, NON-PRICE metadata only
 * (Plan §13.9, §47; ADR-011; Phase 20A). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/subscription-plan.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). There is NO
 * monetary/price column here — price is the sole responsibility of
 * subscription_plan_prices (ADR-011). status ∈ (active, retired); retirement is
 * non-destructive (prices + entitlements preserved). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('tier')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('status', 16)->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['status', 'sort_order'], 'subscription_plans_status_sort_index');
        });

        DB::statement(
            "ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_status_check
             CHECK (status IN ('active','retired'))"
        );
        DB::statement(
            "ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_metadata_object_check
             CHECK (jsonb_typeof(metadata) = 'object')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
