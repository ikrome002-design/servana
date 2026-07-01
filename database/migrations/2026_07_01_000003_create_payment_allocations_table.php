<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_allocations — allocation of a component payment to an invoice (Plan
 * §13.8, §41; §80 Phase 18A). Branch-owned. No ULID (child evidence row).
 *
 * Phase 18A allocates each component to the group's invoice at the INVOICE level
 * (invoice_item_id NULL); the nullable invoice_item_id preserves the Phase-18B
 * item-level allocation seam. Cross-row invariants — Σ(allocations for a component)
 * = component.amount_minor and Σ(group allocations) = group.total_amount_minor, and
 * group allocations target only the group's invoice — are enforced transactionally
 * under the invoice row lock (proven with concurrency tests), not by ordinary CHECK
 * constraints. An allocation NEVER mutates invoices.validated_paid_minor. Money is
 * integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('payment_record_id')->constrained('payment_records')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            // Phase-18B item-level seam; null in Phase 18A (invoice-level allocation).
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestampsTz();

            $table->index('payment_record_id');
            $table->index('invoice_id');
            $table->index(['merchant_id', 'branch_id']);
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount_minor > 0)');

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE payment_allocations
             ADD CONSTRAINT payment_allocations_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_allocations
             ADD CONSTRAINT payment_allocations_record_merchant_foreign
             FOREIGN KEY (payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_allocations
             ADD CONSTRAINT payment_allocations_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        // invoice_item_id keeps only the simple FK to invoice_items(id) (added by the
        // Blueprint above): it is NULL throughout Phase 18A (invoice-level allocation),
        // and invoice_items carries no (id, merchant_id) composite-unique target — the
        // shipped Phase 17 migration must not be edited. Item-level merchant/invoice
        // consistency is a Phase-18B concern activated with the item-allocation UI.
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
