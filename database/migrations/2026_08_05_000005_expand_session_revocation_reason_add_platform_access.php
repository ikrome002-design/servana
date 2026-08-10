<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expand the closed `revoked_reason` vocabulary on `session_families` and `host_sessions` by
 * exactly one value: `platform_access_sessions_revoked` (COR-UI08-001 §11.7; Phase UI-08).
 *
 * WHY: administrator-initiated session revocation needs a TRUTHFUL reason and the vocabulary had
 * none. `session_revoked_by_owner` means the owner revoked their OWN session; `global_logout` means
 * the user signed out everywhere. Reusing either would write a false forensic record — and these
 * columns exist precisely so that "why was this session ended?" is answerable rather than guessed.
 *
 * Suspension and deactivation are NOT given a new value: they keep using `membership_revoked`,
 * whose own docblock is "the membership backing this context was suspended or removed", which is
 * exactly what a suspended or deactivated platform membership is.
 *
 * EXPAND, NOT EDIT. The shipped Phase UI-03 migrations are not touched (guardrail 12); this
 * migration drops and re-adds the two CHECK constraints with the widened list. Widening a CHECK is
 * backward compatible — older code simply never writes the new value — and no row is rewritten.
 * `SessionSchemaContractTest` asserts the enum and both CHECKs stay identical, so the three cannot
 * drift. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE session_families DROP CONSTRAINT session_families_revoked_reason_check');
        DB::statement(
            "ALTER TABLE session_families ADD CONSTRAINT session_families_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout','platform_access_sessions_revoked'))"
        );

        DB::statement('ALTER TABLE host_sessions DROP CONSTRAINT host_sessions_revoked_reason_check');
        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout','platform_access_sessions_revoked'))"
        );
    }

    public function down(): void
    {
        // Narrowing again would fail while any row carries the new value; forward-repair only.
        DB::statement('ALTER TABLE session_families DROP CONSTRAINT session_families_revoked_reason_check');
        DB::statement(
            "ALTER TABLE session_families ADD CONSTRAINT session_families_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout'))"
        );

        DB::statement('ALTER TABLE host_sessions DROP CONSTRAINT host_sessions_revoked_reason_check');
        DB::statement(
            "ALTER TABLE host_sessions ADD CONSTRAINT host_sessions_revoked_reason_check
             CHECK (revoked_reason IS NULL OR revoked_reason IN (
                 'global_logout','user_suspended','user_deactivated','merchant_suspended',
                 'merchant_deactivated','membership_revoked','role_changed','branch_revoked',
                 'session_revoked_by_owner','current_host_logout'))"
        );
    }
};
