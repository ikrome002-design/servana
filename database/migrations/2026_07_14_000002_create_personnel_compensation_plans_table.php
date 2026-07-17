<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_compensation_plans — compensation model per personnel per branch (Plan §59, §80;
 * Scope §12.2-§12.9 / §18.3; Phase 20F). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/
 * personnel-compensation-plan.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id; composite FK → merchant_branches(id, merchant_id)).
 * The compensation SUBJECT is staff_profile_id (composite FK → staff_profiles(id, merchant_id)),
 * and the commission rule is a SIBLING reference (composite FK → commission_rules(id,
 * merchant_id)) — so neither can ever belong to another merchant (ADR-002).
 *
 * Model-shape CHECKs (F1) are the DB guarantee behind Plan §80's named test "salary-only has no
 * commission rule": salary_only keeps commission_rule_id NULL, so no rule can EVER resolve for
 * that personnel (Scope §12.5). Money is integer minor units (never float). Maker/checker is a
 * CHECK, not just a policy: approved_by can never equal submitted_by (F8).
 *
 * ONE ACTIVE PLAN PER PERSONNEL PER BRANCH (F3; Scope §12.9 hard rule) is enforced by a partial
 * btree_gist EXCLUDE over active+scheduled with a HALF-OPEN daterange, so adjacent windows are
 * legal and draft/pending/terminal rows never block. PostgreSQL — not application validation —
 * is the final arbiter. Non-draft monetary/effective/subject terms are immutable at the DATABASE
 * (BEFORE UPDATE trigger): a change is a supersede (new version), never an in-place edit (F7).
 *
 * Configuration ONLY: no salary accrual, no earned commission, no ledger row, no payout, no
 * statement (Phases 20G/20H). A plan grants NO access (Plan §59). No backfill. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist for the scalar `=` columns alongside the daterange overlap in the EXCLUDE.
        // Already installed by the merged Phase 20A migration …2026_07_10_000005; idempotent here.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('personnel_compensation_plans', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('compensation_model', 24);
            $table->bigInteger('salary_amount_minor')->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->string('salary_period', 16)->nullable();
            $table->smallInteger('salary_payout_day')->nullable();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_backdated')->default(false);
            $table->foreignId('supersedes_plan_id')->nullable()->constrained('personnel_compensation_plans')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->text('change_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(
                ['merchant_id', 'branch_id', 'staff_profile_id', 'status', 'effective_from'],
                'personnel_compensation_plans_resolution_index'
            );
            $table->index('commission_rule_id', 'personnel_compensation_plans_commission_rule_index');
            $table->unique(['id', 'merchant_id'], 'personnel_compensation_plans_id_merchant_id_unique');
        });

        // DB-level tenant/branch consistency (ADR-002): merchant_id must match the parent branch.
        DB::statement(
            'ALTER TABLE personnel_compensation_plans
             ADD CONSTRAINT personnel_compensation_plans_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        // The compensation subject can never belong to another merchant.
        DB::statement(
            'ALTER TABLE personnel_compensation_plans
             ADD CONSTRAINT personnel_compensation_plans_staff_profile_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        // The referenced commission rule can never belong to another merchant (F5).
        DB::statement(
            'ALTER TABLE personnel_compensation_plans
             ADD CONSTRAINT personnel_compensation_plans_commission_rule_merchant_foreign
             FOREIGN KEY (commission_rule_id, merchant_id)
             REFERENCES commission_rules (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_compensation_model_check
             CHECK (compensation_model IN ('commission_only','salary_plus_commission','salary_only'))"
        );
        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_salary_period_check
             CHECK (salary_period IS NULL OR salary_period IN ('monthly','weekly','daily','hourly','per_shift'))"
        );
        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_status_check
             CHECK (status IN ('draft','pending_approval','scheduled','active','expired','superseded','rejected','cancelled'))"
        );
        // Scope §12.7 3B/3C: a configured salary amount is > 0. Integer minor units; never float.
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_salary_amount_positive_check
             CHECK (salary_amount_minor IS NULL OR salary_amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_salary_currency_check
             CHECK (salary_currency IS NULL OR (salary_currency = upper(salary_currency) AND char_length(salary_currency) = 3))'
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_salary_payout_day_check
             CHECK (salary_payout_day IS NULL OR (salary_payout_day BETWEEN 1 AND 31))'
        );
        // F1 model shape (DB-authoritative — Plan §59):
        //   commission_only        ⇒ NO salary terms, commission rule REQUIRED
        //   salary_only            ⇒ salary terms REQUIRED, NO commission rule (Plan §80 named test)
        //   salary_plus_commission ⇒ salary terms REQUIRED and commission rule REQUIRED
        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_model_shape_check
             CHECK (
                 (compensation_model = 'commission_only'
                     AND salary_amount_minor IS NULL AND salary_currency IS NULL AND salary_period IS NULL
                     AND salary_payout_day IS NULL AND commission_rule_id IS NOT NULL)
                 OR (compensation_model = 'salary_only'
                     AND salary_amount_minor IS NOT NULL AND salary_currency IS NOT NULL AND salary_period IS NOT NULL
                     AND commission_rule_id IS NULL)
                 OR (compensation_model = 'salary_plus_commission'
                     AND salary_amount_minor IS NOT NULL AND salary_currency IS NOT NULL AND salary_period IS NOT NULL
                     AND commission_rule_id IS NOT NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_change_reason_check
             CHECK (char_length(btrim(change_reason)) > 0)'
        );
        // F8 maker/checker at the DATABASE: the submitter can never be recorded as their own approver.
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_maker_checker_check
             CHECK (approved_by IS NULL OR submitted_by IS NULL OR approved_by <> submitted_by)'
        );
        // Actor/timestamp coherence: an actor and its timestamp are recorded together or not at all.
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_submitted_pair_check
             CHECK ((submitted_by IS NULL AND submitted_at IS NULL) OR (submitted_by IS NOT NULL AND submitted_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_approved_pair_check
             CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_rejected_pair_check
             CHECK ((rejected_by IS NULL AND rejected_at IS NULL) OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL))'
        );
        // An unapproved backdated change can never reach an effective state (F8 fail-closed):
        // scheduled/active/superseded/expired all require recorded approval.
        DB::statement(
            "ALTER TABLE personnel_compensation_plans ADD CONSTRAINT personnel_compensation_plans_approval_status_check
             CHECK (
                 (status IN ('draft','pending_approval') AND approved_by IS NULL)
                 OR (status IN ('scheduled','active','superseded','expired') AND approved_by IS NOT NULL)
                 OR (status IN ('rejected','cancelled'))
             )"
        );

        // F3 — ONE ACTIVE PLAN PER PERSONNEL PER BRANCH (Plan §59; Scope §12.9). Half-open
        // daterange ⇒ adjacent windows are legal (supersede closes the incumbent AT the
        // successor's effective_from); a NULL effective_to is unbounded. Partial WHERE ⇒
        // draft/pending_approval/superseded/expired/rejected/cancelled rows NEVER block a
        // window. This is the authoritative overlap guard; the domain pre-check only produces
        // a friendlier 409.
        DB::statement(
            "ALTER TABLE personnel_compensation_plans
             ADD CONSTRAINT personnel_compensation_plans_no_overlap
             EXCLUDE USING gist (
                 branch_id WITH =,
                 staff_profile_id WITH =,
                 daterange(effective_from, effective_to, '[)') WITH &&
             )
             WHERE (status IN ('active','scheduled'))"
        );

        // F7 immutability (DB-authoritative): once a plan leaves `draft`, its subject, model,
        // monetary terms, commission-rule reference, and effective START are frozen — a change
        // is a SUPERSEDE (new version), never an in-place edit. The ONLY permitted mutation of
        // a non-draft row's effective window is the approved supersede transition (active →
        // superseded), which CLOSES an open-ended window at the successor's effective_from. An
        // illegal destructive edit and that approved transition are distinguished by the
        // status pair + the open→closed direction; the superseded row's terms stay identical.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION personnel_compensation_plans_immutability_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                IF ROW(
                    NEW.merchant_id, NEW.branch_id, NEW.staff_profile_id, NEW.commission_rule_id,
                    NEW.compensation_model, NEW.salary_amount_minor, NEW.salary_currency,
                    NEW.salary_period, NEW.salary_payout_day, NEW.effective_from
                ) IS DISTINCT FROM ROW(
                    OLD.merchant_id, OLD.branch_id, OLD.staff_profile_id, OLD.commission_rule_id,
                    OLD.compensation_model, OLD.salary_amount_minor, OLD.salary_currency,
                    OLD.salary_period, OLD.salary_payout_day, OLD.effective_from
                ) THEN
                    RAISE EXCEPTION 'personnel_compensation_plans terms are immutable once the plan leaves draft (supersede with a new version, never edit)';
                END IF;

                IF NEW.effective_to IS DISTINCT FROM OLD.effective_to
                   AND NOT (
                       OLD.status = 'active'
                       AND NEW.status = 'superseded'
                       AND OLD.effective_to IS NULL
                       AND NEW.effective_to IS NOT NULL
                       AND NEW.effective_to > OLD.effective_from
                   ) THEN
                    RAISE EXCEPTION 'personnel_compensation_plans.effective_to is immutable once the plan leaves draft (only the approved supersede transition may close an open-ended window)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER personnel_compensation_plans_no_term_update
                BEFORE UPDATE ON personnel_compensation_plans
                FOR EACH ROW EXECUTE FUNCTION personnel_compensation_plans_immutability_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS personnel_compensation_plans_no_term_update ON personnel_compensation_plans;');
        DB::unprepared('DROP FUNCTION IF EXISTS personnel_compensation_plans_immutability_guard();');
        Schema::dropIfExists('personnel_compensation_plans');
    }
};
