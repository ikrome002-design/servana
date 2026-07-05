<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand the `uploaded_files` purpose CHECK to allow `audit_export` (Phase 19;
 * ADR-010). Expand/contract — the shipped Phase-10F migration is never edited; this
 * forward-only migration drops and re-adds the CHECK with the new generated-file
 * purpose so the Audit export can write a private CSV through the Phase 10F file
 * domain. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE uploaded_files DROP CONSTRAINT uploaded_files_purpose_check');
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_purpose_check CHECK (purpose IN ('merchant_logo','profile_photo','dispute_evidence','audit_evidence','finance_export','audit_export','invoice_pdf','receipt_pdf','billing_invoice_pdf','earnings_statement','day_close_report','cash_up_report'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE uploaded_files DROP CONSTRAINT uploaded_files_purpose_check');
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_purpose_check CHECK (purpose IN ('merchant_logo','profile_photo','dispute_evidence','audit_evidence','finance_export','invoice_pdf','receipt_pdf','billing_invoice_pdf','earnings_statement','day_close_report','cash_up_report'))");
    }
};
