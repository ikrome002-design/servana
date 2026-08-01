<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Magic Link host binding (Phase UI-03; ADR-019; UI/UX plan §5.1, §18.5).
 * Canonical DDL: docs/architecture/data-dictionary/sessions-and-account-switching.md.
 *
 * ADR-019 adds binding; it removes nothing. Hashing at rest, the 15-minute expiry, the atomic
 * single-use consume and the seven Scope §2.3 eligibility checks are all untouched. The shipped
 * Phase 5 migration is NOT edited (guardrail 12) — this is a forward-only expand.
 *
 * EXPAND → BACKFILL → CONSTRAIN. The columns arrive nullable because the table already has rows.
 * The migration then invalidates every still-usable row, so no UNBOUND credential survives the
 * upgrade: a link emailed before host binding existed cannot satisfy a binding it never carried,
 * and silently accepting it would leave exactly the cross-host substitution hole ADR-019 closes.
 * Historical consumed/invalidated rows keep their nulls, so no audit history is rewritten.
 *
 * The shaped CHECK then makes the invariant permanent without a destructive NOT NULL: any row that
 * is still USABLE (neither consumed nor invalidated) must carry the complete binding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magic_login_tokens', function (Blueprint $table): void {
            // The bound user. Nullable for the historical rows only; every new row sets it.
            $table->foreignId('user_id')->nullable()->after('email')->constrained('users')->cascadeOnDelete();

            $table->string('account_key', 64)->nullable()->after('user_id');
            $table->string('intended_host', 253)->nullable()->after('account_key');
            $table->string('environment', 16)->nullable()->after('intended_host');
            $table->string('redirect_path', 512)->nullable()->after('environment');
            $table->string('audience', 32)->nullable()->after('redirect_path');

            $table->index('user_id');
            $table->index(['account_key', 'environment']);
        });

        // Closed vocabularies. Literal SQL, no interpolation (repo convention).
        DB::statement(
            "ALTER TABLE magic_login_tokens ADD CONSTRAINT magic_login_tokens_account_key_check
             CHECK (account_key IS NULL OR account_key IN (
                 'merchant_administrator','super_administrator','merchant_branch','merchant_finance',
                 'merchant_human_resource','merchant_front_office','merchant_personnel','merchant_audit'))"
        );
        DB::statement(
            "ALTER TABLE magic_login_tokens ADD CONSTRAINT magic_login_tokens_environment_check
             CHECK (environment IS NULL OR environment IN ('production','staging','local','testing'))"
        );
        DB::statement(
            "ALTER TABLE magic_login_tokens ADD CONSTRAINT magic_login_tokens_audience_check
             CHECK (audience IS NULL OR audience IN ('browser_login'))"
        );

        // Fail closed on the upgrade path: retire every link that predates the binding.
        DB::table('magic_login_tokens')
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'updated_at' => now()]);

        // A row that is still usable MUST be fully bound. Consumed/invalidated history is exempt.
        DB::statement(
            'ALTER TABLE magic_login_tokens ADD CONSTRAINT magic_login_tokens_binding_complete_check
             CHECK (
                 invalidated_at IS NOT NULL
              OR consumed_at IS NOT NULL
              OR (user_id IS NOT NULL
                  AND account_key IS NOT NULL
                  AND intended_host IS NOT NULL
                  AND environment IS NOT NULL
                  AND audience IS NOT NULL)
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE magic_login_tokens DROP CONSTRAINT IF EXISTS magic_login_tokens_binding_complete_check');
        DB::statement('ALTER TABLE magic_login_tokens DROP CONSTRAINT IF EXISTS magic_login_tokens_audience_check');
        DB::statement('ALTER TABLE magic_login_tokens DROP CONSTRAINT IF EXISTS magic_login_tokens_environment_check');
        DB::statement('ALTER TABLE magic_login_tokens DROP CONSTRAINT IF EXISTS magic_login_tokens_account_key_check');

        Schema::table('magic_login_tokens', function (Blueprint $table): void {
            $table->dropIndex(['account_key', 'environment']);
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['account_key', 'intended_host', 'environment', 'redirect_path', 'audience']);
        });
    }
};
