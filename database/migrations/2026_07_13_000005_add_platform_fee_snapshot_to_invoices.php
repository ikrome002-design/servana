<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only expand (ADR-004): additive nullable percentage-fee configuration snapshot on the
 * Phase 17 merchant-client `invoices` header (Plan §13.10, §51; Phase 20E). The shipped P17
 * migration is NOT edited. Written at finalization for percentage-bearing modes only; existing
 * finalized invoices and all fixed-only invoices keep NULL (no backfill/recalculation). The
 * merchant-liability ledger row is created later at Finance validation — this header captures the
 * immutable configuration/rate/basis/tier/split/currency + the computed gross/client-shifted split.
 * Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('platform_fee_configuration_id')->nullable()->after('id')
                ->constrained('platform_fee_configurations')->restrictOnDelete();
            $table->string('platform_fee_billing_mode_snapshot', 64)->nullable();
            $table->integer('platform_fee_rate_bps_snapshot')->nullable();
            $table->string('platform_fee_tier_snapshot', 24)->nullable();
            $table->string('platform_fee_basis_type_snapshot', 48)->nullable();
            $table->integer('platform_fee_shared_split_snapshot')->nullable();
            $table->char('platform_fee_currency', 3)->nullable();
            $table->bigInteger('platform_fee_gross_minor')->nullable();
            $table->bigInteger('platform_fee_client_shifted_minor')->nullable();
            $table->timestampTz('platform_fee_resolved_at')->nullable();
        });

        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_tier_snapshot_check
             CHECK (platform_fee_tier_snapshot IS NULL OR platform_fee_tier_snapshot IN ('customer_centric','shared','business_centric'))"
        );
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_basis_type_check
             CHECK (platform_fee_basis_type_snapshot IS NULL OR platform_fee_basis_type_snapshot IN ('merchant_client_invoice_service_subtotal','merchant_client_invoice_total','net_after_discount','invoice_item_subtotal','validated_paid_amount'))"
        );
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_rate_range_check
             CHECK (platform_fee_rate_bps_snapshot IS NULL OR (platform_fee_rate_bps_snapshot BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_split_range_check
             CHECK (platform_fee_shared_split_snapshot IS NULL OR (platform_fee_shared_split_snapshot BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_currency_check
             CHECK (platform_fee_currency IS NULL OR (platform_fee_currency = upper(platform_fee_currency) AND char_length(platform_fee_currency) = 3))'
        );
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_amounts_nonneg_check
             CHECK ((platform_fee_gross_minor IS NULL OR platform_fee_gross_minor >= 0)
                AND (platform_fee_client_shifted_minor IS NULL OR platform_fee_client_shifted_minor >= 0))'
        );
        // Snapshot coherence: either the whole percentage snapshot is present (configuration id,
        // mode, rate, tier, basis, currency, gross, resolved-at) or it is entirely absent.
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_platform_fee_snapshot_coherence_check
             CHECK (
                 (platform_fee_configuration_id IS NULL
                    AND platform_fee_billing_mode_snapshot IS NULL
                    AND platform_fee_rate_bps_snapshot IS NULL
                    AND platform_fee_tier_snapshot IS NULL
                    AND platform_fee_basis_type_snapshot IS NULL
                    AND platform_fee_currency IS NULL
                    AND platform_fee_gross_minor IS NULL
                    AND platform_fee_client_shifted_minor IS NULL
                    AND platform_fee_resolved_at IS NULL)
                 OR (platform_fee_configuration_id IS NOT NULL
                    AND platform_fee_billing_mode_snapshot IS NOT NULL
                    AND platform_fee_rate_bps_snapshot IS NOT NULL
                    AND platform_fee_tier_snapshot IS NOT NULL
                    AND platform_fee_basis_type_snapshot IS NOT NULL
                    AND platform_fee_currency IS NOT NULL
                    AND platform_fee_gross_minor IS NOT NULL
                    AND platform_fee_client_shifted_minor IS NOT NULL
                    AND platform_fee_resolved_at IS NOT NULL)
             )'
        );
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_fee_configuration_id');
            $table->dropColumn([
                'platform_fee_billing_mode_snapshot',
                'platform_fee_rate_bps_snapshot',
                'platform_fee_tier_snapshot',
                'platform_fee_basis_type_snapshot',
                'platform_fee_shared_split_snapshot',
                'platform_fee_currency',
                'platform_fee_gross_minor',
                'platform_fee_client_shifted_minor',
                'platform_fee_resolved_at',
            ]);
        });
    }
};
