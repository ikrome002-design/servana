<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_users — membership (Plan §7.1, §8.1, §10.2).
 *
 * One row = one user holding one account-type role in one merchant. All
 * authorization derives from an `active` row here (Plan §8.1). The registering
 * owner gets a `merchant_admin` / `active` row at self-registration; initial
 * Branch/HR staff added during first-time setup get `invited` rows (they cannot
 * authenticate until Phase 7's accept flow activates them — eligibility check 4).
 *
 * Launch rule: one membership per user (unique merchant_id+user_id; the single
 * active row is resolved by ResolveTenantContext). Role and status are
 * enum-backed (MerchantUserRole / MerchantUserStatus) + DB CHECKs.
 *
 * `last_branch_id` is the UX branch-selector persistence field (Plan §7.1). In
 * Phase 6 it also records the branch chosen for an invited staff member during
 * setup step 5; Phase 7 promotes that into a real branch_user_assignment row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_users', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('invited');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_branch_id')->nullable()->constrained('merchant_branches')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'user_id']);
            $table->index(['merchant_id', 'role', 'status']);
            $table->index('user_id');
        });

        DB::statement(
            "ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_role_check
             CHECK (role IN ('merchant_admin','branch_manager','hr','finance','front_office','personnel','audit'))"
        );
        DB::statement(
            "ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_status_check
             CHECK (status IN ('invited','active','suspended','deactivated'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_users');
    }
};
