<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan_entitlements — per-plan entitlement limits; the Plan §20 resolver/gate substrate
 * (Plan §13.9, §20, §47; Phase 20A). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). The merchant→plan
 * binding is Phase 20B (merchant_subscriptions); the 20A resolver takes the plan through an
 * explicit interface and fabricates no subscription rows. unique(plan_id, entitlement_key);
 * limit_int null = unlimited when enabled. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('entitlement_key');
            $table->integer('limit_int')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestampsTz();

            $table->unique(['plan_id', 'entitlement_key'], 'plan_entitlements_plan_key_unique');
            $table->index('plan_id', 'plan_entitlements_plan_index');
        });

        DB::statement(
            'ALTER TABLE plan_entitlements ADD CONSTRAINT plan_entitlements_limit_check
             CHECK (limit_int IS NULL OR limit_int >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
