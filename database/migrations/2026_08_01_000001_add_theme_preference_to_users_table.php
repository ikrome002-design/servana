<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User theme preference (Phase UI-04; ADR-021 §3; UI/UX plan §12.2).
 * Canonical DDL: docs/architecture/data-dictionary/core-identity-and-tenancy.md.
 *
 * EXPAND ONLY. The shipped `users` migration is not edited (CLAUDE.md guardrail 12).
 *
 * The column is NULLABLE with NO DEFAULT, and that is the whole point: `null` means "this person
 * has never made an explicit choice", which ADR-021 rule 2 resolves to LIGHT. Storing `'light'`
 * as a column default would make "never chose" indistinguishable from "chose light", and a future
 * default change would then silently rewrite everybody's unexpressed preference.
 *
 * The vocabulary is closed at the database, not only in PHP (Plan §9 rule 7). `system` / `auto`
 * are deliberately unrepresentable: ADR-021 forbids the operating-system colour scheme from
 * selecting the theme, so there must be no way to persist "follow the OS".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme_preference', 5)->nullable()->after('is_platform_staff');
        });

        // Literal SQL, no interpolation (repo convention).
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_theme_preference_check
             CHECK (theme_preference IS NULL OR theme_preference IN ('light','dark'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_theme_preference_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme_preference');
        });
    }
};
