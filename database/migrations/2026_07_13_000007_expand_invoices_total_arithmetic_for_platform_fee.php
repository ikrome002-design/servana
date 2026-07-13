<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forward-only expand (ADR-004): widen the Phase 17 `invoices_total_arithmetic_check` so the invoice
 * total may include the Phase 20E client-shifted percentage amount (Plan §51, Scope §6.3.3; Phase 20E).
 * The shipped P17 migration is NOT edited — this new migration drops and recreates the constraint.
 *
 * Backward-compatible: existing invoices and all fixed-only invoices have `platform_fee_client_shifted_minor`
 * NULL → COALESCE 0 → the arithmetic is identical to before. Only shared/business_centric percentage
 * invoices add the client-shifted amount to `total_minor` (so the merchant-client actually pays it and
 * payments validate against it). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_total_arithmetic_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_total_arithmetic_check
             CHECK (total_minor = subtotal_minor + COALESCE(preferred_personnel_fee_snapshot_minor, 0) + tax_minor - discount_minor + COALESCE(platform_fee_client_shifted_minor, 0))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_total_arithmetic_check');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_total_arithmetic_check
             CHECK (total_minor = subtotal_minor + COALESCE(preferred_personnel_fee_snapshot_minor, 0) + tax_minor - discount_minor)'
        );
    }
};
