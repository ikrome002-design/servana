<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private business-file registry (Plan §13.13, §65; Phase 10F, REM-FILE-001).
 * Canonical DDL: docs/architecture/data-dictionary/files-and-media.md.
 *
 * Cross-cutting, nullable-scope infrastructure: merchant/branch/owner are nullable
 * because platform-generated files may have no merchant. Isolation is enforced by
 * the file-access service (every read re-checks scope) and scoped route binding —
 * it is classified in TenantOwnership, never silently exempt. Forward-only.
 *
 * Security invariants (Plan §9, §73): the original filename is encrypted at rest;
 * storage disk/paths and the SHA-256 are never exposed publicly; status columns
 * change only through transition actions (no mass assignment, no controller writes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_files', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();

            // Nullable scope — platform-generated files may have no merchant/branch.
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->restrictOnDelete();
            // Subject/owner of the file (e.g. the personnel a statement belongs to).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('purpose', 40);

            // Storage location — never exposed publicly.
            $table->string('storage_disk', 40);
            $table->string('quarantine_path', 512);
            $table->string('final_path', 512)->nullable();

            // Original name encrypted at rest; sanitised name used for downloads.
            $table->text('original_filename_encrypted');
            $table->string('safe_download_filename', 255);

            // Client-declared (untrusted) vs server magic-byte detection (authoritative).
            $table->string('declared_mime_type', 191)->nullable();
            $table->string('detected_mime_type', 191)->nullable();
            $table->string('extension', 20)->nullable();
            $table->bigInteger('size_bytes');
            // Streaming SHA-256 — never exposed publicly; NOT globally unique (§73).
            $table->char('sha256', 64);

            $table->string('scan_status', 20)->default('pending');
            $table->string('lifecycle_status', 20)->default('quarantined');

            $table->timestamp('retention_until')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'purpose', 'lifecycle_status']);
            $table->index(['branch_id', 'purpose']);
            $table->index('sha256');
            $table->index(['scan_status', 'created_at']);
        });

        // Status enums backed by DB CHECKs (guardrail §6.7 / Plan §13.1).
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_purpose_check CHECK (purpose IN ('merchant_logo','profile_photo','dispute_evidence','audit_evidence','finance_export','invoice_pdf','receipt_pdf','billing_invoice_pdf','earnings_statement','day_close_report','cash_up_report'))");
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_scan_status_check CHECK (scan_status IN ('pending','clean','infected','scan_failed','rejected'))");
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_lifecycle_status_check CHECK (lifecycle_status IN ('quarantined','available','revoked','expired','deleted'))");
        // An available file MUST be clean and have a final path (financial-grade invariant).
        DB::statement("ALTER TABLE uploaded_files ADD CONSTRAINT uploaded_files_available_consistency_check CHECK (lifecycle_status <> 'available' OR (scan_status = 'clean' AND final_path IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_files');
    }
};
