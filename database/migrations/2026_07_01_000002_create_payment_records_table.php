<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_records — a concrete-method component of a payment recording group
 * (Plan §13.8, §41; §80 Phase 18A). Branch-owned. The ULID is the public
 * identifier + route key.
 *
 * Every record belongs to exactly one payment_recording_groups row. Phase 18A
 * always creates a component at 'pending_validation' (the duplicate hold is a
 * GROUP-level state, not a component state). method carries the full CHECK enum for
 * schema fidelity, but Gate B forbids the application from ever writing
 * 'split_payment' as a component method — a split is represented by the group, and
 * the concrete component methods are cash/mpesa_offline/bank_transfer/card_terminal/
 * voucher/other. Method-specific reference rules (§41): cash reference optional (no
 * duplicate check); mpesa_offline/bank_transfer/card_terminal/voucher/other require
 * a reference and are duplicate-checked in payment_reference_checks.
 *
 * reference_normalized is the normalized comparison key ($hidden; never in a
 * Resource/audit/log); reference_display_encrypted is the encrypted original (masked
 * to a suffix for display). validated_amount_minor is Phase-18B-written (null here).
 * Money is integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_records', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_recording_group_id')->constrained('payment_recording_groups')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('payer_client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('method', 20);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');
            // Normalized comparison key; $hidden on the model; never returned/audited/logged.
            $table->string('reference_normalized', 191)->nullable();
            // Encrypted original entered reference; decrypted only to derive a masked suffix.
            $table->text('reference_display_encrypted')->nullable();
            $table->timestampTz('paid_at');
            $table->string('status', 24)->default('pending_validation');
            $table->foreignId('maker_user_id')->constrained('users')->restrictOnDelete();
            // Phase 18B — written at validation only; null in Phase 18A.
            $table->bigInteger('validated_amount_minor')->nullable();
            $table->timestampsTz();

            $table->index(['invoice_id', 'status']);
            // NON-unique — the record table must allow a duplicate reference to persist;
            // uniqueness lives on payment_reference_checks (Gate C).
            $table->index(['merchant_id', 'method', 'reference_normalized']);
            $table->index('payment_recording_group_id');
            // Composite-FK target for payment_allocations + payment_reference_checks.
            $table->unique(['id', 'merchant_id'], 'payment_records_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE payment_records ADD CONSTRAINT payment_records_method_check
             CHECK (method IN ('cash','mpesa_offline','bank_transfer','card_terminal','voucher','split_payment','other'))"
        );
        DB::statement(
            "ALTER TABLE payment_records ADD CONSTRAINT payment_records_status_check
             CHECK (status IN ('pending_validation','validated','rejected','correction_required','reversed','adjusted'))"
        );
        DB::statement('ALTER TABLE payment_records ADD CONSTRAINT payment_records_amount_check CHECK (amount_minor > 0)');
        DB::statement(
            'ALTER TABLE payment_records ADD CONSTRAINT payment_records_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE payment_records ADD CONSTRAINT payment_records_validated_amount_check
             CHECK (validated_amount_minor IS NULL OR validated_amount_minor >= 0)'
        );
        // Method ↔ reference coherence: reference-requiring methods must carry a
        // normalized reference; cash (and the never-persisted split_payment) may not.
        DB::statement(
            "ALTER TABLE payment_records ADD CONSTRAINT payment_records_reference_coherence_check
             CHECK (method NOT IN ('mpesa_offline','bank_transfer','card_terminal','voucher','other') OR reference_normalized IS NOT NULL)"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE payment_records
             ADD CONSTRAINT payment_records_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_records
             ADD CONSTRAINT payment_records_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_records
             ADD CONSTRAINT payment_records_group_merchant_foreign
             FOREIGN KEY (payment_recording_group_id, merchant_id)
             REFERENCES payment_recording_groups (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_records
             ADD CONSTRAINT payment_records_payer_client_merchant_foreign
             FOREIGN KEY (payer_client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_records');
    }
};
