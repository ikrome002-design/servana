<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expands the shipped `invoice_number_sequences.scope` CHECK to allow `subscription_invoice`
 * in addition to `merchant_client_invoice` (Gate B3; Plan §13.15; ADR-004 expand-and-contract;
 * Phase 20B). Subscription invoices allocate gap-free per-merchant numbers from a SEPARATE
 * counter row `(merchant_id, scope='subscription_invoice')` under a row lock — the two counters
 * are independent and merchant-client numbering is unchanged. `unique(merchant_id, scope)`
 * already scopes the counters. The original migration is NOT edited; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_number_sequences DROP CONSTRAINT IF EXISTS invoice_number_sequences_scope_check');
        DB::statement(
            "ALTER TABLE invoice_number_sequences
             ADD CONSTRAINT invoice_number_sequences_scope_check
             CHECK (scope IN ('merchant_client_invoice','subscription_invoice'))"
        );
    }

    public function down(): void
    {
        // Restore the former single-value CHECK only when no subscription_invoice rows exist
        // (safe rollback per repository conventions; never silently drop data).
        $hasSubscriptionScope = DB::table('invoice_number_sequences')
            ->where('scope', 'subscription_invoice')
            ->exists();

        DB::statement('ALTER TABLE invoice_number_sequences DROP CONSTRAINT IF EXISTS invoice_number_sequences_scope_check');

        if (! $hasSubscriptionScope) {
            DB::statement(
                "ALTER TABLE invoice_number_sequences
                 ADD CONSTRAINT invoice_number_sequences_scope_check
                 CHECK (scope = 'merchant_client_invoice')"
            );
        } else {
            // Rows exist under the new scope — retain the widened CHECK rather than corrupt data.
            DB::statement(
                "ALTER TABLE invoice_number_sequences
                 ADD CONSTRAINT invoice_number_sequences_scope_check
                 CHECK (scope IN ('merchant_client_invoice','subscription_invoice'))"
            );
        }
    }
};
