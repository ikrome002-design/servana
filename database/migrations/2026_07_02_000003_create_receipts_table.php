<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * receipts — one original receipt per validated payment group (+ reissue) (Plan §13.8,
 * §13.16, §43; Gate J; Phase 18B). Branch-owned. The ULID is the public identifier.
 *
 * A receipt cannot exist before a validated payment_validation_events row. The
 * receipt_number is per-merchant unique and gap-free (receipt_number_sequences). The
 * `components` jsonb holds SAFE snapshots only ({method, amount_minor}) — never a full
 * or normalized payment reference, internal id, or storage path. The PDF is generated
 * through the Phase 10F file domain (purpose receipt_pdf) via a durable outbox job; a
 * receipt is not "issued for download" until file_generation_status = 'ready'. Reissue
 * creates a NEW row referencing the immutable original (reissue_of_receipt_id) with a
 * new number; the original is never mutated. Money is integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_validation_event_id')->nullable()->constrained('payment_validation_events')->restrictOnDelete();
            $table->bigInteger('receipt_number');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');
            $table->jsonb('components');
            $table->foreignId('reissue_of_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->string('file_generation_status', 16)->default('pending');
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['invoice_id']);
            $table->index(['branch_id', 'created_at']);
            $table->unique(['merchant_id', 'receipt_number'], 'receipts_merchant_id_receipt_number_unique');
            $table->unique(['id', 'merchant_id'], 'receipts_id_merchant_id_unique');
        });

        DB::statement(
            'ALTER TABLE receipts ADD CONSTRAINT receipts_amount_check
             CHECK (amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE receipts ADD CONSTRAINT receipts_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            "ALTER TABLE receipts ADD CONSTRAINT receipts_file_generation_status_check
             CHECK (file_generation_status IN ('pending','ready','failed'))"
        );
        // Exactly one ORIGINAL receipt per validated event (reissues excluded).
        DB::statement(
            'CREATE UNIQUE INDEX receipts_one_original_per_event
             ON receipts (payment_validation_event_id) WHERE reissue_of_receipt_id IS NULL'
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE receipts
             ADD CONSTRAINT receipts_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE receipts
             ADD CONSTRAINT receipts_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE receipts
             ADD CONSTRAINT receipts_event_merchant_foreign
             FOREIGN KEY (payment_validation_event_id, merchant_id)
             REFERENCES payment_validation_events (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE receipts
             ADD CONSTRAINT receipts_reissue_merchant_foreign
             FOREIGN KEY (reissue_of_receipt_id, merchant_id)
             REFERENCES receipts (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
