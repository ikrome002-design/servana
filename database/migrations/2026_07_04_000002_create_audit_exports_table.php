<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_exports — async, reason-gated, permission-masked, signed/expiring,
 * download-counted Audit export requests (Plan §13.5, §19.2/§19.3, §80; Phase 19;
 * ADR-010; product-owner decision 2026-07-04 resolving REM-AUDEXP-001). Canonical
 * DDL: docs/architecture/data-dictionary/audit-files-notifications.md.
 *
 * Branch-owned (branch_id NOT NULL — Audit export is branch-scoped; merchant-level
 * branch-null audit rows are never exported). Generation runs on the reports-exports
 * queue (GenerateAuditExport, TenantAwareJob) writing a private CSV through the Phase
 * 10F file domain (FilePurpose::AuditExport). Download accounting happens on the
 * authorized file STREAM (not link issuance). Failures store only a redacted
 * code/message. No soft/destructive delete. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('reason', 500);
            $table->jsonb('scope_json');
            $table->string('status', 16)->default('queued');

            $table->foreignId('file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->integer('row_count')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestampTz('first_downloaded_at')->nullable();
            $table->timestampTz('last_downloaded_at')->nullable();

            $table->timestampTz('requested_at');
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expired_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message_redacted', 500)->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id', 'status', 'created_at']);
            $table->index(['expires_at']);
            $table->index(['requested_by_user_id', 'created_at']);
            $table->unique(['id', 'merchant_id'], 'audit_exports_id_merchant_id_unique');
        });

        // Status enum backed by a DB CHECK (guardrail §6.7).
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_status_check
             CHECK (status IN ('queued','processing','ready','failed','expired','revoked'))"
        );

        // Reason is always present and non-empty (reason-gated export).
        DB::statement(
            'ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_reason_not_empty_check
             CHECK (length(btrim(reason)) > 0)'
        );

        // scope_json is a JSON object (validated filters only), never a scalar/array.
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_scope_json_object_check
             CHECK (jsonb_typeof(scope_json) = 'object')"
        );

        // Download counter + timestamp coherence.
        DB::statement(
            'ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_download_count_check
             CHECK (download_count >= 0)'
        );
        DB::statement(
            'ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_first_download_coherence_check
             CHECK ((download_count = 0 AND first_downloaded_at IS NULL)
                 OR (download_count > 0 AND first_downloaded_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_last_download_coherence_check
             CHECK ((download_count = 0 AND last_downloaded_at IS NULL)
                 OR (download_count > 0 AND last_downloaded_at IS NOT NULL AND last_downloaded_at >= first_downloaded_at))'
        );

        // row_count: null until a terminal-with-file outcome, non-negative when set.
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_row_count_check
             CHECK ((row_count IS NULL AND status IN ('queued','processing','failed'))
                 OR (row_count >= 0 AND status IN ('ready','expired','revoked')))"
        );

        // Terminal/ready-state field requirements.
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_ready_requires_file_check
             CHECK (status <> 'ready'
                 OR (file_id IS NOT NULL AND generated_at IS NOT NULL AND expires_at IS NOT NULL AND row_count IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_failed_requires_reason_check
             CHECK (status <> 'failed' OR (failed_at IS NOT NULL AND failure_code IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_revoked_requires_ts_check
             CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE audit_exports ADD CONSTRAINT audit_exports_expired_requires_ts_check
             CHECK (status <> 'expired' OR expired_at IS NOT NULL)"
        );

        // Composite tenant/branch consistency (a branch always belongs to its merchant).
        DB::statement(
            'ALTER TABLE audit_exports
             ADD CONSTRAINT audit_exports_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_exports');
    }
};
