<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand migration (ADR-004): add the composite FK from salary_ledger.payout_item_id to
 * personnel_payout_items. Phase 20G created salary_ledger.payout_item_id as a nullable,
 * UN-CONSTRAINED column; this adds the FK by expand — the shipped 20G migration is never edited.
 *
 * Composite `(payout_item_id, merchant_id) → personnel_payout_items (id, merchant_id)` keeps the
 * link merchant-consistent; MATCH SIMPLE skips the check while payout_item_id is NULL (an unlinked
 * pending accrual). No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_payout_item_merchant_foreign FOREIGN KEY (payout_item_id, merchant_id) REFERENCES personnel_payout_items (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE salary_ledger DROP CONSTRAINT IF EXISTS salary_ledger_payout_item_merchant_foreign');
    }
};
