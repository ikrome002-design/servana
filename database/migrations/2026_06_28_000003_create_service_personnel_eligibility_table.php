<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * service_personnel_eligibility — HR-owned eligibility gate (Plan §13.7, §39; 15A).
 *
 * Branch-owned junction (no ulid; not directly route-bound by ULID — managed via
 * service/personnel-scoped routes). One row per (service, staff_profile) pair;
 * assign/revoke toggles `active`. Composite FKs to merchant_branches, services
 * and staff_profiles force SAME-MERCHANT linkage in the database; same-BRANCH
 * linkage (service.branch == staff.primary_branch) is enforced by the
 * AssignEligibility action + Form Request. `personnel.eligibility.manage` (HR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_personnel_eligibility', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['service_id', 'staff_profile_id'], 'service_personnel_eligibility_pair_unique');
            $table->index(['merchant_id', 'branch_id']);
            $table->index('branch_id');
            $table->index('staff_profile_id');
        });

        DB::statement(
            'ALTER TABLE service_personnel_eligibility
             ADD CONSTRAINT service_personnel_eligibility_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_personnel_eligibility
             ADD CONSTRAINT service_personnel_eligibility_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_personnel_eligibility
             ADD CONSTRAINT service_personnel_eligibility_staff_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_personnel_eligibility');
    }
};
