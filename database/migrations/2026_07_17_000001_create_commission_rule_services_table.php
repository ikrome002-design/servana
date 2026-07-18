<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * commission_rule_services — normalized selected-services membership substrate for
 * commission rules (Plan §61; §9.1 product-owner decision; Phase 20G). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * Phase 20F ships `commission_rules.applies_to = 'selected_services'` as a valid
 * applicability but recorded that it had NO membership source (only `service_category_id`
 * exists, for the category case). Without a normalized membership set, Phase 20G cannot
 * truthfully compute commission for a selected-services rule. This table closes that
 * inherited 20F seam: one immutable row per (rule, service). It is CONFIGURATION substrate
 * — no money, no payout, no validated amount, no Wallet/provider field, no settlement.
 *
 * BRANCH-OWNED (merchant_id + branch_id). Merchant/branch consistency is DB-enforced by
 * composite FKs to merchant_branches(id, merchant_id), commission_rules(id, merchant_id) and
 * services(id, merchant_id); a BEFORE INSERT trigger additionally proves rule.branch_id =
 * service.branch_id = membership.branch_id (same branch), because the parents only expose an
 * (id, merchant_id) composite key. Membership is part of a rule's effective financial
 * configuration, so it may change ONLY while the rule is `draft` (guard trigger); once the
 * rule leaves draft the set is frozen and a change is a supersede with a new rule version
 * (Scope §12.7 supersede-not-edit). Service deletion is RESTRICTed so historical rule meaning
 * is never silently erased. No JSON service list. No backfill. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rule_services', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            // Append-only membership row: never UPDATEd (add/remove = insert/delete while draft).
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index('commission_rule_id', 'commission_rule_services_rule_index');
            $table->index('service_id', 'commission_rule_services_service_index');
            // One membership per (rule, service): a rule cannot list the same service twice.
            $table->unique(['commission_rule_id', 'service_id'], 'commission_rule_services_rule_service_unique');
        });

        // Merchant/branch consistency (ADR-002): membership merchant matches its branch's merchant.
        DB::statement(
            'ALTER TABLE commission_rule_services
             ADD CONSTRAINT commission_rule_services_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        // Rule same merchant (composite FK to commission_rules(id, merchant_id)).
        DB::statement(
            'ALTER TABLE commission_rule_services
             ADD CONSTRAINT commission_rule_services_rule_merchant_foreign
             FOREIGN KEY (commission_rule_id, merchant_id)
             REFERENCES commission_rules (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        // Service same merchant (composite FK to services(id, merchant_id)).
        DB::statement(
            'ALTER TABLE commission_rule_services
             ADD CONSTRAINT commission_rule_services_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );

        // Configuration-immutability + same-branch guard. Membership may be mutated ONLY while
        // the parent rule is `draft`; rule.branch_id = service.branch_id = membership.branch_id
        // (the (id, merchant_id) composite FKs above prove merchant, not branch); rows are never
        // UPDATEd (add/remove is insert/delete).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_rule_services_guard() RETURNS trigger AS $$
            DECLARE
                rule_status text;
                rule_branch bigint;
                svc_branch bigint;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'commission_rule_services rows are immutable (add/remove a membership, never update it)';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    SELECT status INTO rule_status FROM commission_rules WHERE id = OLD.commission_rule_id FOR SHARE;
                    -- A membership may be removed only while the rule is still a draft; if the rule
                    -- row is already gone (cascade delete of the whole rule) allow the child delete.
                    IF rule_status IS NOT NULL AND rule_status <> 'draft' THEN
                        RAISE EXCEPTION 'commission_rule_services membership is immutable once the rule leaves draft (supersede with a new rule version)';
                    END IF;
                    RETURN OLD;
                END IF;

                -- INSERT: parent rule must be a draft; branches must all agree.
                SELECT status, branch_id INTO rule_status, rule_branch
                    FROM commission_rules WHERE id = NEW.commission_rule_id FOR SHARE;
                IF rule_status IS NULL THEN
                    RAISE EXCEPTION 'commission_rule_services references a missing commission rule';
                END IF;
                IF rule_status <> 'draft' THEN
                    RAISE EXCEPTION 'commission_rule_services membership can only be added while the rule is draft (supersede with a new rule version)';
                END IF;
                IF rule_branch <> NEW.branch_id THEN
                    RAISE EXCEPTION 'commission_rule_services.branch_id must equal the commission rule branch';
                END IF;

                SELECT branch_id INTO svc_branch FROM services WHERE id = NEW.service_id FOR SHARE;
                IF svc_branch IS NULL THEN
                    RAISE EXCEPTION 'commission_rule_services references a missing service';
                END IF;
                IF svc_branch <> NEW.branch_id THEN
                    RAISE EXCEPTION 'commission_rule_services.service must belong to the same branch as the rule';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_rule_services_guard_ins
                BEFORE INSERT ON commission_rule_services
                FOR EACH ROW EXECUTE FUNCTION commission_rule_services_guard();
            CREATE TRIGGER commission_rule_services_guard_upd
                BEFORE UPDATE ON commission_rule_services
                FOR EACH ROW EXECUTE FUNCTION commission_rule_services_guard();
            CREATE TRIGGER commission_rule_services_guard_del
                BEFORE DELETE ON commission_rule_services
                FOR EACH ROW EXECUTE FUNCTION commission_rule_services_guard();
        SQL);

        // A selected-services rule may NOT leave `draft` with zero memberships (a draft may
        // temporarily have none while HR is still editing it). Enforced as a second BEFORE UPDATE
        // trigger on commission_rules (additive; the shipped 20F immutability trigger is untouched).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_rules_selected_services_membership_guard() RETURNS trigger AS $$
            DECLARE
                membership_count integer;
            BEGIN
                IF OLD.status = 'draft'
                   AND NEW.status <> 'draft'
                   AND NEW.applies_to = 'selected_services' THEN
                    SELECT count(*) INTO membership_count
                        FROM commission_rule_services WHERE commission_rule_id = NEW.id;
                    IF membership_count = 0 THEN
                        RAISE EXCEPTION 'a selected_services commission rule requires at least one selected service before it leaves draft';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_rules_selected_services_membership_check
                BEFORE UPDATE ON commission_rules
                FOR EACH ROW EXECUTE FUNCTION commission_rules_selected_services_membership_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rules_selected_services_membership_check ON commission_rules;');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_rules_selected_services_membership_guard();');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rule_services_guard_ins ON commission_rule_services;');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rule_services_guard_upd ON commission_rule_services;');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rule_services_guard_del ON commission_rule_services;');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_rule_services_guard();');
        Schema::dropIfExists('commission_rule_services');
    }
};
