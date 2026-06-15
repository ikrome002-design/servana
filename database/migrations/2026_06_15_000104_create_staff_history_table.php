<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * staff_history — append-only role/branch/status trail (Plan §7.1, Scope §3.4
 * "Role/Branch/Status History").
 *
 * Append-only by convention: only `created_at`, and no UPDATE/DELETE route ever
 * targets this table. The richer tamper-evident audit_logs chain is Phase 8/19.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_history', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->cascadeOnDelete();
            $table->string('field', 30);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('approval_status', 20)->default('n/a');
            $table->timestamp('created_at')->nullable();

            $table->index(['staff_profile_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE staff_history ADD CONSTRAINT staff_history_field_check
             CHECK (field IN ('role','branch','status','employment_status','service_eligibility','availability'))"
        );
        DB::statement(
            "ALTER TABLE staff_history ADD CONSTRAINT staff_history_approval_status_check
             CHECK (approval_status IN ('n/a','pending','approved','rejected'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_history');
    }
};
