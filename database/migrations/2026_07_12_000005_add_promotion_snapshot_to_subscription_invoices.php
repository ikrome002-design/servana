<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_invoices promotion snapshot (Gate C4; Phase 20C). Forward-only expand —
 * the shipped 20B `create_subscription_invoices` migration is NOT edited (guardrail 12).
 * Additive nullable columns record WHICH promotion applied and its configured terms,
 * alongside the existing `discount_minor` (the applied, capped amount — Gate C5).
 * Existing issued invoices keep NULL (no backfill, no recalculation). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->foreignId('promotional_discount_id')->nullable()->after('discount_minor')
                ->constrained('promotional_discounts')->restrictOnDelete();
            $table->string('promotion_type', 16)->nullable()->after('promotional_discount_id');
            $table->bigInteger('promotion_value_snapshot')->nullable()->after('promotion_type');
            $table->char('promotion_currency', 3)->nullable()->after('promotion_value_snapshot');
            $table->timestampTz('promotion_resolved_at')->nullable()->after('promotion_currency');

            $table->index('promotional_discount_id', 'subscription_invoices_promotion_index');
        });

        DB::statement(
            "ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_promotion_type_check
             CHECK (promotion_type IS NULL OR promotion_type IN ('percentage','fixed_amount'))"
        );
        DB::statement(
            'ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_promotion_value_check
             CHECK (promotion_value_snapshot IS NULL OR promotion_value_snapshot > 0)'
        );
        // Snapshot coherence: either no promotion (all null) or a complete snapshot.
        // promotion_currency stays null for percentage promotions, so it is not required here.
        DB::statement(
            'ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_promotion_snapshot_check
             CHECK (
                 (promotional_discount_id IS NULL AND promotion_type IS NULL AND promotion_value_snapshot IS NULL
                     AND promotion_currency IS NULL AND promotion_resolved_at IS NULL)
                 OR (promotional_discount_id IS NOT NULL AND promotion_type IS NOT NULL
                     AND promotion_value_snapshot IS NOT NULL AND promotion_resolved_at IS NOT NULL)
             )'
        );
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            DB::statement('ALTER TABLE subscription_invoices DROP CONSTRAINT IF EXISTS subscription_invoices_promotion_snapshot_check');
            DB::statement('ALTER TABLE subscription_invoices DROP CONSTRAINT IF EXISTS subscription_invoices_promotion_value_check');
            DB::statement('ALTER TABLE subscription_invoices DROP CONSTRAINT IF EXISTS subscription_invoices_promotion_type_check');
            $table->dropIndex('subscription_invoices_promotion_index');
            $table->dropConstrainedForeignId('promotional_discount_id');
            $table->dropColumn(['promotion_type', 'promotion_value_snapshot', 'promotion_currency', 'promotion_resolved_at']);
        });
    }
};
