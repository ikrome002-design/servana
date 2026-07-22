<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_payout_items — frozen snapshot lines of a personnel payout run (Plan §62; §13.12
 * canonical DDL; Phase 20H). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md;
 * lifecycle mirrors the parent run (docs/architecture/state-machines/personnel-payout-run.md).
 *
 * BRANCH-OWNED (merchant_id + branch_id). One item per (run, staff_profile, currency). Each item
 * SNAPSHOTS eligible unpaid 20G ledger facts — salary_ledger, commission_ledger, and approved
 * compensation_adjustments — into bucketed sums (never recomputed from current plans/rules), with
 * the exact snapshotted row identities in `source_ledger_refs` jsonb. `currency` completes the
 * §13.12 summary so an item is single-currency (no cross-currency combination). `gross_amount_minor`
 * = salary + commission + adjustment (all signed; a clawback item may net negative).
 *
 * FREEZE: while the run is `draft` an item may be regenerated (DELETE + re-insert). Once the run is
 * submitted the item is immutable except for the `status` mirror transition — a DB trigger blocks
 * DELETE of a non-draft item and blocks any core-column UPDATE (only `status`/`updated_at` change).
 * The source-ledger claim is the ledger row's own `payout_item_id` (set at submit, cleared on
 * reject/cancel); this table adds the FK target for those links. Integer minor units (ADR-005).
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_payout_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->unsignedBigInteger('payout_run_id');
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('salary_amount_minor')->default(0);
            $table->bigInteger('commission_amount_minor')->default(0);
            $table->bigInteger('adjustment_amount_minor')->default(0);
            $table->bigInteger('gross_amount_minor')->default(0);
            $table->jsonb('source_ledger_refs');
            $table->string('status', 40)->default('draft');
            $table->timestampsTz();

            $table->index(['merchant_id', 'staff_profile_id'], 'personnel_payout_items_staff_index');
            $table->index('payout_run_id', 'personnel_payout_items_run_index');
            $table->unique(['payout_run_id', 'staff_profile_id', 'currency'], 'personnel_payout_items_run_staff_currency_unique');
            $table->unique(['id', 'merchant_id'], 'personnel_payout_items_id_merchant_id_unique');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_run_merchant_foreign FOREIGN KEY (payout_run_id, merchant_id) REFERENCES personnel_payout_runs (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_status_check
             CHECK (status IN ('draft','submitted','finance_verified','pending_merchant_admin_approval','approved','paid','rejected','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE personnel_payout_items ADD CONSTRAINT personnel_payout_items_gross_sum_check
             CHECK (gross_amount_minor = salary_amount_minor + commission_amount_minor + adjustment_amount_minor)'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION personnel_payout_items_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'personnel_payout_items is frozen once submitted (DELETE allowed only while draft)';
                    END IF;
                    RETURN OLD;
                END IF;

                IF ROW(
                    NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.payout_run_id, NEW.staff_profile_id,
                    NEW.currency, NEW.salary_amount_minor, NEW.commission_amount_minor,
                    NEW.adjustment_amount_minor, NEW.gross_amount_minor, NEW.source_ledger_refs, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.payout_run_id, OLD.staff_profile_id,
                    OLD.currency, OLD.salary_amount_minor, OLD.commission_amount_minor,
                    OLD.adjustment_amount_minor, OLD.gross_amount_minor, OLD.source_ledger_refs, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'personnel_payout_items snapshot columns are immutable (only status may transition)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER personnel_payout_items_no_snapshot_update
                BEFORE UPDATE ON personnel_payout_items
                FOR EACH ROW EXECUTE FUNCTION personnel_payout_items_guard();
            CREATE TRIGGER personnel_payout_items_no_frozen_delete
                BEFORE DELETE ON personnel_payout_items
                FOR EACH ROW EXECUTE FUNCTION personnel_payout_items_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS personnel_payout_items_no_snapshot_update ON personnel_payout_items;');
        DB::unprepared('DROP TRIGGER IF EXISTS personnel_payout_items_no_frozen_delete ON personnel_payout_items;');
        DB::unprepared('DROP FUNCTION IF EXISTS personnel_payout_items_guard();');
        Schema::dropIfExists('personnel_payout_items');
    }
};
