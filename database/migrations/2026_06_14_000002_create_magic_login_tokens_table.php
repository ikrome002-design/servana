<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic Link token persistence (Plan §7.1 magic_login_tokens, §9.1).
 *
 * Security invariants (Plan §3 rule 14):
 *  - Only the SHA-256 hash of the 64-byte random token is stored; the raw token
 *    never touches the database.
 *  - Single-use: consumed atomically via an `UPDATE … WHERE consumed_at IS NULL`
 *    that must affect exactly one row (MagicLinkTokenService).
 *  - 15-minute expiry (`expires_at`).
 *  - Invalidatable: suspension/deactivation sets `invalidated_at` (Plan §9.2;
 *    wired to the lifecycle service in Phase 7).
 *
 * `intended_merchant_id` from Plan §7.1 is deferred to Phase 6 (no merchants
 * table yet); the token is bound to the email now and to tenant context later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_login_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Email the link was issued for (normalized lower-case). Not a FK:
            // a request for a non-existent email writes no row, but eligibility
            // is re-checked at consume time regardless.
            $table->string('email');
            // SHA-256 hex digest of the raw token — 64 chars. Never the raw token.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            // Request context for abuse forensics (Plan §9.3). User agent is
            // hashed (not stored raw) to avoid retaining fingerprintable PII.
            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('expires_at');
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_login_tokens');
    }
};
