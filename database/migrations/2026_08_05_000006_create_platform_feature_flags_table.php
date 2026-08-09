<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_feature_flags — per-environment rollout state (COR-UI08-001 §12; Phase UI-08).
 * Canonical DDL: docs/architecture/data-dictionary/platform-governance.md; lifecycle:
 * docs/architecture/state-machines/platform-feature-flag.md.
 *
 * A FLAG IS AN ADDITIONAL RESTRICTIVE CONTROL, NEVER AN AUTHORIZATION MECHANISM. It can turn an
 * otherwise-authorized capability OFF; it can never turn an unauthorized capability ON. Permission,
 * entitlement, billing state and account context are evaluated independently and are never consulted
 * by, or replaced by, flag evaluation.
 *
 * THE CATALOGUE ITSELF IS CODE (`config/platform-feature-flags.php`), so this table only ever holds
 * STATE for a key that already exists there; an unknown key fails closed at the service boundary
 * and no row can bring one into being.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id. UNIQUE(flag_key, environment) means environments
 * never share state — enabling something in staging can never enable it in production.
 * `rollout_basis_points` is an integer 0…10000, never a float percentage. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('flag_key', 64);
            $table->string('environment', 16);
            $table->string('state', 16)->default('inactive');
            $table->integer('rollout_basis_points')->default(0);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->integer('version')->default(1);
            $table->char('approved_configuration_hash', 64)->nullable();
            $table->unsignedBigInteger('applied_change_request_id')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['flag_key', 'environment'], 'platform_feature_flags_key_environment_unique');
            $table->index(['environment', 'state']);
        });

        DB::statement(
            "ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_state_check
             CHECK (state IN ('inactive','scheduled','active','paused','retired'))"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_environment_check
             CHECK (environment IN ('production','staging','local','testing'))"
        );
        // Integer basis points only — a float percentage would make a rollout irreproducible.
        DB::statement(
            'ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_rollout_check
             CHECK (rollout_basis_points >= 0 AND rollout_basis_points <= 10000)'
        );
        DB::statement(
            'ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_dating_check
             CHECK (effective_to IS NULL OR (effective_from IS NOT NULL AND effective_to > effective_from))'
        );
        // A scheduled or active flag must say WHEN it applies.
        DB::statement(
            "ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_scheduled_check
             CHECK (state NOT IN ('scheduled','active') OR effective_from IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_version_check
             CHECK (version >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_feature_flags');
    }
};
