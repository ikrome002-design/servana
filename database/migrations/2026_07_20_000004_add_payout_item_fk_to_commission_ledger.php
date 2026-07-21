<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand migration (ADR-004): add the composite FK from commission_ledger.payout_item_id to
 * personnel_payout_items now that the Phase 20H target table exists. Phase 20G created
 * commission_ledger.payout_item_id as a nullable, UN-CONSTRAINED column; this adds the FK by
 * expand — the shipped 20G migration is never edited.
 *
 * The FK is composite `(payout_item_id, merchant_id) → personnel_payout_items (id, merchant_id)` so
 * a commission ledger row can only ever link to a payout item of the SAME merchant. PostgreSQL
 * MATCH SIMPLE (default) does not enforce the FK while payout_item_id is NULL (an unlinked earned
 * row), so no backfill is needed and existing rows remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_payout_item_merchant_foreign FOREIGN KEY (payout_item_id, merchant_id) REFERENCES personnel_payout_items (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commission_ledger DROP CONSTRAINT IF EXISTS commission_ledger_payout_item_merchant_foreign');
    }
};
