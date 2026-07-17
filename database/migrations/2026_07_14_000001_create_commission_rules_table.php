<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * commission_rules — HR-controlled commission CONFIGURATION (Plan §59, §80; Scope §12.7
 * Step 3A / §18.3; Phase 20F). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/commission-rule.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id; composite FK → merchant_branches(id, merchant_id)
 * so merchant_id can never disagree with the parent branch; ADR-002). A SIBLING record
 * REFERENCED BY personnel_compensation_plans.commission_rule_id (Scope §18.3 — not a child
 * of a plan, not a ledger). UNIQUE (id, merchant_id) makes this table a composite-FK target
 * for that reference.
 *
 * Value-shape CHECKs make percentage vs fixed mutually exclusive (integer basis points /
 * integer minor units — never float, ADR-005); applies-to CHECKs bind service_category_id to
 * the applicability. Active/scheduled monetary terms are immutable at the DATABASE (BEFORE
 * UPDATE trigger): a change is a supersede with a new version, and a previously active rule
 * is ENDED, not deleted (Scope §12.7 Step 3C). change_reason is mandatory.
 *
 * Configuration ONLY: no commission is computed, no commission_ledger row is created, and no
 * payout is triggered here. Earning happens at Finance validation in Phase 20G (Plan §61).
 * No backfill. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist for the scalar `=` columns alongside the daterange overlap in the
        // EXCLUDE constraint on personnel_compensation_plans (…000002). Already installed by
        // the merged Phase 20A migration …2026_07_10_000005; idempotent here.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('calculation_type', 16);
            $table->integer('percentage_basis_points')->nullable();
            $table->bigInteger('fixed_amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('calculation_basis', 32);
            $table->string('applies_to', 24);
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->restrictOnDelete();
            $table->boolean('applies_to_preferred_personnel_fee')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->text('change_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['merchant_id', 'branch_id', 'status', 'effective_from'], 'commission_rules_resolution_index');
            $table->unique(['id', 'merchant_id'], 'commission_rules_id_merchant_id_unique');
        });

        // DB-level tenant/branch consistency (ADR-002): merchant_id must match the parent branch.
        DB::statement(
            'ALTER TABLE commission_rules
             ADD CONSTRAINT commission_rules_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );

        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_calculation_type_check
             CHECK (calculation_type IN ('percentage','fixed_amount'))"
        );
        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_calculation_basis_check
             CHECK (calculation_basis IN ('service_price','invoice_item_total','paid_amount','net_after_discount'))"
        );
        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_applies_to_check
             CHECK (applies_to IN ('all_services','selected_services','service_category'))"
        );
        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_status_check
             CHECK (status IN ('draft','pending_approval','scheduled','active','superseded','expired','rejected','cancelled'))"
        );
        // F4 structural ceiling: 0..10000 bp (0-100%). The Scope's "configured merchant/platform
        // maximum" has no settings substrate anywhere in the Plan/repository, so this is the
        // enforced bound (the preferred_personnel_fee_rules precedent). See docs/proof/phase-20f.md §F4.
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_basis_points_range_check
             CHECK (percentage_basis_points IS NULL OR (percentage_basis_points BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_fixed_amount_nonneg_check
             CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_currency_check
             CHECK (currency IS NULL OR (currency = upper(currency) AND char_length(currency) = 3))'
        );
        // Value-shape: percentage ⇒ bp present & amount/currency null; fixed ⇒ amount+currency
        // present & bp null. Exactly one calculation value; never float.
        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_value_shape_check
             CHECK (
                 (calculation_type = 'percentage' AND percentage_basis_points IS NOT NULL AND fixed_amount_minor IS NULL AND currency IS NULL)
                 OR
                 (calculation_type = 'fixed_amount' AND fixed_amount_minor IS NOT NULL AND currency IS NOT NULL AND percentage_basis_points IS NULL)
             )"
        );
        // Applies-to: service_category ⇒ category bound; all_services/selected_services ⇒ null.
        DB::statement(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_applies_to_category_check
             CHECK (
                 (applies_to = 'service_category' AND service_category_id IS NOT NULL)
                 OR
                 (applies_to IN ('all_services','selected_services') AND service_category_id IS NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_effective_range_check
             CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_change_reason_check
             CHECK (char_length(btrim(change_reason)) > 0)'
        );
        DB::statement(
            'ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_approval_pair_check
             CHECK ((approved_by IS NULL AND approved_at IS NULL) OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))'
        );

        // F7 immutability (DB-authoritative): once a rule leaves `draft`, its monetary,
        // applicability, and effective-start terms are frozen — a change is a supersede with a
        // new version, never an in-place edit. The ONLY permitted mutation of a non-draft row's
        // effective window is the approved `end` transition (active → superseded), which CLOSES
        // an open-ended window at the successor's effective_from (Scope §12.7 Step 3C: ended,
        // not deleted). Everything else about the ended row stays byte-identical.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_rules_immutability_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                IF ROW(
                    NEW.merchant_id, NEW.branch_id, NEW.calculation_type, NEW.percentage_basis_points,
                    NEW.fixed_amount_minor, NEW.currency, NEW.calculation_basis, NEW.applies_to,
                    NEW.service_category_id, NEW.applies_to_preferred_personnel_fee, NEW.effective_from
                ) IS DISTINCT FROM ROW(
                    OLD.merchant_id, OLD.branch_id, OLD.calculation_type, OLD.percentage_basis_points,
                    OLD.fixed_amount_minor, OLD.currency, OLD.calculation_basis, OLD.applies_to,
                    OLD.service_category_id, OLD.applies_to_preferred_personnel_fee, OLD.effective_from
                ) THEN
                    RAISE EXCEPTION 'commission_rules terms are immutable once the rule leaves draft (supersede with a new version, never edit)';
                END IF;

                IF NEW.effective_to IS DISTINCT FROM OLD.effective_to
                   AND NOT (
                       OLD.status = 'active'
                       AND NEW.status = 'superseded'
                       AND OLD.effective_to IS NULL
                       AND NEW.effective_to IS NOT NULL
                       AND NEW.effective_to > OLD.effective_from
                   ) THEN
                    RAISE EXCEPTION 'commission_rules.effective_to is immutable once the rule leaves draft (only the approved end/supersede transition may close an open-ended window)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_rules_no_term_update
                BEFORE UPDATE ON commission_rules
                FOR EACH ROW EXECUTE FUNCTION commission_rules_immutability_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rules_no_term_update ON commission_rules;');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_rules_immutability_guard();');
        Schema::dropIfExists('commission_rules');
    }
};
