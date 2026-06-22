<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency + replay store (Plan §13.5 corrected schema [Correction 3.2], §24.4,
 * §8 ADR-003; Phase R4, REM-IDEMP-001). Canonical DDL:
 * docs/architecture/data-dictionary/core-identity-and-tenancy.md.
 *
 * Replaces the prior INVALID definition (no `key_hash`, wrong unique key) that
 * Plan §4 finding 9 contradicted; that table never existed in the repo, so this
 * is a clean forward-only create.
 *
 * Security invariants (Plan §9 rules 12, 13, 15):
 *  - only SHA-256(raw Idempotency-Key) is stored (`key_hash`); the raw key never
 *    touches the database;
 *  - the replay-safe response body is encrypted at rest (`response_body_encrypted`);
 *  - `response_headers` holds an allowlist of safe headers only — never cookies,
 *    auth, session, signed-URL or debug headers;
 *  - `UNIQUE (idempotency_scope, key_hash)` + row locking are the correctness
 *    boundary for concurrency (not process memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();

            // Deterministic scope (embeds merchant/user/provider identity) so the
            // same raw key cannot collide across tenants/actors/environments.
            $table->string('idempotency_scope', 191);
            // SHA-256 hex of the raw Idempotency-Key — never the raw key.
            $table->char('key_hash', 64);

            // Forensic actor/tenant links (nullable; platform/webhook scopes have none).
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->restrictOnDelete();

            $table->string('route_name', 191);
            $table->string('http_method', 10);
            $table->string('request_content_type', 100)->nullable();
            // SHA-256 canonical request hash (method+route+path params+content type+body).
            $table->char('request_hash', 64);

            $table->string('state', 20);

            // Replay-safe stored response.
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body_encrypted')->nullable();

            // Lock + lifecycle.
            $table->timestamp('locked_at');
            $table->timestamp('lock_expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error_code', 100)->nullable();

            // Retention horizon for pruning.
            $table->timestamp('expires_at');
            $table->timestamps();

            // Correctness boundary: one row per (scope, key).
            $table->unique(['idempotency_scope', 'key_hash']);
            // Expired-lock recovery scans + prune scans.
            $table->index(['state', 'lock_expires_at']);
            $table->index('expires_at');
        });

        // State enum backed by a DB CHECK (guardrail §6.7 / Plan §13.1).
        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_state_check CHECK (state IN ('processing','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
