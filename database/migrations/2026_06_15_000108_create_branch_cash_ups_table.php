<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_cash_ups — SEAM ONLY (Plan §7.2, Scope §3.3 Cash-Up).
 *
 * Phase 7 creates the table + model so BranchClosureGuard can check for an
 * unresolved cash-up discrepancy. The full cash-up / reconciliation / payment
 * validation workflow is Phase 18 — NO finance logic is implemented here. All
 * money columns are integer minor units (bigint) per the Phase 3 Money rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_cash_ups', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('branch_day_record_id')->nullable()->constrained('branch_day_records')->nullOnDelete();
            $table->bigInteger('expected_total')->default(0);
            $table->json('recorded_totals')->nullable();
            $table->bigInteger('cash_counted')->default(0);
            $table->bigInteger('discrepancy_amount')->default(0);
            $table->string('discrepancy_note')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE branch_cash_ups ADD CONSTRAINT branch_cash_ups_status_check
             CHECK (status IN ('draft','submitted','approved','rejected'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_cash_ups');
    }
};
