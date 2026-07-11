<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * preferred_personnel_fee_rules — launch-active, effective-dated fixed/percentage rules
 * (Plan §13.10, §47; ADR-005 round-half-up; Phase 20A). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/preferred-personnel-fee-rule.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). Super-Admin
 * governed. Value-shape CHECKs make fixed vs percentage mutually exclusive; scope CHECKs bind
 * service_id to scope; a partial btree_gist EXCLUDE (over active + scheduled) prevents
 * overlapping effective ranges per scope (and per service_id when scope='service'). Active
 * monetary terms are immutable (supersede with a new version). change_reason is mandatory.
 * Expand-and-contract from the legacy services.preferred_personnel_fee_minor seam is a
 * separate forward migration (…000006). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist for scalar `=` (text scope + expression coalesce(service_id,0))
        // alongside the daterange overlap in the EXCLUDE constraint.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('preferred_personnel_fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('calculation_type', 16);
            $table->bigInteger('fixed_amount_minor')->nullable();
            $table->integer('percentage_basis_points')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('calculation_basis', 32);
            $table->string('scope', 16);
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            // Nullable: NULL denotes the system/migration legacy backfill (…000006), which
            // has no acting user; every interactive create action sets created_by.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('change_reason');
            $table->timestampsTz();

            $table->index(['scope', 'service_id', 'status', 'effective_from'], 'preferred_personnel_fee_rules_resolution_index');
        });

        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_calculation_type_check
             CHECK (calculation_type IN ('fixed_amount','percentage'))"
        );
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_basis_check
             CHECK (calculation_basis IN ('service_item_net_amount','service_item_gross_amount'))"
        );
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_scope_check
             CHECK (scope IN ('platform_default','service'))"
        );
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_status_check
             CHECK (status IN ('draft','scheduled','active','superseded','expired','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_basis_points_range_check
             CHECK (percentage_basis_points IS NULL OR (percentage_basis_points BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_fixed_amount_nonneg_check
             CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_currency_check
             CHECK (currency IS NULL OR (currency = upper(currency) AND char_length(currency) = 3))'
        );
        // Value-shape: fixed ⇒ amount+currency present & bp null; percentage ⇒ bp present & amount/currency null.
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_value_shape_check
             CHECK (
                 (calculation_type = 'fixed_amount' AND fixed_amount_minor IS NOT NULL AND currency IS NOT NULL AND percentage_basis_points IS NULL)
                 OR
                 (calculation_type = 'percentage' AND percentage_basis_points IS NOT NULL AND fixed_amount_minor IS NULL AND currency IS NULL)
             )"
        );
        // Scope: platform_default ⇒ service_id null; service ⇒ service_id not null.
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_scope_service_check
             CHECK (
                 (scope = 'platform_default' AND service_id IS NULL)
                 OR
                 (scope = 'service' AND service_id IS NOT NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE preferred_personnel_fee_rules ADD CONSTRAINT preferred_personnel_fee_rules_change_reason_check
             CHECK (char_length(btrim(change_reason)) > 0)'
        );

        // No overlapping ACTIVE/SCHEDULED ranges per scope (and per service_id when scoped).
        // coalesce(service_id,0) groups platform_default rows; half-open daterange so
        // adjacent ranges are allowed; superseded/expired/cancelled/draft never block.
        DB::statement(
            "ALTER TABLE preferred_personnel_fee_rules
             ADD CONSTRAINT preferred_personnel_fee_rules_no_overlap
             EXCLUDE USING gist (
                 scope WITH =,
                 (coalesce(service_id, 0)) WITH =,
                 daterange(effective_from, effective_to, '[)') WITH &&
             )
             WHERE (status IN ('active','scheduled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('preferred_personnel_fee_rules');
    }
};
