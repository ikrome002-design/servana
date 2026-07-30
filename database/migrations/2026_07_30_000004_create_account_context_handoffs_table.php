<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * account_context_handoffs — the single-use, short-lived, hashed credential that carries a user
 * from a source account host to a target account host (Phase UI-03; ADR-018 step 3–10; UI/UX plan
 * §5.3). Canonical DDL: docs/architecture/data-dictionary/sessions-and-account-switching.md.
 *
 * The token is a BEARER CREDENTIAL and is treated as one: 64 random bytes, SHA-256 at rest,
 * 120-second expiry, consumed atomically under a row lock, bound to its exact target.
 *
 * THE ROW CARRIES NO PERMISSION SNAPSHOT AND NO CLIENT-ASSERTED ROLE. Every target authority
 * (user status, merchant status, membership, role, branch) is re-read from the database at consume
 * time — that re-read is what makes the switch safe (ADR-018 step 7): a role revoked one second
 * ago cannot be carried across.
 *
 * `ip_hash` / `user_agent_hash` are minimized forensics, mirroring the Phase 5 Magic Link table:
 * enough to correlate an abuse pattern, never enough to retain the address or fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_context_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_session_family_id')->constrained('session_families')->cascadeOnDelete();
            $table->foreignId('source_host_session_id')->nullable()->constrained('host_sessions')->nullOnDelete();
            $table->string('source_account_key', 64);
            $table->string('target_account_key', 64);
            $table->string('target_host', 253);
            $table->string('environment', 16);
            $table->foreignId('target_merchant_id')->nullable()->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('target_merchant_user_id')->nullable()->constrained('merchant_users')->cascadeOnDelete();
            $table->foreignId('target_branch_id')->nullable()->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('redirect_path', 512)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidated_reason', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'consumed_at']);
            $table->index('expires_at');
            $table->index('source_session_family_id', 'account_context_handoffs_family_index');
        });

        DB::statement(
            "ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_source_account_check
             CHECK (source_account_key IN (
                 'merchant_administrator','super_administrator','merchant_branch','merchant_finance',
                 'merchant_human_resource','merchant_front_office','merchant_personnel','merchant_audit'))"
        );
        DB::statement(
            "ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_target_account_check
             CHECK (target_account_key IN (
                 'merchant_administrator','super_administrator','merchant_branch','merchant_finance',
                 'merchant_human_resource','merchant_front_office','merchant_personnel','merchant_audit'))"
        );
        DB::statement(
            "ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_environment_check
             CHECK (environment IN ('production','staging','local','testing'))"
        );
        DB::statement(
            "ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_invalidated_reason_check
             CHECK (invalidated_reason IS NULL OR invalidated_reason IN (
                 'expired','replayed','wrong_host','wrong_environment','target_unavailable',
                 'family_revoked','source_session_revoked','user_ineligible','unsafe_redirect','superseded'))"
        );
        DB::statement(
            'ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_expiry_check
             CHECK (expires_at > created_at)'
        );
        // A token is consumed OR invalidated, never both — that is what makes "was this replayed?"
        // an answerable question rather than a guess.
        DB::statement(
            'ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_terminal_state_check
             CHECK (NOT (consumed_at IS NOT NULL AND invalidated_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_invalidation_consistency_check
             CHECK ((invalidated_at IS NULL) = (invalidated_reason IS NULL))'
        );
        DB::statement(
            "ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_platform_merchant_check
             CHECK ((target_account_key = 'super_administrator') = (target_merchant_id IS NULL))"
        );
        DB::statement(
            'ALTER TABLE account_context_handoffs ADD CONSTRAINT account_context_handoffs_branch_requires_merchant_check
             CHECK (target_branch_id IS NULL OR target_merchant_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('account_context_handoffs');
    }
};
