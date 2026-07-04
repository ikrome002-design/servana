<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * finance_disputes — Finance-only investigation record over an invoice and/or payment
 * record (Plan §44; Phase 18B). Branch-owned. The ULID is the public identifier.
 *
 * The dispute NEVER mutates the disputed source row. At least one of invoice_id /
 * payment_record_id must identify the disputed record. Evidence uses the private Phase
 * 10F file domain (purpose dispute_evidence); the storage path is never exposed. Uses
 * the authoritative Plan 4-state set (open/under_review/resolved/rejected); the broader
 * Scope-only list is not added. See docs/architecture/state-machines/finance-dispute.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_disputes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_record_id')->nullable()->constrained('payment_records')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('reason', 500);
            $table->string('resolution_note', 1000)->nullable();
            $table->foreignId('evidence_file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['invoice_id']);
            $table->index(['payment_record_id']);
            $table->unique(['id', 'merchant_id'], 'finance_disputes_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE finance_disputes ADD CONSTRAINT finance_disputes_status_check
             CHECK (status IN ('open','under_review','resolved','rejected'))"
        );
        // At least one disputed financial record is identified.
        DB::statement(
            'ALTER TABLE finance_disputes ADD CONSTRAINT finance_disputes_linkage_check
             CHECK (invoice_id IS NOT NULL OR payment_record_id IS NOT NULL)'
        );
        // Resolution note + resolver coherence with terminal states.
        DB::statement(
            "ALTER TABLE finance_disputes ADD CONSTRAINT finance_disputes_resolution_check
             CHECK ((status IN ('resolved','rejected')) = (resolution_note IS NOT NULL AND resolved_by IS NOT NULL))"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE finance_disputes
             ADD CONSTRAINT finance_disputes_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE finance_disputes
             ADD CONSTRAINT finance_disputes_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE finance_disputes
             ADD CONSTRAINT finance_disputes_payment_record_merchant_foreign
             FOREIGN KEY (payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_disputes');
    }
};
