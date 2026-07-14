<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only expand (ADR-004): additive nullable per-item largest-remainder provenance on the
 * Phase 17 `invoice_items` table (Plan §13.10, §51; Phase 20E). The shipped P17 migration is NOT
 * edited. Written at finalization for percentage-bearing modes only; existing items keep NULL. The
 * sum of item shares reconciles to the invoices-header snapshot (test-enforced, not a cross-row DB
 * constraint). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->bigInteger('platform_fee_item_gross_minor')->nullable();
            $table->bigInteger('platform_fee_item_client_shifted_minor')->nullable();
            $table->bigInteger('platform_fee_item_absorbed_minor')->nullable();
        });

        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_platform_fee_item_nonneg_check
             CHECK ((platform_fee_item_gross_minor IS NULL OR platform_fee_item_gross_minor >= 0)
                AND (platform_fee_item_client_shifted_minor IS NULL OR platform_fee_item_client_shifted_minor >= 0)
                AND (platform_fee_item_absorbed_minor IS NULL OR platform_fee_item_absorbed_minor >= 0))'
        );
        // Per-item split invariant when present: client_shifted + absorbed = gross.
        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_platform_fee_item_split_check
             CHECK (
                 platform_fee_item_gross_minor IS NULL
                 OR (platform_fee_item_client_shifted_minor IS NOT NULL
                     AND platform_fee_item_absorbed_minor IS NOT NULL
                     AND platform_fee_item_client_shifted_minor + platform_fee_item_absorbed_minor = platform_fee_item_gross_minor)
             )'
        );
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn([
                'platform_fee_item_gross_minor',
                'platform_fee_item_client_shifted_minor',
                'platform_fee_item_absorbed_minor',
            ]);
        });
    }
};
