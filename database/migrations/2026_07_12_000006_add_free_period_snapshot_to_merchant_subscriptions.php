<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_subscriptions free-period snapshot (Gate C4; Phase 20C). Forward-only expand —
 * the shipped 20B `create_merchant_subscriptions` migration is NOT edited (guardrail 12).
 * Additive nullable columns record WHICH free-period offer set the trial length; the
 * applied days stay in the existing `trial_days_snapshot` (null offer ⇒ platform default
 * trial days). Existing trials keep NULL (no backfill). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            $table->foreignId('free_period_offer_id')->nullable()->after('trial_days_snapshot')
                ->constrained('free_period_offers')->restrictOnDelete();
            $table->timestampTz('free_period_resolved_at')->nullable()->after('free_period_offer_id');

            $table->index('free_period_offer_id', 'merchant_subscriptions_free_period_index');
        });

        // Snapshot coherence: offer id and resolution instant are both-null or both-set.
        DB::statement(
            'ALTER TABLE merchant_subscriptions ADD CONSTRAINT merchant_subscriptions_free_period_snapshot_check
             CHECK ((free_period_offer_id IS NULL AND free_period_resolved_at IS NULL)
                 OR (free_period_offer_id IS NOT NULL AND free_period_resolved_at IS NOT NULL))'
        );
    }

    public function down(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            DB::statement('ALTER TABLE merchant_subscriptions DROP CONSTRAINT IF EXISTS merchant_subscriptions_free_period_snapshot_check');
            $table->dropIndex('merchant_subscriptions_free_period_index');
            $table->dropConstrainedForeignId('free_period_offer_id');
        });
    }
};
