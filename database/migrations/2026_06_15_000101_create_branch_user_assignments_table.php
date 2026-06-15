<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_user_assignments — branch scope per membership (Plan §7.1, §8.2).
 *
 * Every branch-scoped role (branch_manager, hr, finance, front_office,
 * personnel, audit) needs an `active` row here to touch branch data; Merchant
 * Admin sees all branches of its own merchant by role and needs no row. A
 * partial unique index enforces a single ACTIVE assignment per (member, branch)
 * while preserving revoked history rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user_assignments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_user_id')->constrained('merchant_users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('merchant_user_id');
        });

        DB::statement(
            "ALTER TABLE branch_user_assignments ADD CONSTRAINT branch_user_assignments_status_check
             CHECK (status IN ('active','revoked'))"
        );

        // One ACTIVE assignment per (member, branch); revoked rows are unconstrained.
        DB::statement(
            "CREATE UNIQUE INDEX branch_user_assignments_active_unique
             ON branch_user_assignments (merchant_user_id, branch_id)
             WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user_assignments');
    }
};
