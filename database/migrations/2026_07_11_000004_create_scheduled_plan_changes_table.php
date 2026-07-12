<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * scheduled_plan_changes — no-proration next-cycle plan changes (Plan §13.9, §48; Phase 20B).
 * Merchant-owned. Applied only at the next cycle boundary; `applied`/`cancelled` history is
 * retained (immutable). Target price belongs to target plan (composite FK). At most one
 * scheduled change per (subscription, effective boundary). See
 * docs/architecture/state-machines/scheduled-plan-change.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_plan_changes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('merchant_subscription_id')->constrained('merchant_subscriptions')->restrictOnDelete();
            $table->foreignId('target_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('target_price_id')->constrained('subscription_plan_prices')->restrictOnDelete();
            $table->date('effective_at');
            $table->string('status', 16)->default('scheduled');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('merchant_id');
            $table->index(['merchant_subscription_id', 'status']);
        });

        // Literal values (parity with ScheduledPlanChangeStatus guarded by Phase20BEnumParityTest).
        DB::statement(
            "ALTER TABLE scheduled_plan_changes
             ADD CONSTRAINT scheduled_plan_changes_status_check
             CHECK (status IN ('scheduled','applied','cancelled'))"
        );

        // Tenant consistency: the subscription belongs to the same merchant.
        DB::statement(
            'ALTER TABLE scheduled_plan_changes
             ADD CONSTRAINT scheduled_plan_changes_subscription_merchant_foreign
             FOREIGN KEY (merchant_subscription_id, merchant_id)
             REFERENCES merchant_subscriptions (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // Target price belongs to target plan (DB-authoritative composite FK).
        DB::statement(
            'ALTER TABLE scheduled_plan_changes
             ADD CONSTRAINT scheduled_plan_changes_target_price_plan_foreign
             FOREIGN KEY (target_price_id, target_plan_id)
             REFERENCES subscription_plan_prices (id, plan_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // At most one SCHEDULED change per subscription and effective boundary.
        DB::statement(
            "CREATE UNIQUE INDEX scheduled_plan_changes_one_per_cycle
             ON scheduled_plan_changes (merchant_subscription_id, effective_at)
             WHERE status = 'scheduled'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_plan_changes');
    }
};
