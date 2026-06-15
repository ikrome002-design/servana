<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * staff_profiles — 1:1 with a staff merchant_users row (Plan §7.1, Scope §3.4).
 *
 * Duplicate Staff Prevention (Scope §3.4): email is already unique on users;
 * phone is enforced unique among ACTIVE staff platform-wide via a partial unique
 * index on a denormalized `is_active` flag maintained by StaffLifecycleService.
 * `profile_photo_path` is a Phase 23 upload seam (nullable, never written now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_user_id')->unique()->constrained('merchant_users')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('primary_branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('display_name', 120);
            $table->string('phone', 32);
            $table->string('profile_photo_path', 255)->nullable();
            $table->string('role_title', 80)->nullable();
            $table->string('employment_type', 20)->default('full_time');
            $table->string('employment_status', 20)->default('employed');
            $table->date('start_date')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'primary_branch_id']);
        });

        DB::statement(
            "ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_employment_type_check
             CHECK (employment_type IN ('full_time','part_time','contract','commission_only'))"
        );
        DB::statement(
            "ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_employment_status_check
             CHECK (employment_status IN ('employed','on_leave','terminated'))"
        );

        // Phone unique among active staff platform-wide (Scope §3.4 Duplicate Staff).
        DB::statement(
            'CREATE UNIQUE INDEX staff_profiles_active_phone_unique
             ON staff_profiles (phone)
             WHERE is_active = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
