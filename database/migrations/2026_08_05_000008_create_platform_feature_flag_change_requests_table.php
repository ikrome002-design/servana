<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_feature_flag_change_requests — maker/checker for flag changes (COR-UI08-001 §12.3;
 * Phase UI-08). Canonical DDL: docs/architecture/data-dictionary/platform-governance.md;
 * lifecycle: docs/architecture/state-machines/platform-feature-flag-change-request.md.
 *
 * MAKER/CHECKER IS A DATABASE CONSTRAINT, NOT A CONVENTION:
 *
 *     CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)
 *
 * A self-approved production change CANNOT EXIST AS A ROW. Even a bypassed policy, controller and
 * service layer could not persist one.
 *
 * `impact_statement`, `rollback_plan`, `health_criterion` and `reason` are NOT NULL, so a
 * production-sensitive change with no stated impact or no rollback plan is unrepresentable rather
 * than merely discouraged. A partial unique index permits at most ONE pending request per flag, so
 * two operators cannot queue contradictory changes.
 *
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_flag_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('feature_flag_id')->constrained('platform_feature_flags')->restrictOnDelete();
            $table->string('status', 12)->default('pending');
            $table->jsonb('proposed_configuration');
            $table->char('proposed_configuration_hash', 64);
            $table->text('impact_statement');
            $table->text('rollback_plan');
            $table->text('health_criterion');
            $table->string('reason', 500);
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_status_check
             CHECK (status IN ('pending','approved','rejected','cancelled','applied','failed'))"
        );
        // The maker/checker guarantee: a self-approved change is not a representable row.
        DB::statement(
            'ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_maker_checker_check
             CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> requested_by_user_id)'
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_approved_check
             CHECK (status NOT IN ('approved','applied') OR (approved_by_user_id IS NOT NULL AND decided_at IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_rejected_check
             CHECK (status <> 'rejected' OR (decided_at IS NOT NULL AND decision_note IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_applied_check
             CHECK (status <> 'applied' OR applied_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_failed_check
             CHECK (status <> 'failed' OR failure_reason IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE platform_feature_flag_change_requests ADD CONSTRAINT platform_feature_flag_change_requests_config_object_check
             CHECK (jsonb_typeof(proposed_configuration) = 'object')"
        );
        // At most one pending request per flag — two operators cannot queue contradictory changes.
        DB::statement(
            "CREATE UNIQUE INDEX platform_feature_flag_change_requests_pending_unique
             ON platform_feature_flag_change_requests (feature_flag_id)
             WHERE status = 'pending'"
        );

        // The flag's applied-request pointer can only be constrained now that this table exists.
        DB::statement(
            'ALTER TABLE platform_feature_flags ADD CONSTRAINT platform_feature_flags_applied_change_request_foreign
             FOREIGN KEY (applied_change_request_id) REFERENCES platform_feature_flag_change_requests (id) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE platform_feature_flags DROP CONSTRAINT IF EXISTS platform_feature_flags_applied_change_request_foreign');
        Schema::dropIfExists('platform_feature_flag_change_requests');
    }
};
