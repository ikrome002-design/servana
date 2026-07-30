<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * host_sessions — one row per Laravel browser session, bound to the account context that session
 * was created for (Phase UI-03; ADR-018; UI/UX plan §5.2). Canonical DDL:
 * docs/architecture/data-dictionary/sessions-and-account-switching.md.
 *
 * WHY `session_id` IS STORED RAW. Revocation must DELETE the row from Laravel's `sessions` table,
 * whose primary key IS the session id — a hash could not be used to find it. The value is already
 * stored, in plaintext, in the same database, in `sessions.id`; a second copy therefore adds no new
 * exposure class. It is never logged, audited, serialized or returned by any API (proven by
 * SessionSecretRedactionTest and OwnSessionManagementTest).
 *
 * NO PERMISSION SNAPSHOT. The context columns record WHICH membership/merchant/branch the session
 * belongs to, never WHAT it may do. Permissions are re-resolved per request by PermissionResolver
 * (see AccessRevocationService::forgetAuthorizationCache — there is deliberately no cross-request
 * authorization cache).
 *
 * `mfa_required_at_creation` is EVIDENCE, not authority: the live assertion stays `mfa_verified_at`
 * inside the Laravel session and is never copied across hosts (ADR-018 step 7; Plan §18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('session_family_id')->constrained('session_families')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Not a foreign key: Laravel sweeps `sessions` on its own lottery and the table has no
            // integrity contract we may depend on. Uniqueness is what matters here.
            $table->string('session_id', 255)->unique();
            $table->string('account_key', 64);
            $table->string('host', 253);
            $table->string('environment', 16);
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('merchant_user_id')->nullable()->constrained('merchant_users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->cascadeOnDelete();
            $table->boolean('mfa_required_at_creation')->default(false);
            $table->timestampTz('last_activity_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['session_family_id', 'revoked_at']);
            $table->index(['merchant_id', 'revoked_at']);
            $table->index(['branch_id', 'revoked_at']);
            $table->index(['merchant_user_id', 'revoked_at']);
            $table->index('account_key');
        });

        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_account_key_check
             CHECK (account_key IN (
                 'merchant_administrator','super_administrator','merchant_branch','merchant_finance',
                 'merchant_human_resource','merchant_front_office','merchant_personnel','merchant_audit'))"
        );
        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_environment_check
             CHECK (environment IN ('production','staging','local','testing'))"
        );
        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout'))"
        );
        DB::statement(
            'ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_revocation_consistency_check
             CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL))'
        );
        // The platform context is the ONLY account without a merchant, and every merchant-side
        // context must name its merchant. This is what stops a "merchant_finance session with no
        // merchant" ever existing as a row.
        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_platform_merchant_check
             CHECK ((account_key = 'super_administrator') = (merchant_id IS NULL))"
        );
        DB::statement(
            'ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_branch_requires_merchant_check
             CHECK (branch_id IS NULL OR merchant_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('host_sessions');
    }
};
