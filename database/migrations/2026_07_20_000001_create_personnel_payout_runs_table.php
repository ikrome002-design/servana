<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_payout_runs — internal personnel payout workflow (Plan §62; §13.12 canonical DDL;
 * §25.4/§25.5 lifecycle; Phase 20H). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/personnel-payout-run.md.
 *
 * BRANCH-OWNED (merchant_id + branch_id). HR creates/edits a `draft`, then submits (freeze).
 * Finance verifies and either approves an ordinary run or routes a high-value run to Merchant-Admin
 * approval; Finance marks paid after an EXTERNAL payment. **Servana moves no money** — mark-paid
 * records that an external settlement happened; there is no provider/Wallet call here.
 *
 * `high_value_threshold_snapshot_minor` is SNAPSHOTTED at creation from
 * merchant_subscriptions.high_value_payout_threshold_minor (Phase 20A) — never hardcoded; a null
 * snapshot means the high-value approval gate is inactive (ordinary Finance approval). `currency`
 * completes the §13.12 summary so the no-cross-currency invariant is enforceable (a run is
 * single-currency; every item shares it). `gross_total_minor` is signed (clawback adjustments may
 * net negative). `external_payment_reference_encrypted` is encrypted at rest and never logged.
 * Integer minor units (ADR-005). Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_payout_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->bigInteger('high_value_threshold_snapshot_minor')->nullable();
            $table->string('status', 40)->default('draft');
            $table->bigInteger('gross_total_minor')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('external_payment_reference_encrypted')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id', 'status']);
            $table->index(['merchant_id', 'branch_id', 'period_start', 'period_end'], 'personnel_payout_runs_period_index');
            $table->unique(['id', 'merchant_id'], 'personnel_payout_runs_id_merchant_id_unique');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE personnel_payout_runs ADD CONSTRAINT personnel_payout_runs_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE personnel_payout_runs ADD CONSTRAINT personnel_payout_runs_status_check
             CHECK (status IN ('draft','submitted','finance_verified','pending_merchant_admin_approval','approved','paid','rejected','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE personnel_payout_runs ADD CONSTRAINT personnel_payout_runs_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE personnel_payout_runs ADD CONSTRAINT personnel_payout_runs_period_range_check
             CHECK (period_end >= period_start)'
        );
        DB::statement(
            'ALTER TABLE personnel_payout_runs ADD CONSTRAINT personnel_payout_runs_threshold_check
             CHECK (high_value_threshold_snapshot_minor IS NULL OR high_value_threshold_snapshot_minor >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_payout_runs');
    }
};
