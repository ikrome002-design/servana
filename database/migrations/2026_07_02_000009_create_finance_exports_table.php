<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * finance_exports — async, scoped, masked finance export requests (Plan §65, §67;
 * Gate I; Phase 18B). Merchant-owned with an optional branch scope. The ULID is the
 * public identifier.
 *
 * Generation runs on the reports-exports queue (GenerateFinanceExport, TenantAwareJob)
 * and writes a private CSV through the Phase 10F file domain (purpose finance_export).
 * export_type enumerates all nine future types for forward compatibility, but the
 * Phase 18B request policy rejects compensation/payouts/billing (422
 * unsupported_export_type). Downloads are authorized + signed; download_count is
 * incremented atomically; exports expire and can be revoked. Failures store only a
 * redacted code/message. See docs/architecture/state-machines/finance-export.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('export_type', 24);
            $table->jsonb('scope_json');
            $table->string('reason', 500);
            $table->string('status', 16)->default('queued');
            $table->foreignId('file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->integer('row_count')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('first_downloaded_at')->nullable();
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->integer('download_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message_redacted', 500)->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'status']);
            $table->index(['requested_by', 'created_at']);
            $table->index(['expires_at']);
            $table->unique(['id', 'merchant_id'], 'finance_exports_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE finance_exports ADD CONSTRAINT finance_exports_export_type_check
             CHECK (export_type IN ('invoices','payments','receipts','cash_up','refunds','disputes','compensation','payouts','billing'))"
        );
        DB::statement(
            "ALTER TABLE finance_exports ADD CONSTRAINT finance_exports_status_check
             CHECK (status IN ('queued','processing','ready','failed','expired','revoked'))"
        );
        DB::statement(
            'ALTER TABLE finance_exports ADD CONSTRAINT finance_exports_download_count_check
             CHECK (download_count >= 0)'
        );

        DB::statement(
            'ALTER TABLE finance_exports
             ADD CONSTRAINT finance_exports_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_exports');
    }
};
