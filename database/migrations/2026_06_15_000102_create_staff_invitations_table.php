<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * staff_invitations (Plan §7.1, Scope §3.4 Staff Creation).
 *
 * Security (Plan §3 rule 14): only the SHA-256 hash of the raw invitation token
 * is stored; the raw token lives only in the emailed link. 72-hour expiry. A
 * partial unique index blocks a duplicate PENDING invite for the same
 * merchant+email+role+branch while allowing re-invites after revoke/expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('role_title', 80)->nullable();
            // Service-eligibility seam (HR assigns for personnel — Phase 15 consumes).
            $table->json('service_eligibility_ids')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->smallInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('email');
        });

        DB::statement(
            "ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_role_check
             CHECK (role IN ('branch_manager','hr','finance','front_office','personnel','audit'))"
        );
        DB::statement(
            "ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_status_check
             CHECK (status IN ('pending','accepted','revoked','expired'))"
        );

        // Block duplicate PENDING invitations for the same target.
        DB::statement(
            "CREATE UNIQUE INDEX staff_invitations_pending_unique
             ON staff_invitations (merchant_id, email, role, branch_id)
             WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
