<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `suspension_salary_policy` to personnel_compensation_plans (Plan A-11; §60; §13.12; Phase
 * 20G, G10). Phase 20F shipped the compensation-plan table WITHOUT this canonical §13.12 column;
 * the shipped 20F migration is never edited (ADR-004 / Guardrail 12) — this is the forward-only
 * expand that adds it, as the Increment 1 G10 decision authorized.
 *
 * Settled default `continue` (A-11): salary accrues during suspension. A prospective `pause`
 * override is expressed by SUPERSEDING the plan to a new effective-dated version (never a
 * retroactive edit), so the column is part of a plan version's frozen terms: a second BEFORE
 * UPDATE trigger (additive; the shipped 20F immutability trigger is untouched) blocks changing it
 * once the plan leaves draft. Adding a NOT NULL column with a constant default backfills the 0
 * existing rows to `continue` and is production-safe. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_compensation_plans', function (Blueprint $table): void {
            $table->string('suspension_salary_policy', 16)->default('continue');
        });

        // Backfill any existing rows to the settled default, then enforce NOT NULL + CHECK.
        DB::statement("UPDATE personnel_compensation_plans SET suspension_salary_policy = 'continue' WHERE suspension_salary_policy IS NULL");
        DB::statement('ALTER TABLE personnel_compensation_plans ALTER COLUMN suspension_salary_policy SET NOT NULL');
        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_suspension_salary_policy_check
             CHECK (suspension_salary_policy IN ('continue','pause'))"
        );

        // Frozen once non-draft (supersede-not-edit, consistent with the shipped F7 trigger).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION personnel_compensation_plans_suspension_policy_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'draft'
                   AND NEW.suspension_salary_policy IS DISTINCT FROM OLD.suspension_salary_policy THEN
                    RAISE EXCEPTION 'personnel_compensation_plans.suspension_salary_policy is immutable once the plan leaves draft (supersede with a new version)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER personnel_compensation_plans_suspension_policy_immutable
                BEFORE UPDATE ON personnel_compensation_plans
                FOR EACH ROW EXECUTE FUNCTION personnel_compensation_plans_suspension_policy_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS personnel_compensation_plans_suspension_policy_immutable ON personnel_compensation_plans;');
        DB::unprepared('DROP FUNCTION IF EXISTS personnel_compensation_plans_suspension_policy_guard();');
        DB::statement('ALTER TABLE personnel_compensation_plans DROP CONSTRAINT IF EXISTS personnel_compensation_plans_suspension_salary_policy_check');
        Schema::table('personnel_compensation_plans', function (Blueprint $table): void {
            $table->dropColumn('suspension_salary_policy');
        });
    }
};
