<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * financial_period_locks — database-backed financial period locks (Plan §46; ADR-0007
 * §Decision 2/3; Gate F; Phase 18B). Merchant-owned with an optional branch scope
 * (branch_id null = merchant-wide). Replaces the Phase 17 always-open
 * UnlockedPeriodLockRepository via DatabasePeriodLockRepository.
 *
 * A financial mutation whose Africa/Nairobi business date falls inside a `locked` row
 * (merchant-wide OR matching branch) returns 423 financial_period_locked. Finance owns
 * lock creation + reopen execution; a Merchant Administrator approves exceptional
 * reopen only where exception_required is set; the requester may not approve. A
 * btree_gist EXCLUDE constraint rejects overlapping ACTIVE locks for the same scope.
 * See docs/architecture/state-machines/financial-period-lock.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('financial_period_locks', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('locked');
            $table->boolean('exception_required')->default(false);
            $table->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('locked_at');
            $table->foreignId('reopen_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopen_requested_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->foreignId('reopen_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopen_approved_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'branch_id', 'period_start', 'period_end'], 'financial_period_locks_scope_range_index');
            $table->unique(['id', 'merchant_id'], 'financial_period_locks_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE financial_period_locks ADD CONSTRAINT financial_period_locks_status_check
             CHECK (status IN ('open','locked','reopened'))"
        );
        DB::statement(
            'ALTER TABLE financial_period_locks ADD CONSTRAINT financial_period_locks_range_check
             CHECK (period_start <= period_end)'
        );
        // Maker/checker on the exceptional reopen: approver never the requester.
        DB::statement(
            'ALTER TABLE financial_period_locks ADD CONSTRAINT financial_period_locks_reopen_maker_checker_check
             CHECK (reopen_approved_by IS NULL OR reopen_approved_by <> reopen_requested_by)'
        );
        // reopened_* only for a reopened row.
        DB::statement(
            "ALTER TABLE financial_period_locks ADD CONSTRAINT financial_period_locks_reopened_check
             CHECK ((status = 'reopened') = (reopened_by IS NOT NULL AND reopened_at IS NOT NULL))"
        );

        // No overlapping ACTIVE lock for the same scope (merchant + normalized branch
        // key). Inclusive daterange: two locks covering any shared day conflict.
        DB::statement(
            "ALTER TABLE financial_period_locks
             ADD CONSTRAINT financial_period_locks_no_overlap
             EXCLUDE USING gist (
                 merchant_id WITH =,
                 (COALESCE(branch_id, 0)) WITH =,
                 daterange(period_start, period_end, '[]') WITH &&
             )
             WHERE (status = 'locked')"
        );

        // Composite consistency for the optional branch scope.
        DB::statement(
            'ALTER TABLE financial_period_locks
             ADD CONSTRAINT financial_period_locks_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_period_locks');
    }
};
