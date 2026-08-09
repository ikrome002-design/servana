<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_feature_flag_targets — who a rollout applies to (COR-UI08-001 §12; Phase UI-08).
 * Canonical DDL: docs/architecture/data-dictionary/platform-governance.md.
 *
 * NO ARBITRARY TARGETING LOGIC IS STORABLE, and that is the point. `target_type` is a CLOSED
 * vocabulary and `target_value` is a scalar identifier (a merchant ULID, a plan key, an allowlisted
 * cohort key). There is nowhere to persist an executable predicate, so no stored value can ever be
 * evaluated as code.
 *
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flag_targets', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('feature_flag_id')->constrained('platform_feature_flags')->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->string('target_value', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['feature_flag_id', 'target_type', 'target_value'], 'platform_feature_flag_targets_unique');
        });

        DB::statement(
            "ALTER TABLE platform_feature_flag_targets ADD CONSTRAINT platform_feature_flag_targets_type_check
             CHECK (target_type IN ('merchant','plan','cohort'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_feature_flag_targets');
    }
};
