<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * compensation_plan_history — APPEND-ONLY compensation change history (Plan §59, §80; Scope
 * §12 "compensation change history"; Phase 20F). Canonical DDL: docs/architecture/
 * data-dictionary/billing-and-wallet.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id; composite FK → merchant_branches(id, merchant_id)).
 * The plan reference and the denormalized subject both carry composite FKs so history can
 * never point at another merchant's plan or personnel (ADR-002).
 *
 * APPEND-ONLY at the database (the audit_logs precedent, Guardrail 5): a BEFORE UPDATE OR
 * DELETE trigger raises — financial-configuration history is never rewritten and never
 * deleted. There is no updated_at: a row has no mutable column at all. Each row is written in
 * the SAME transaction as the plan transition that produced it.
 *
 * NOT A LEDGER: it records CONFIGURATION changes — never money owed, accrued, earned, or paid.
 * Read via compensation.history.view (HR, branch-scoped). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_plan_history', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('compensation_plan_id')->constrained('personnel_compensation_plans')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('event', 32);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->jsonb('changed_fields')->nullable();
            $table->boolean('was_backdated')->default(false);
            $table->text('change_reason');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->date('effective_from');
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(
                ['merchant_id', 'branch_id', 'staff_profile_id', 'created_at'],
                'compensation_plan_history_subject_index'
            );
            $table->index('compensation_plan_id', 'compensation_plan_history_plan_index');
        });

        // DB-level tenant/branch consistency (ADR-002): merchant_id must match the parent branch.
        DB::statement(
            'ALTER TABLE compensation_plan_history
             ADD CONSTRAINT compensation_plan_history_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE compensation_plan_history
             ADD CONSTRAINT compensation_plan_history_plan_merchant_foreign
             FOREIGN KEY (compensation_plan_id, merchant_id)
             REFERENCES personnel_compensation_plans (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE compensation_plan_history
             ADD CONSTRAINT compensation_plan_history_staff_profile_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // `activated` is the symmetric boundary partner of `expired`: a scheduled plan becoming
        // active at its effective_from is a real state-machine transition and must be visible in
        // compensation history. Recording it as `approved` would collapse two distinct lifecycle
        // moments; recording it as `updated_draft` would be false; omitting it would make
        // activation invisible. See docs/proof/phase-20f.md (Increment 3 correction).
        DB::statement(
            "ALTER TABLE compensation_plan_history ADD CONSTRAINT compensation_plan_history_event_check
             CHECK (event IN ('created','updated_draft','submitted','approved','activated','rejected','cancelled','superseded','expired'))"
        );
        // Status values mirror the plan's own CHECK vocabulary (Scope §12.9).
        DB::statement(
            "ALTER TABLE compensation_plan_history ADD CONSTRAINT compensation_plan_history_from_status_check
             CHECK (from_status IS NULL OR from_status IN ('draft','pending_approval','scheduled','active','expired','superseded','rejected','cancelled'))"
        );
        DB::statement(
            "ALTER TABLE compensation_plan_history ADD CONSTRAINT compensation_plan_history_to_status_check
             CHECK (to_status IN ('draft','pending_approval','scheduled','active','expired','superseded','rejected','cancelled'))"
        );
        // `created` is the only event with no prior status; every other event transitions from one.
        DB::statement(
            "ALTER TABLE compensation_plan_history ADD CONSTRAINT compensation_plan_history_event_from_status_check
             CHECK (
                 (event = 'created' AND from_status IS NULL)
                 OR (event <> 'created' AND from_status IS NOT NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE compensation_plan_history ADD CONSTRAINT compensation_plan_history_change_reason_check
             CHECK (char_length(btrim(change_reason)) > 0)'
        );

        // Append-only guard: history is never rewritten and never deleted (the audit_logs
        // precedent, Guardrail 5). The row carries no mutable column, so EVERY update is blocked.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_plan_history_append_only_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'compensation_plan_history is append-only (DELETE blocked)';
                END IF;

                RAISE EXCEPTION 'compensation_plan_history is append-only (UPDATE blocked)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compensation_plan_history_no_update
                BEFORE UPDATE ON compensation_plan_history
                FOR EACH ROW EXECUTE FUNCTION compensation_plan_history_append_only_guard();

            CREATE TRIGGER compensation_plan_history_no_delete
                BEFORE DELETE ON compensation_plan_history
                FOR EACH ROW EXECUTE FUNCTION compensation_plan_history_append_only_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS compensation_plan_history_no_update ON compensation_plan_history;');
        DB::unprepared('DROP TRIGGER IF EXISTS compensation_plan_history_no_delete ON compensation_plan_history;');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_plan_history_append_only_guard();');
        Schema::dropIfExists('compensation_plan_history');
    }
};
