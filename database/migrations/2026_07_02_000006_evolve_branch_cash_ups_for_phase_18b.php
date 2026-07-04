<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_cash_ups — forward-only evolution to the Phase 18B canonical cash-up schema
 * (Plan §45; Gate G). The Phase 7 seam migration
 * (2026_06_15_000108_create_branch_cash_ups_table) and the R5 merchant_id backfill
 * (2026_06_23_000002_add_merchant_id_to_branch_owned_tables) are NOT edited or
 * recreated; this migration expands/backfills/constrains additively so existing rows
 * survive.
 *
 * Additive canonical columns (existing→canonical mapping kept back-compatible):
 *   business_date (backfilled from branch_day_records.business_date),
 *   expected_minor (from expected_total), counted_minor (from cash_counted),
 *   variance_minor (from discrepancy_amount), approved_by/approved_at, notes.
 * The named status CHECK is widened (drop+recreate) to add correction_requested +
 * locked. A partial unique index enforces one cash-up per (branch, business_date).
 * Money is integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_cash_ups', function (Blueprint $table): void {
            $table->date('business_date')->nullable()->after('branch_day_record_id');
            $table->bigInteger('expected_minor')->default(0)->after('business_date');
            $table->bigInteger('counted_minor')->default(0)->after('expected_minor');
            $table->bigInteger('variance_minor')->default(0)->after('counted_minor');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable()->after('approved_by');
            $table->string('notes', 1000)->nullable()->after('review_note');
        });

        // Backfill canonical columns from the seam columns (no data loss).
        DB::statement('UPDATE branch_cash_ups SET expected_minor = expected_total WHERE expected_total IS NOT NULL');
        DB::statement('UPDATE branch_cash_ups SET counted_minor = cash_counted WHERE cash_counted IS NOT NULL');
        DB::statement('UPDATE branch_cash_ups SET variance_minor = discrepancy_amount WHERE discrepancy_amount IS NOT NULL');
        DB::statement(
            'UPDATE branch_cash_ups bc
             SET business_date = bdr.business_date
             FROM branch_day_records bdr
             WHERE bc.branch_day_record_id = bdr.id AND bc.business_date IS NULL'
        );

        // Widen the status CHECK (drop + recreate the named constraint — forward-only).
        DB::statement('ALTER TABLE branch_cash_ups DROP CONSTRAINT IF EXISTS branch_cash_ups_status_check');
        DB::statement(
            "ALTER TABLE branch_cash_ups ADD CONSTRAINT branch_cash_ups_status_check
             CHECK (status IN ('draft','submitted','approved','rejected','correction_requested','locked'))"
        );

        // One cash-up per branch-day (only for rows that carry a business_date).
        DB::statement(
            'CREATE UNIQUE INDEX branch_cash_ups_branch_business_date_unique
             ON branch_cash_ups (branch_id, business_date) WHERE business_date IS NOT NULL'
        );

        // Composite-FK target for child cash_up_lines (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE branch_cash_ups
             ADD CONSTRAINT branch_cash_ups_id_merchant_id_unique UNIQUE (id, merchant_id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE branch_cash_ups DROP CONSTRAINT IF EXISTS branch_cash_ups_id_merchant_id_unique');
        DB::statement('DROP INDEX IF EXISTS branch_cash_ups_branch_business_date_unique');
        DB::statement('ALTER TABLE branch_cash_ups DROP CONSTRAINT IF EXISTS branch_cash_ups_status_check');
        DB::statement(
            "ALTER TABLE branch_cash_ups ADD CONSTRAINT branch_cash_ups_status_check
             CHECK (status IN ('draft','submitted','approved','rejected'))"
        );

        Schema::table('branch_cash_ups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['business_date', 'expected_minor', 'counted_minor', 'variance_minor', 'approved_at', 'notes']);
        });
    }
};
