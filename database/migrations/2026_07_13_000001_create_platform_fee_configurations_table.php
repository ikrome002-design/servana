<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_fee_configurations — effective-dated percentage platform-fee configuration
 * (Plan §13.10, §51, §52; Phase 20E). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/
 * platform-fee-configuration.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (TenantOwnership::EXEMPT). Super-Admin
 * governed. The active billing MODE remains platform_billing_settings (no duplicate source
 * of truth). Value-shape CHECKs bind percentage/fixed/tier/split coherence; a partial
 * btree_gist EXCLUDE (over active + scheduled) prevents overlapping effective ranges per
 * (billing_mode, currency). Approved monetary terms are immutable (supersede with a new
 * version). change_reason is mandatory. No backfill — the engine is inert until configured.
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('platform_fee_configurations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('billing_mode', 64);
            $table->integer('percentage_basis_points')->nullable();
            $table->bigInteger('fixed_component_minor')->nullable();
            $table->string('tier_behavior', 24)->nullable();
            $table->integer('shared_split_basis_points')->nullable();
            $table->string('fee_basis_type', 48)->nullable();
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('change_reason');
            $table->timestampsTz();

            $table->index(['billing_mode', 'currency', 'status', 'effective_from'], 'platform_fee_configurations_resolution_index');
        });

        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_billing_mode_check
             CHECK (billing_mode IN ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_tier_behavior_check
             CHECK (tier_behavior IS NULL OR tier_behavior IN ('customer_centric','shared','business_centric'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_fee_basis_type_check
             CHECK (fee_basis_type IS NULL OR fee_basis_type IN ('merchant_client_invoice_service_subtotal','merchant_client_invoice_total','net_after_discount','invoice_item_subtotal','validated_paid_amount'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_status_check
             CHECK (status IN ('draft','scheduled','active','superseded','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_basis_points_range_check
             CHECK (percentage_basis_points IS NULL OR (percentage_basis_points BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_shared_split_range_check
             CHECK (shared_split_basis_points IS NULL OR (shared_split_basis_points BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_fixed_component_nonneg_check
             CHECK (fixed_component_minor IS NULL OR fixed_component_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        // Percentage modes require rate + tier + basis; fixed_amount mode carries none of them.
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_mode_shape_check
             CHECK (
                 (billing_mode = 'fixed_amount' AND percentage_basis_points IS NULL AND tier_behavior IS NULL AND fee_basis_type IS NULL AND fixed_component_minor IS NULL)
                 OR (billing_mode = 'percentage_on_merchant_client_invoice' AND percentage_basis_points IS NOT NULL AND tier_behavior IS NOT NULL AND fee_basis_type IS NOT NULL AND fixed_component_minor IS NULL)
                 OR (billing_mode = 'fixed_amount_plus_percentage_on_merchant_client_invoice' AND percentage_basis_points IS NOT NULL AND tier_behavior IS NOT NULL AND fee_basis_type IS NOT NULL AND fixed_component_minor IS NOT NULL)
             )"
        );
        // shared tier ⇒ a configured split; non-shared tier ⇒ no split.
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_shared_split_coherence_check
             CHECK (
                 (tier_behavior = 'shared' AND shared_split_basis_points IS NOT NULL)
                 OR (tier_behavior IS DISTINCT FROM 'shared' AND shared_split_basis_points IS NULL)
             )"
        );
        // Gate 4.2 (product-owner decision): validated_paid_amount is future-dependent (known only at
        // Finance validation), so it may not drive a finalization-time client-shifted line. It is valid
        // only with customer_centric (client-shifted = 0). shared/business_centric must use a basis fully
        // known at finalization. The resolved-effective-tier domain guard additionally enforces this when
        // a merchant tier override differs from this configuration default.
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_validated_paid_tier_check
             CHECK (fee_basis_type IS DISTINCT FROM 'validated_paid_amount' OR tier_behavior = 'customer_centric')"
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_change_reason_check
             CHECK (char_length(btrim(change_reason)) > 0)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_approval_pair_check
             CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))'
        );
        // Approval/status coherence: draft unapproved; scheduled/active/superseded approved;
        // cancelled may come from draft (unapproved) or scheduled (approved).
        DB::statement(
            "ALTER TABLE platform_fee_configurations ADD CONSTRAINT platform_fee_configurations_approval_status_check
             CHECK (
                 (status = 'draft' AND approved_by IS NULL)
                 OR (status IN ('scheduled','active','superseded') AND approved_by IS NOT NULL)
                 OR (status = 'cancelled')
             )"
        );

        // No overlapping ACTIVE/SCHEDULED ranges per (billing_mode, currency). Half-open
        // daterange so adjacent ranges are allowed; terminal/draft rows never block.
        DB::statement(
            "ALTER TABLE platform_fee_configurations
             ADD CONSTRAINT platform_fee_configurations_no_overlap
             EXCLUDE USING gist (
                 billing_mode WITH =,
                 currency WITH =,
                 daterange(effective_from, effective_to, '[)') WITH &&
             )
             WHERE (status IN ('active','scheduled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_configurations');
    }
};
