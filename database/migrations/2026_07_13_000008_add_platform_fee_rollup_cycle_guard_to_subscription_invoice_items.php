<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forward-only expand (ADR-004): the cycle-level uniqueness guard for the Phase 20E platform-fee
 * rollup line (Plan §13.10, §51; Phase 20E, Increment 5A). The shipped Phase 20B
 * `subscription_invoice_items` migration is NOT edited.
 *
 * A partial UNIQUE index guarantees at most ONE `platform_fee_rollup` line per subscription invoice.
 * Combined with the existing one-invoice-per-(merchant, period) idempotency in
 * {@see IssueSubscriptionInvoice} (serialized on the MerchantSubscription
 * row lock), this makes it impossible for two concurrent workers to issue two separate platform-fee
 * rollups for the same merchant / subscription / currency / billing period — the guard is at the
 * database, not an application-only pre-check (assignment §4.4). Non-rollup line types are
 * unconstrained. Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX subscription_invoice_items_platform_fee_rollup_unique
             ON subscription_invoice_items (subscription_invoice_id)
             WHERE type = 'platform_fee_rollup'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscription_invoice_items_platform_fee_rollup_unique');
    }
};
