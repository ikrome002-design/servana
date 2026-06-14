<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Auth-owned expansion of the users table (Plan §9, A5, §7.1 partial).
 *
 * Forward-only / expand (CLAUDE.md guardrail 12): the original users table is a
 * shipped Phase 1 migration and is never edited. This adds only the columns the
 * Phase 5 authentication model genuinely needs:
 *
 *  - `ulid`          public identifier so /me never leaks the bigint PK (A5).
 *  - `status`        user-level lifecycle for Scope §2.3 checks 3 & 5 (active /
 *                    not suspended) and the §9.2 suspension-revocation rule.
 *  - `last_login_at` set on each successful Magic Link consume (Plan §9.1).
 *
 * The full account model (first_name/last_name/display_name, phone,
 * is_platform_staff, mfa_secret, merchants, merchant_users, …) is owned by
 * Phase 6/7 and will expand this table further — it is NOT created here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('ulid', 26)->nullable()->after('id');
            $table->string('status', 20)->default('active')->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('status');
            // Servana has no passwords (Plan A3 / §7.1 "No password column"). The
            // column from the Phase 1 default migration is made nullable so no
            // password is ever required; it is never written to. Phase 6 may drop
            // it entirely when it reshapes the users table per §7.1.
            $table->string('password')->nullable()->change();
        });

        // Backfill ulid for any pre-existing rows, then enforce uniqueness.
        foreach (DB::table('users')->whereNull('ulid')->orderBy('id')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['ulid' => (string) Str::ulid()]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('ulid');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['ulid']);
            $table->dropIndex(['status']);
            $table->dropColumn(['ulid', 'status', 'last_login_at']);
        });
    }
};
