<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * session_families — the server-side link between one user's browser sessions across the eight
 * account hosts (Phase UI-03; ADR-018; UI/UX plan §5.2). Canonical DDL:
 * docs/architecture/data-dictionary/sessions-and-account-switching.md.
 *
 * ADR-018 rejects a shared `.servana.ke` cookie precisely because it makes per-host revocation
 * impossible. This table is what replaces it: cookies stay host-only, and the FAMILY is what
 * global logout, suspension, role removal and branch removal act on — once, for every host.
 *
 * IDENTITY-OWNED, NOT TENANT-OWNED. A family may legitimately span merchants, so a `merchant_id`
 * column would be wrong and `BelongsToMerchant` deliberately does not apply.
 *
 * CONTAINS NO SECRET AND NO PERMISSION SNAPSHOT. `ulid` is a non-secret handle, never a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_families', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('environment', 16);
            // Monotonic lifecycle counter: bumped on every revocation so a concurrent writer
            // cannot resurrect a family that was revoked between its read and its write.
            $table->integer('lifecycle_version')->default(1);
            $table->timestampTz('last_activity_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at']);
            $table->index('revoked_at');
        });

        DB::statement(
            "ALTER TABLE session_families ADD CONSTRAINT session_families_environment_check
             CHECK (environment IN ('production','staging','local','testing'))"
        );
        DB::statement(
            "ALTER TABLE session_families ADD CONSTRAINT session_families_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout'))"
        );
        // A revocation is either fully recorded or absent — never half-stated.
        DB::statement(
            'ALTER TABLE session_families ADD CONSTRAINT session_families_revocation_consistency_check
             CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL))'
        );
        DB::statement(
            'ALTER TABLE session_families ADD CONSTRAINT session_families_lifecycle_version_check
             CHECK (lifecycle_version >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('session_families');
    }
};
