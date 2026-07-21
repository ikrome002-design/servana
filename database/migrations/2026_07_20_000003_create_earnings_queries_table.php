<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * earnings_queries — personnel own-scope earnings queries (Plan §63; §13.12 canonical DDL; §25.4
 * lifecycle; Phase 20H). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md;
 * lifecycle: docs/architecture/state-machines/earnings-query.md.
 *
 * BRANCH-OWNED + PERSONNEL OWN-SCOPE (staff_profile_id derived from membership at create time;
 * arbitrary staff ids are rejected). A personnel member raises a query against one of their own
 * facts (`subject_type` ∈ commission_ledger / salary_ledger / payout_item, `subject_id` validated
 * in-scope by the action). `query_type` drives the triage routing role (`assigned_role`); the
 * authoritative resolution permission is always `earnings_query.respond` (Finance) per the matrix.
 *
 * RESOLUTION NEVER MUTATES A LEDGER SILENTLY: a monetary correction is a separate
 * `compensation_adjustments` row, referenced by `resolved_adjustment_id`. Personnel see status +
 * resolution note. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings_queries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->string('query_type', 32);
            $table->text('body');
            $table->string('status', 16)->default('open');
            $table->string('assigned_role', 16)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_adjustment_id')->nullable()->constrained('compensation_adjustments')->restrictOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id', 'status']);
            $table->index(['staff_profile_id', 'status'], 'earnings_queries_staff_status_index');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_subject_type_check
             CHECK (subject_type IN ('commission_ledger','salary_ledger','payout_item'))"
        );
        DB::statement(
            "ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_query_type_check
             CHECK (query_type IN ('commission_disagreement','salary_disagreement','payout_missing','payout_amount','statement_request','other'))"
        );
        DB::statement(
            "ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_status_check
             CHECK (status IN ('open','assigned','resolved','rejected'))"
        );
        DB::statement(
            "ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_assigned_role_check
             CHECK (assigned_role IS NULL OR assigned_role IN ('finance','hr'))"
        );
        DB::statement(
            'ALTER TABLE earnings_queries ADD CONSTRAINT earnings_queries_body_check
             CHECK (char_length(btrim(body)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_queries');
    }
};
