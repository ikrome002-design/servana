<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time MFA recovery codes (Plan §13.5 mfa_recovery_codes, §18; Phase R3,
 * REM-MFA-001). Canonical DDL: docs/architecture/data-dictionary/
 * core-identity-and-tenancy.md.
 *
 * Security invariants (Plan §9 rule 13):
 *  - Only the SHA-256 hash of a high-entropy raw code is stored; the raw code is
 *    shown once at generation and never persisted/logged.
 *  - Single-use, enforced by an atomic conditional UPDATE that must affect
 *    exactly one row (RecoveryCodeManager::consume).
 *  - Generated only after successful TOTP confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            // SHA-256 hex digest of the raw recovery code — never the raw code.
            $table->char('code_hash', 64)->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // "This user's still-usable codes" lookups.
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
    }
};
