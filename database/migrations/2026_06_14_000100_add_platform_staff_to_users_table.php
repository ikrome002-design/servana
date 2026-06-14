<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-model expansion of users (Plan §7.1 / Phase 6).
 *
 * Forward-only / expand (CLAUDE.md guardrail 12): the shipped users migrations
 * are never edited. Phase 6 needs the `is_platform_staff` flag so the Magic Link
 * eligibility check 2 ("active membership in a merchant tenant OR is platform
 * staff", Scope §2.3) can be evaluated now that the tenancy schema exists.
 *
 * The full §7.1 users shape (first_name/last_name/display_name, phone, mfa_*,
 * soft-delete anonymization) is still deferred — Phase 6 adds only what the
 * tenant/membership model genuinely requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Super Admin scope flag (Plan §7.1). Never mass-assigned; set by
            // platform seeders only. Drives eligibility check 2 for platform staff.
            $table->boolean('is_platform_staff')->default(false)->after('status');
            $table->index('is_platform_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_platform_staff']);
            $table->dropColumn('is_platform_staff');
        });
    }
};
