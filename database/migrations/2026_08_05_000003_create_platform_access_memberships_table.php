<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * platform_access_memberships — the lifecycle authority for internal Citrus Labs platform access
 * (COR-UI08-001 §11; Phase UI-08). Canonical DDL:
 * docs/architecture/data-dictionary/platform-governance.md; lifecycle:
 * docs/architecture/state-machines/platform-access-membership.md.
 *
 * WHY A TABLE AT ALL (proven, not assumed): `users.is_platform_staff` is a bare boolean. It has no
 * status vocabulary, no invited/active/suspended/deactivated lifecycle, no actor, no reason and no
 * activation/suspension/deactivation timestamps — its own migration says "set by platform seeders
 * only". A boolean cannot answer "who granted this, when, why, and is it currently suspended?".
 *
 * `users.is_platform_staff` IS RETAINED as the eligibility flag and becomes a DERIVED MIRROR of
 * `status = 'active'`, written in the same transaction as every transition. That is what lets every
 * shipped reader — LoginEligibilityService, AccountContextResolver, TenantContextResolver,
 * ResolvePlatformContext, MfaRequirementResolver — keep working byte-for-byte while the membership
 * becomes the lifecycle authority.
 *
 * STRUCTURALLY ABSENT AND NEVER TO BE ADDED: merchant_id, branch_id, staff_profile_id, any
 * permission snapshot, any secret. A platform administrator holds no merchant structure of any
 * kind.
 *
 * BACKFILL: one `active` membership per existing `is_platform_staff = true` user, so the flag and
 * the membership agree from this migration onwards. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_memberships', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Exactly one current platform membership per identity.
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('role_key', 32)->default('super_admin');
            $table->string('status', 20)->default('invited');
            $table->foreignId('invitation_id')->nullable()->constrained('platform_access_invitations')->restrictOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->string('last_action', 32)->nullable();
            $table->string('last_action_reason', 500)->nullable();
            $table->foreignId('last_action_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_action_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_role_key_check
             CHECK (role_key IN ('super_admin'))"
        );
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_status_check
             CHECK (status IN ('invited','active','suspended','deactivated'))"
        );
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_last_action_check
             CHECK (last_action IS NULL OR last_action IN (
                 'invited','activated','suspended','reactivated','deactivated','permissions_changed','sessions_revoked'))"
        );
        // A state is never claimed without the evidence that produced it.
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_active_check
             CHECK (status <> 'active' OR activated_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_suspended_check
             CHECK (status <> 'suspended' OR suspended_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_deactivated_check
             CHECK (status <> 'deactivated' OR deactivated_at IS NOT NULL)"
        );
        // An invited membership has never been activated, so it holds no access.
        DB::statement(
            "ALTER TABLE platform_access_memberships ADD CONSTRAINT platform_access_memberships_invited_check
             CHECK (status <> 'invited' OR activated_at IS NULL)"
        );

        $this->backfillExistingPlatformStaff();
    }

    /**
     * Every user already flagged as platform staff gets an `active` membership, so the derived
     * mirror is true from the first instant the table exists. `invited_at`/`activated_at` are the
     * user's own creation timestamp — the honest statement that this grant predates the lifecycle
     * record — and no actor is attributed, because none is known.
     */
    private function backfillExistingPlatformStaff(): void
    {
        $now = now();

        DB::table('users')
            ->where('is_platform_staff', true)
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->chunkById(500, function ($users) use ($now): void {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'ulid' => (string) Str::ulid(),
                        'user_id' => $user->id,
                        'role_key' => 'super_admin',
                        'status' => 'active',
                        'invitation_id' => null,
                        'invited_by_user_id' => null,
                        'invited_at' => $user->created_at,
                        'activated_at' => $user->created_at,
                        'last_action' => 'activated',
                        'last_action_reason' => 'Backfilled from users.is_platform_staff when the platform access lifecycle record was introduced (COR-UI08-001).',
                        'last_action_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('platform_access_memberships')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_memberships');
    }
};
