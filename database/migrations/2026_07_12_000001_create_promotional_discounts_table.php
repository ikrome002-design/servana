<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * promotional_discounts — platform-governed promotional discount configuration
 * (Plan §53; Phase 20C). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/
 * promotional-discount.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). `type`
 * mirrors PromotionalDiscountType; `status` mirrors PromotionStatus; `target_scope`
 * mirrors PromotionTargetScope (BillingEnumParity/Phase20CEnumParityTest). `value` is a
 * positive integer — basis points for `percentage` (≤10000 = 100%), minor units for
 * `fixed_amount`. Effective-dated (`effective_from`/`effective_to`, Gate C1 — no
 * `starts_at`). Approved terms are immutable (a change is a new record); no hard-delete
 * path. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_discounts', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->bigInteger('value');
            $table->char('currency', 3)->nullable();
            $table->string('target_scope', 24);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'effective_from', 'effective_to'], 'promotional_discounts_resolution_index');
            $table->index('target_scope', 'promotional_discounts_target_scope_index');
        });

        DB::statement(
            'ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_name_check
             CHECK (char_length(btrim(name)) > 0)'
        );
        DB::statement(
            "ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_type_check
             CHECK (type IN ('percentage','fixed_amount'))"
        );
        DB::statement(
            "ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_status_check
             CHECK (status IN ('draft','scheduled','active','paused','expired','cancelled'))"
        );
        DB::statement(
            "ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_target_scope_check
             CHECK (target_scope IN ('all_new_merchants','selected_merchants','selected_plans','billing_mode'))"
        );
        DB::statement(
            'ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_value_positive_check
             CHECK (value > 0)'
        );
        // Value/currency coherence: percentage ⇒ null currency and ≤100% (10000 bps);
        // fixed_amount ⇒ uppercase ISO 3-char currency.
        DB::statement(
            "ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_value_currency_check
             CHECK (
                 (type = 'percentage' AND currency IS NULL AND value <= 10000)
                 OR (type = 'fixed_amount' AND currency IS NOT NULL AND currency = upper(currency) AND char_length(currency) = 3)
             )"
        );
        DB::statement(
            'ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        // approved_by / approved_at are both-null or both-set.
        DB::statement(
            'ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_approval_pair_check
             CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))'
        );
        // Approval/status coherence: draft is unapproved; scheduled/active/paused/expired require
        // approval; cancelled may be reached from draft (unapproved) or scheduled (approved).
        DB::statement(
            "ALTER TABLE promotional_discounts ADD CONSTRAINT promotional_discounts_approval_status_check
             CHECK (
                 (status = 'draft' AND approved_by IS NULL)
                 OR (status IN ('scheduled','active','paused','expired') AND approved_by IS NOT NULL)
                 OR (status = 'cancelled')
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_discounts');
    }
};
