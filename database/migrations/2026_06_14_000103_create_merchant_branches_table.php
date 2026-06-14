<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_branches — MINIMAL Phase 6 seam (Plan §7.2 full table is Phase 7).
 *
 * Plan tension resolved per the Phase 6 brief: Phase 6 first-time setup must
 * create "≥1 branch" (Scope §3.2 step 3) so the initial Branch/HR staff have a
 * branch to be assigned to (step 5, with auto-select when only one exists), yet
 * the full branch lifecycle (operating hours, calendar exceptions, day records,
 * cash-ups, closure protection, branch CRUD endpoints, branch_user_assignments)
 * belongs to Phase 7. This migration therefore creates ONLY the branch identity
 * + profile + status columns needed by setup. Phase 7 EXPANDS this table
 * forward-only (it does not recreate it) and adds the operational tables.
 *
 * Status is enum-backed (BranchStatus) + DB CHECK (CLAUDE.md guardrail 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_branches', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 160);
            // Short branch code, unique per merchant (Plan §7.2).
            $table->string('code', 20);
            $table->string('address')->nullable();
            $table->string('town', 80)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('business_category', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'code']);
            $table->index(['merchant_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE merchant_branches ADD CONSTRAINT merchant_branches_status_check
             CHECK (status IN ('active','suspended','archived'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_branches');
    }
};
