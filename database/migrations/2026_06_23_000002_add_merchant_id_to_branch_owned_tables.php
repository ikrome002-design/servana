<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add merchant_id to branch-owned tables (Plan §2.1, §8.2, §13.1; ADR-002;
 * Phase R5, REM-TEN-001). Expand-and-contract, forward-only.
 *
 * For each branch-owned table that carried only branch_id:
 *   1. add merchant_id nullable
 *   2. backfill from the parent branch (idempotent, parameterized — no raw SQL)
 *   3. fail safely if any row cannot be resolved (never guess a merchant)
 *   4. make merchant_id NOT NULL
 *   5. add an index beginning with merchant_id
 *   6. add merchant_id → merchants FK (RESTRICT — merchants are deactivated, never deleted)
 *   7. add a composite FK (branch_id, merchant_id) → merchant_branches(id, merchant_id)
 *      so merchant_id can never disagree with the parent branch (the DB consistency
 *      guarantee; ADR-002). The existing branch_id → merchant_branches CASCADE FK is
 *      retained, so branch deletion still cascades these rows.
 */
return new class extends Migration
{
    /** @var list<string> branch-owned tables that need merchant_id. */
    private array $tables = [
        'branch_user_assignments',
        'branch_operating_hours',
        'branch_calendar_exceptions',
        'branch_day_records',
        'branch_cash_ups',
    ];

    public function up(): void
    {
        // 1. Expand — add nullable merchant_id after branch_id.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('merchant_id')->nullable()->after('branch_id');
            });
        }

        // 2. Backfill from the parent branch (parameterized, idempotent).
        foreach (DB::table('merchant_branches')->select('id', 'merchant_id')->cursor() as $branch) {
            foreach ($this->tables as $table) {
                DB::table($table)
                    ->where('branch_id', $branch->id)
                    ->whereNull('merchant_id')
                    ->update(['merchant_id' => $branch->merchant_id]);
            }
        }

        // 3. Fail safely — never guess a merchant for an unresolved row.
        foreach ($this->tables as $table) {
            $unresolved = DB::table($table)->whereNull('merchant_id')->count();
            if ($unresolved > 0) {
                throw new RuntimeException(
                    "R5 backfill: {$table} has {$unresolved} row(s) with no resolvable merchant_id ".
                    '(orphaned branch_id). Resolve the data before re-running.'
                );
            }
        }

        // 4–7. Contract — NOT NULL, index, FKs (direct + composite consistency).
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unsignedBigInteger('merchant_id')->nullable(false)->change();

                $blueprint->index(['merchant_id', 'branch_id'], "{$table}_merchant_branch_index");

                $blueprint->foreign('merchant_id', "{$table}_merchant_id_foreign")
                    ->references('id')->on('merchants')->restrictOnDelete();

                $blueprint->foreign(['branch_id', 'merchant_id'], "{$table}_branch_merchant_foreign")
                    ->references(['id', 'merchant_id'])->on('merchant_branches')
                    ->cascadeOnDelete()->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign("{$table}_branch_merchant_foreign");
                $blueprint->dropForeign("{$table}_merchant_id_foreign");
                $blueprint->dropIndex("{$table}_merchant_branch_index");
                $blueprint->dropColumn('merchant_id');
            });
        }
    }
};
