<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * salary_ledger — append-only salary accrual facts (Plan §60; §13.12 canonical DDL; Phase
 * 20G). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/salary-ledger-entry.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id). The salary-accrual scheduler creates one `accrual`
 * row per payable pay-period SEGMENT in Africa/Nairobi using the Actual/Actual calendar-day
 * proration convention (G8): monthly denominator = actual days in the Nairobi month; weekly =
 * ISO Mon–Mon, denominator 7; half-open plan windows [effective_from, effective_to). Each row
 * stores the segment's payable date range (pay_period_start/end) and a deterministic
 * pay_period_segment_key; the idempotency unique (compensation_plan_id, staff_profile_id,
 * pay_period_segment_key, entry_type) makes every scheduler run replay-safe. Mid-period plan
 * changes, prospective suspension `pause`, resumption, and termination each split the period
 * into separate segments; daily/hourly/per_shift salary is NOT accrued here (no approved
 * attendance/shift source exists — the domain guard fails closed).
 *
 * Corrections are ADDITIVE: `reversal` (exact negative of an original accrual, via
 * source_entry_id) and `adjustment`; originals are immutable. `pay_period_segment_key` and
 * `source_entry_id` extend the minimal §13.12 column list for reversal provenance + segment
 * idempotency, matching the commission_ledger reversal pattern. Append-only at the database
 * (DELETE blocked; UPDATE limited to status + payout_item_id). `payout_item_id` is nullable and
 * UN-CONSTRAINED — its FK target personnel_payout_items is added by a Phase 20H expand migration
 * (ADR-004). Integer minor units (ADR-005). No backfill. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_ledger', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('compensation_plan_id')->constrained('personnel_compensation_plans')->restrictOnDelete();
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->string('pay_period_segment_key', 191);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('source_entry_id')->nullable();
            $table->string('entry_type', 16);
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('payout_item_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['staff_profile_id', 'status'], 'salary_ledger_staff_status_index');
            $table->index('compensation_plan_id', 'salary_ledger_plan_index');
            $table->index('payout_item_id', 'salary_ledger_payout_item_index');
            $table->unique(['id', 'merchant_id'], 'salary_ledger_id_merchant_id_unique');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_plan_merchant_foreign FOREIGN KEY (compensation_plan_id, merchant_id) REFERENCES personnel_compensation_plans (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_source_entry_merchant_foreign FOREIGN KEY (source_entry_id, merchant_id) REFERENCES salary_ledger (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_entry_type_check
             CHECK (entry_type IN ('accrual','adjustment','reversal'))"
        );
        DB::statement(
            "ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_status_check
             CHECK (status IN ('pending','included_in_payout','paid','reversed','adjusted'))"
        );
        DB::statement(
            'ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_period_range_check
             CHECK (pay_period_end >= pay_period_start)'
        );
        // Accrual amounts are non-negative; reversal amounts are non-positive (exact negative).
        DB::statement(
            "ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_accrual_sign_check
             CHECK (entry_type <> 'accrual' OR amount_minor >= 0)"
        );
        DB::statement(
            "ALTER TABLE salary_ledger ADD CONSTRAINT salary_ledger_reversal_sign_check
             CHECK (entry_type <> 'reversal' OR (amount_minor <= 0 AND source_entry_id IS NOT NULL))"
        );

        // Idempotency: one accrual per (plan, staff, pay-period segment); one reversal per original.
        DB::statement(
            "CREATE UNIQUE INDEX salary_ledger_accrual_idempotency_unique
             ON salary_ledger (compensation_plan_id, staff_profile_id, pay_period_segment_key, entry_type)
             WHERE entry_type = 'accrual'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX salary_ledger_reversal_idempotency_unique
             ON salary_ledger (source_entry_id)
             WHERE entry_type = 'reversal'"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION salary_ledger_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'salary_ledger is append-only (DELETE blocked)';
                END IF;

                IF ROW(
                    NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.staff_profile_id, NEW.compensation_plan_id,
                    NEW.pay_period_start, NEW.pay_period_end, NEW.pay_period_segment_key, NEW.amount_minor,
                    NEW.currency, NEW.source_entry_id, NEW.entry_type, NEW.created_by, NEW.approved_by, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.staff_profile_id, OLD.compensation_plan_id,
                    OLD.pay_period_start, OLD.pay_period_end, OLD.pay_period_segment_key, OLD.amount_minor,
                    OLD.currency, OLD.source_entry_id, OLD.entry_type, OLD.created_by, OLD.approved_by, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'salary_ledger monetary/period columns are immutable (only status + payout_item_id may transition)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER salary_ledger_no_monetary_update
                BEFORE UPDATE ON salary_ledger
                FOR EACH ROW EXECUTE FUNCTION salary_ledger_guard();
            CREATE TRIGGER salary_ledger_no_delete
                BEFORE DELETE ON salary_ledger
                FOR EACH ROW EXECUTE FUNCTION salary_ledger_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS salary_ledger_no_monetary_update ON salary_ledger;');
        DB::unprepared('DROP TRIGGER IF EXISTS salary_ledger_no_delete ON salary_ledger;');
        DB::unprepared('DROP FUNCTION IF EXISTS salary_ledger_guard();');
        Schema::dropIfExists('salary_ledger');
    }
};
