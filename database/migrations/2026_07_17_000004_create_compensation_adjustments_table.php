<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * compensation_adjustments — append-only additive compensation adjustments (Plan §60/§61;
 * §13.12 canonical DDL; Phase 20G/20H). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id). Two sources, both ADDITIVE (never rewrite paid or
 * earned history):
 *   1. Finance manual adjustment (`compensation.adjustment.create`, MFA + fresh step-up,
 *      high-severity audit) — `adjustment_type = 'manual'`.
 *   2. A reversal whose original ledger row is ALREADY PAID — Plan §61 requires this to become a
 *      negative adjustment for a future Phase 20H payout rather than a ledger reversal
 *      (`adjustment_type = 'paid_commission_reversal' | 'paid_salary_reversal'`, referencing the
 *      paid source row).
 * `amount_minor` may be negative (a reversal/clawback) or positive. `adjustment_type`,
 * `created_by`, `source_*_ledger_id`, and the Phase 20H `payout_item_id` link extend the minimal
 * §13.12 column list for provenance and idempotency; `payout_item_id` is nullable + UN-CONSTRAINED
 * (its FK target is Phase 20H). Fully append-only at the database (DELETE blocked; UPDATE limited
 * to the payout_item_id link). Integer minor units (ADR-005). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('adjustment_type', 32)->default('manual');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('reason');
            // Provenance for system-created already-paid reversals (null for manual adjustments).
            $table->unsignedBigInteger('source_commission_ledger_id')->nullable();
            $table->unsignedBigInteger('source_salary_ledger_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('payout_item_id')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['staff_profile_id'], 'compensation_adjustments_staff_index');
            $table->index('payout_item_id', 'compensation_adjustments_payout_item_index');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_source_commission_merchant_foreign FOREIGN KEY (source_commission_ledger_id, merchant_id) REFERENCES commission_ledger (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_source_salary_merchant_foreign FOREIGN KEY (source_salary_ledger_id, merchant_id) REFERENCES salary_ledger (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_type_check
             CHECK (adjustment_type IN ('manual','paid_commission_reversal','paid_salary_reversal','correction'))"
        );
        DB::statement(
            'ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_amount_nonzero_check
             CHECK (amount_minor <> 0)'
        );
        DB::statement(
            'ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_reason_check
             CHECK (char_length(btrim(reason)) > 0)'
        );
        // Provenance: a manual adjustment references no ledger row; a paid-reversal references
        // exactly the matching ledger source and nets negative.
        DB::statement(
            "ALTER TABLE compensation_adjustments ADD CONSTRAINT compensation_adjustments_provenance_check
             CHECK (
                 (adjustment_type IN ('manual','correction') AND source_commission_ledger_id IS NULL AND source_salary_ledger_id IS NULL)
                 OR (adjustment_type = 'paid_commission_reversal' AND source_commission_ledger_id IS NOT NULL AND source_salary_ledger_id IS NULL AND amount_minor < 0)
                 OR (adjustment_type = 'paid_salary_reversal' AND source_salary_ledger_id IS NOT NULL AND source_commission_ledger_id IS NULL AND amount_minor < 0)
             )"
        );
        // One paid-reversal adjustment per paid source ledger row (idempotent reversal of paid history).
        DB::statement(
            'CREATE UNIQUE INDEX compensation_adjustments_paid_commission_unique
             ON compensation_adjustments (source_commission_ledger_id)
             WHERE source_commission_ledger_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX compensation_adjustments_paid_salary_unique
             ON compensation_adjustments (source_salary_ledger_id)
             WHERE source_salary_ledger_id IS NOT NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compensation_adjustments_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'compensation_adjustments is append-only (DELETE blocked)';
                END IF;

                IF ROW(
                    NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.staff_profile_id, NEW.adjustment_type,
                    NEW.amount_minor, NEW.currency, NEW.reason, NEW.source_commission_ledger_id,
                    NEW.source_salary_ledger_id, NEW.created_by, NEW.approved_by, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.staff_profile_id, OLD.adjustment_type,
                    OLD.amount_minor, OLD.currency, OLD.reason, OLD.source_commission_ledger_id,
                    OLD.source_salary_ledger_id, OLD.created_by, OLD.approved_by, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'compensation_adjustments is immutable (only payout_item_id may transition)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER compensation_adjustments_no_update
                BEFORE UPDATE ON compensation_adjustments
                FOR EACH ROW EXECUTE FUNCTION compensation_adjustments_guard();
            CREATE TRIGGER compensation_adjustments_no_delete
                BEFORE DELETE ON compensation_adjustments
                FOR EACH ROW EXECUTE FUNCTION compensation_adjustments_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS compensation_adjustments_no_update ON compensation_adjustments;');
        DB::unprepared('DROP TRIGGER IF EXISTS compensation_adjustments_no_delete ON compensation_adjustments;');
        DB::unprepared('DROP FUNCTION IF EXISTS compensation_adjustments_guard();');
        Schema::dropIfExists('compensation_adjustments');
    }
};
