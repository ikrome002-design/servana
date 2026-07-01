<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * invoices — merchant-client invoices (Plan §13.8, §40, §25.3; §80 Phase 17).
 * Branch-owned. The ULID is the public identifier + route key; the internal bigint
 * id is never exposed.
 *
 * Finalization (draft → issued) allocates a gap-free per-merchant number from
 * invoice_number_sequences under a row lock and snapshots prices, the resolved
 * preferred-personnel fee, the (null until Phase 20E) percentage-fee config, and
 * tax/discount. Issued monetary snapshots and the number are IMMUTABLE. Void and
 * adjust are additive and non-destructive: the original snapshots + number are
 * never mutated and no row is ever deleted (Gate B columns below). Balance is
 * total_minor - validated_paid_minor, where validated_paid_minor is written only
 * by the Phase-18B validated-payment workflow (never a Phase 17 route).
 *
 * Money is integer minor units only (Money value object). Structural invariants
 * live in PostgreSQL: status CHECK (nine states), uppercase-ISO currency CHECK,
 * non-negative money CHECKs, validated_paid <= total, draft/finalization coherence,
 * total arithmetic coherence, void/adjust coherence, composite-merchant FKs, and a
 * partial-unique merchant-wide invoice number. The full lifecycle lives in
 * docs/architecture/state-machines/invoice.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Allocated at finalization (draft → issued); null while draft.
            $table->string('invoice_number', 40)->nullable();
            $table->string('status', 24)->default('draft');
            // Payable state preserved when entering void_pending, restored on rejection.
            $table->string('previous_status', 24)->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            // Σ of item preferred-personnel fees (Gate D); null = no preferred fee anywhere.
            $table->bigInteger('preferred_personnel_fee_snapshot_minor')->nullable();
            $table->bigInteger('total_minor')->default(0);
            // Written ONLY by the Phase-18B validated-payment workflow; starts at 0.
            $table->bigInteger('validated_paid_minor')->default(0);
            $table->char('currency', 3)->default('KES');
            // Gate E: null = "not configured" until Phase 20E populates it.
            $table->jsonb('percentage_fee_config_snapshot')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            // Gate B — additive void columns (no destructive edit, no new table).
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            // Gate B — additive adjustment columns.
            $table->timestampTz('adjusted_at')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('adjustment_reason')->nullable();
            // Correcting-invoice link (composite self-FK added below).
            $table->unsignedBigInteger('adjustment_of_invoice_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['merchant_id', 'invoice_number']);
            $table->index('client_id');
            $table->index('adjustment_of_invoice_id');
            // Composite-FK target for invoice_items + the self-referencing adjustment FK.
            $table->unique(['id', 'merchant_id'], 'invoices_id_merchant_id_unique');
        });

        // Merchant-wide invoice-number uniqueness; many NULL-number drafts coexist.
        DB::statement(
            'CREATE UNIQUE INDEX invoices_merchant_invoice_number_unique
             ON invoices (merchant_id, invoice_number)
             WHERE invoice_number IS NOT NULL'
        );

        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
             CHECK (status IN ('draft','issued','partially_paid','paid','void_pending','voided','adjusted','refund_pending','adjustment_required'))"
        );
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        // Non-negative money.
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_subtotal_check CHECK (subtotal_minor >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_discount_check CHECK (discount_minor >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_tax_check CHECK (tax_minor >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_check CHECK (total_minor >= 0)');
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_preferred_fee_snapshot_check
             CHECK (preferred_personnel_fee_snapshot_minor IS NULL OR preferred_personnel_fee_snapshot_minor >= 0)'
        );
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_validated_paid_nonneg_check CHECK (validated_paid_minor >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_validated_paid_le_total_check CHECK (validated_paid_minor <= total_minor)');
        // Total arithmetic coherence (integer minor units).
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_total_arithmetic_check
             CHECK (total_minor = subtotal_minor + COALESCE(preferred_personnel_fee_snapshot_minor, 0) + tax_minor - discount_minor)'
        );
        // Draft has no number and no finalized_at; issued+ has both.
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_draft_unnumbered_check
             CHECK (status <> 'draft' OR (invoice_number IS NULL AND finalized_at IS NULL))"
        );
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_finalized_numbered_check
             CHECK (status = 'draft' OR (invoice_number IS NOT NULL AND finalized_at IS NOT NULL))"
        );
        // Void / void_pending / adjust coherence (Gate B).
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_voided_coherence_check
             CHECK (status <> 'voided' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_previous_status_check
             CHECK (previous_status IS NULL OR status = 'void_pending')"
        );
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_void_pending_coherence_check
             CHECK (status <> 'void_pending' OR (previous_status IN ('issued','partially_paid') AND void_reason IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE invoices ADD CONSTRAINT invoices_adjusted_coherence_check
             CHECK (status <> 'adjusted' OR (adjusted_at IS NOT NULL AND adjusted_by IS NOT NULL AND adjustment_reason IS NOT NULL))"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE invoices
             ADD CONSTRAINT invoices_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE invoices
             ADD CONSTRAINT invoices_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        // Same-merchant correcting-invoice link (Gate B additive adjustment).
        DB::statement(
            'ALTER TABLE invoices
             ADD CONSTRAINT invoices_adjustment_of_merchant_foreign
             FOREIGN KEY (adjustment_of_invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
