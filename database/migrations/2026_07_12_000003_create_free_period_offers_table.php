<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * free_period_offers — platform-governed free-period (trial-length) offer configuration
 * (Plan §53; Phase 20C). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/
 * free-period-offer.md.
 *
 * PLATFORM-OWNED (TenantOwnership::EXEMPT). `status` mirrors FreePeriodOfferStatus;
 * `target_scope` mirrors PromotionTargetScope. `free_period_days` is 1..365. Effective-
 * dated; approved terms immutable; no hard-delete. Approval always lands in `scheduled`
 * (no direct draft→active; §12). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_period_offers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name', 120);
            $table->integer('free_period_days');
            $table->string('target_scope', 24);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'effective_from', 'effective_to'], 'free_period_offers_resolution_index');
            $table->index('target_scope', 'free_period_offers_target_scope_index');
        });

        DB::statement(
            'ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_name_check
             CHECK (char_length(btrim(name)) > 0)'
        );
        DB::statement(
            'ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_days_check
             CHECK (free_period_days BETWEEN 1 AND 365)'
        );
        DB::statement(
            "ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_status_check
             CHECK (status IN ('draft','scheduled','active','paused','expired','cancelled'))"
        );
        DB::statement(
            "ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_target_scope_check
             CHECK (target_scope IN ('all_new_merchants','selected_merchants','selected_plans','billing_mode'))"
        );
        DB::statement(
            'ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_approval_pair_check
             CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))'
        );
        DB::statement(
            "ALTER TABLE free_period_offers ADD CONSTRAINT free_period_offers_approval_status_check
             CHECK (
                 (status = 'draft' AND approved_by IS NULL)
                 OR (status IN ('scheduled','active','paused','expired') AND approved_by IS NOT NULL)
                 OR (status = 'cancelled')
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('free_period_offers');
    }
};
