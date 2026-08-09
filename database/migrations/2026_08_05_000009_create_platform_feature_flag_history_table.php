<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_feature_flag_history — APPEND-ONLY governance history (COR-UI08-001 §12; Phase UI-08).
 * Canonical DDL: docs/architecture/data-dictionary/platform-governance.md.
 *
 * The `platform_feature_flag_history_append_only` trigger raises on UPDATE and on DELETE, always,
 * giving flag governance the same guarantee `audit_logs` has (guardrail 5). "Who turned this on,
 * when, under whose approval, and what exactly changed?" must remain answerable from the record — a
 * history that could be edited would answer nothing.
 *
 * Each row carries before/after configuration, before/after SHA-256 hashes, the actor, the reason
 * and a correlation ULID linking request -> decision -> application. There is no `updated_at`,
 * because a history row is never updated.
 *
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flag_history', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('feature_flag_id')->constrained('platform_feature_flags')->restrictOnDelete();
            $table->foreignId('change_request_id')->nullable()->constrained('platform_feature_flag_change_requests')->restrictOnDelete();
            $table->string('action', 32);
            $table->jsonb('before_configuration')->nullable();
            $table->jsonb('after_configuration')->nullable();
            $table->char('before_hash', 64)->nullable();
            $table->char('after_hash', 64)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestampTz('created_at');

            $table->index(['feature_flag_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE platform_feature_flag_history ADD CONSTRAINT platform_feature_flag_history_action_check
             CHECK (action IN ('created','change_requested','approved','rejected','cancelled','applied','paused','retired','failed'))"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_history ADD CONSTRAINT platform_feature_flag_history_before_object_check
             CHECK (before_configuration IS NULL OR jsonb_typeof(before_configuration) = 'object')"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_history ADD CONSTRAINT platform_feature_flag_history_after_object_check
             CHECK (after_configuration IS NULL OR jsonb_typeof(after_configuration) = 'object')"
        );

        // Append-only, the same guarantee audit_logs carries. Literal statement (no interpolation).
        DB::statement(
            "CREATE OR REPLACE FUNCTION platform_feature_flag_history_append_only() RETURNS trigger AS $$
             BEGIN
                 RAISE EXCEPTION 'platform_feature_flag_history is append-only: a governance history row is never updated or deleted';
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER platform_feature_flag_history_append_only
             BEFORE UPDATE OR DELETE ON platform_feature_flag_history
             FOR EACH ROW EXECUTE FUNCTION platform_feature_flag_history_append_only()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS platform_feature_flag_history_append_only ON platform_feature_flag_history');
        DB::statement('DROP FUNCTION IF EXISTS platform_feature_flag_history_append_only()');
        Schema::dropIfExists('platform_feature_flag_history');
    }
};
