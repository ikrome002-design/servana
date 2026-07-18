<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * commission_ledger — append-only earned/reversal commission facts (Plan §61; §13.12
 * canonical DDL; Correction 2.3; Phase 20G). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/commission-ledger-entry.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id). An `earned` row is created ONLY when Finance
 * validates a payment (Plan §61) — never at payment recording, service-session completion,
 * or invoice finalization. Earning is driven by the durable idempotent
 * commission_handoff_events outbox written inside ValidatePaymentRecordingGroup; a 20G
 * consumer allocates the validated amount across eligible invoice items and creates one
 * earned row per (validation event, invoice item, staff), snapshotting the plan/rule/basis/
 * rate/source identities. `salary_only` plans never generate rows.
 *
 * Corrections are ADDITIVE: a `reversal` row stores the EXACT NEGATIVE of the original stored
 * amount (never recomputed), references the original via source_entry_id, and carries a
 * reversal_reason; already-paid history is never rewritten (a paid reversal becomes a negative
 * compensation_adjustments row instead — Phase 20H payout). Append-only at the database: a
 * trigger blocks DELETE always and blocks UPDATE of every column except the lifecycle `status`
 * and the Phase 20H aggregation link `payout_item_id`. Money is integer minor units (ADR-005;
 * round-half-up + largest-remainder residual applied by the domain). `payout_item_id` is a
 * nullable, UN-CONSTRAINED column now — its FK target `personnel_payout_items` does not exist
 * until Phase 20H, which adds the FK by expand migration (ADR-004). No backfill. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite-FK target so commission_ledger.invoice_item_id can be merchant-consistent
        // (invoice_items ships only an (id) PK + (service_session_id) unique). Additive; id is the
        // PK so (id, merchant_id) is trivially unique. Never edits the shipped 20F/17 migration.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS invoice_items_id_merchant_id_unique
             ON invoice_items (id, merchant_id)'
        );

        Schema::create('commission_ledger', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('compensation_plan_id')->constrained('personnel_compensation_plans')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->restrictOnDelete();
            $table->foreignId('service_session_id')->nullable()->constrained('service_sessions')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->constrained('invoice_items')->restrictOnDelete();
            $table->foreignId('payment_record_id')->nullable()->constrained('payment_records')->restrictOnDelete();
            $table->foreignId('payment_validation_event_id')->nullable()->constrained('payment_validation_events')->restrictOnDelete();
            $table->unsignedBigInteger('source_entry_id')->nullable();
            $table->string('entry_type', 16);
            $table->string('reversal_reason', 24)->nullable();
            $table->bigInteger('calculation_basis_minor');
            $table->integer('rate_basis_points')->nullable();
            $table->bigInteger('fixed_rate_minor')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('earned_at')->nullable();
            $table->string('status', 24)->default('earned');
            // Phase 20H aggregation link — nullable, NO FK yet (personnel_payout_items is 20H).
            $table->unsignedBigInteger('payout_item_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['staff_profile_id', 'status'], 'commission_ledger_staff_status_index');
            $table->index('invoice_id', 'commission_ledger_invoice_index');
            $table->index('payment_validation_event_id', 'commission_ledger_validation_index');
            $table->index('payout_item_id', 'commission_ledger_payout_item_index');
            // Self-FK target for reversal provenance.
            $table->unique(['id', 'merchant_id'], 'commission_ledger_id_merchant_id_unique');
        });

        // Merchant consistency (ADR-002) via composite FKs — no reference can cross a merchant.
        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_plan_merchant_foreign FOREIGN KEY (compensation_plan_id, merchant_id) REFERENCES personnel_compensation_plans (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_rule_merchant_foreign FOREIGN KEY (commission_rule_id, merchant_id) REFERENCES commission_rules (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_invoice_merchant_foreign FOREIGN KEY (invoice_id, merchant_id) REFERENCES invoices (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_invoice_item_merchant_foreign FOREIGN KEY (invoice_item_id, merchant_id) REFERENCES invoice_items (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_session_merchant_foreign FOREIGN KEY (service_session_id, merchant_id) REFERENCES service_sessions (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_payment_record_merchant_foreign FOREIGN KEY (payment_record_id, merchant_id) REFERENCES payment_records (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_validation_merchant_foreign FOREIGN KEY (payment_validation_event_id, merchant_id) REFERENCES payment_validation_events (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_source_entry_merchant_foreign FOREIGN KEY (source_entry_id, merchant_id) REFERENCES commission_ledger (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        // Backed-enum CHECKs (mirror the domain enums; parity guarded by Phase20GEnumParityTest).
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_entry_type_check
             CHECK (entry_type IN ('pending_preview','earned','reversal','adjustment'))"
        );
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_reversal_reason_check
             CHECK (reversal_reason IS NULL OR reversal_reason IN ('invoice_voided','payment_reversed','refund_finalized','manual_adjustment','correction'))"
        );
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_status_check
             CHECK (status IN ('pending','earned','included_in_payout','paid','reversed','adjusted','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_basis_nonneg_check
             CHECK (calculation_basis_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_rate_range_check
             CHECK (rate_basis_points IS NULL OR (rate_basis_points BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_fixed_rate_nonneg_check
             CHECK (fixed_rate_minor IS NULL OR fixed_rate_minor >= 0)'
        );
        // Provenance: earned/pending_preview rows carry no source and no reversal_reason; reversal
        // rows reference an original and carry a reason; adjustment rows carry a reason.
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_provenance_check
             CHECK (
                 (entry_type IN ('earned','pending_preview') AND source_entry_id IS NULL AND reversal_reason IS NULL)
                 OR (entry_type = 'reversal' AND source_entry_id IS NOT NULL AND reversal_reason IS NOT NULL)
                 OR (entry_type = 'adjustment' AND reversal_reason IS NOT NULL)
             )"
        );
        // Earned rows are non-negative and carry the validation source; reversal rows are non-positive.
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_earned_shape_check
             CHECK (
                 entry_type <> 'earned'
                 OR (amount_minor >= 0 AND payment_validation_event_id IS NOT NULL AND earned_at IS NOT NULL)
             )"
        );
        DB::statement(
            "ALTER TABLE commission_ledger ADD CONSTRAINT commission_ledger_reversal_sign_check
             CHECK (entry_type <> 'reversal' OR amount_minor <= 0)"
        );

        // Idempotency (DB-enforced, not only application code):
        //  - exactly one earned row per (validation event, invoice item, staff).
        DB::statement(
            "CREATE UNIQUE INDEX commission_ledger_earned_idempotency_unique
             ON commission_ledger (payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type)
             WHERE entry_type = 'earned'"
        );
        //  - an original earned row is reversed at most once (exact-negative, one-time).
        DB::statement(
            "CREATE UNIQUE INDEX commission_ledger_reversal_idempotency_unique
             ON commission_ledger (source_entry_id)
             WHERE entry_type = 'reversal'"
        );

        // Append-only guard: block DELETE; on UPDATE only status + payout_item_id may change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_ledger_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'commission_ledger is append-only (DELETE blocked)';
                END IF;

                IF ROW(
                    NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.staff_profile_id, NEW.compensation_plan_id,
                    NEW.commission_rule_id, NEW.service_session_id, NEW.invoice_id, NEW.invoice_item_id,
                    NEW.payment_record_id, NEW.payment_validation_event_id, NEW.source_entry_id, NEW.entry_type,
                    NEW.reversal_reason, NEW.calculation_basis_minor, NEW.rate_basis_points, NEW.fixed_rate_minor,
                    NEW.amount_minor, NEW.currency, NEW.earned_at, NEW.created_by, NEW.approved_by, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.staff_profile_id, OLD.compensation_plan_id,
                    OLD.commission_rule_id, OLD.service_session_id, OLD.invoice_id, OLD.invoice_item_id,
                    OLD.payment_record_id, OLD.payment_validation_event_id, OLD.source_entry_id, OLD.entry_type,
                    OLD.reversal_reason, OLD.calculation_basis_minor, OLD.rate_basis_points, OLD.fixed_rate_minor,
                    OLD.amount_minor, OLD.currency, OLD.earned_at, OLD.created_by, OLD.approved_by, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'commission_ledger monetary/snapshot columns are immutable (only status + payout_item_id may transition)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_ledger_no_monetary_update
                BEFORE UPDATE ON commission_ledger
                FOR EACH ROW EXECUTE FUNCTION commission_ledger_guard();
            CREATE TRIGGER commission_ledger_no_delete
                BEFORE DELETE ON commission_ledger
                FOR EACH ROW EXECUTE FUNCTION commission_ledger_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commission_ledger_no_monetary_update ON commission_ledger;');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_ledger_no_delete ON commission_ledger;');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_ledger_guard();');
        Schema::dropIfExists('commission_ledger');
        DB::statement('DROP INDEX IF EXISTS invoice_items_id_merchant_id_unique');
    }
};
