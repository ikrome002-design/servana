<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_access_invitations — the Magic Link-compatible invitation that admits a person to
 * internal Citrus Labs platform access (COR-UI08-001 §11; Phase UI-08). Canonical DDL:
 * docs/architecture/data-dictionary/platform-governance.md; lifecycle:
 * docs/architecture/state-machines/platform-access-invitation.md.
 *
 * WHY NOT `staff_invitations` (proven, not assumed): that table's `merchant_id` and `branch_id` are
 * NOT NULL and `staff_invitations_role_check` restricts `role` to the six merchant staff roles, so
 * `super_admin` is structurally unrepresentable there. Inviting a platform administrator through it
 * would require inventing a merchant and a branch for a person who must never hold either.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (registered in TenantOwnership).
 *
 * ONLY THE SHA-256 HASH OF THE TOKEN IS STORED (Plan §3 rule 14). The raw 64-byte token lives only
 * inside the emailed link; it is never persisted, logged, audited or returned by any Resource.
 * Consumption is atomic (SELECT FOR UPDATE + a conditional single-use update), resend ROTATES the
 * secret, and `purpose` + `environment` bind the credential so it cannot be replayed into another
 * context. No password, OTP or WebAuthn credential is introduced anywhere in this path.
 *
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_invitations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('email');
            $table->string('role_key', 32);
            $table->string('purpose', 32)->default('platform_access');
            $table->string('environment', 16);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->smallInteger('resend_count')->default(0);
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        // The launch platform role model is super_admin ONLY (COR-UI08-001 §11.1).
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_role_key_check
             CHECK (role_key IN ('super_admin'))"
        );
        // Purpose binding: this credential can grant nothing else.
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_purpose_check
             CHECK (purpose IN ('platform_access'))"
        );
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_environment_check
             CHECK (environment IN ('production','staging','local','testing'))"
        );
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_status_check
             CHECK (status IN ('pending','accepted','revoked','expired'))"
        );
        // Normalized address: a duplicate that differs only in case cannot exist.
        DB::statement(
            'ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_email_lower_check
             CHECK (email = lower(email))'
        );
        DB::statement(
            'ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_expiry_check
             CHECK (expires_at > created_at)'
        );
        // A terminal state is never half-stated.
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_accepted_check
             CHECK (status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_revoked_check
             CHECK (status <> 'revoked' OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revocation_reason IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE platform_access_invitations ADD CONSTRAINT platform_access_invitations_resend_count_check
             CHECK (resend_count >= 0)'
        );
        // At most one live invitation per address — a re-invite after revoke/expiry is allowed.
        DB::statement(
            "CREATE UNIQUE INDEX platform_access_invitations_pending_unique
             ON platform_access_invitations (email)
             WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_invitations');
    }
};
