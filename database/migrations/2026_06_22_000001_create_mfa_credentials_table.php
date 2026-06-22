<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP MFA credential (Plan §13.5 mfa_credentials, §17, §18; Phase R3,
 * REM-MFA-001). Canonical DDL: docs/architecture/data-dictionary/
 * core-identity-and-tenancy.md.
 *
 * Identity-owned (no merchant_id): MFA is a property of the user, resolved
 * before tenant context (Plan §18 enforcement order). One TOTP authenticator
 * per user via UNIQUE (user_id, type).
 *
 * Security invariants (Plan §9 rule 13):
 *  - `secret_encrypted` holds the TOTP secret encrypted at rest (Laravel
 *    `encrypted` cast); the plaintext secret never touches the database/logs.
 *  - `last_used_timestep` is the RFC 6238 replay-prevention state: a new code
 *    must verify with a strictly newer time-step, so a code cannot be replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_credentials', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('type', 20)->default('totp');
            // Encrypted TOTP shared secret — never the plaintext secret.
            $table->text('secret_encrypted');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            // RFC 6238 replay guard: time-step index of the last accepted code.
            $table->bigInteger('last_used_timestep')->nullable();
            $table->timestamps();

            // One active credential per user (one TOTP authenticator per user).
            $table->unique(['user_id', 'type']);
        });

        // Status enum backed by a DB CHECK (guardrail §6.7 / Plan §13.1).
        DB::statement("ALTER TABLE mfa_credentials ADD CONSTRAINT mfa_credentials_type_check CHECK (type IN ('totp'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_credentials');
    }
};
