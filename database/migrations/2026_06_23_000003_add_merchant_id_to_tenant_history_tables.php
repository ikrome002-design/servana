<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add merchant_id to tenant-owned tables that lacked it (Plan §2.1, §13.1;
 * ADR-002; Phase R5, REM-TEN-001). Expand-and-contract, forward-only.
 *
 *   - staff_history (append-only) — backfilled via staff_profiles.
 *   - merchant_user_permission_overrides — backfilled via merchant_users
 *     (Plan §13.5 lists merchant_id for this table).
 *
 * Each gains: merchant_id NOT NULL; merchant_id → merchants FK (RESTRICT); a
 * composite FK to its parent `(fk, merchant_id) → parent(id, merchant_id)` so
 * merchant_id can never disagree with the parent (ADR-002); and an index.
 */
return new class extends Migration
{
    /**
     * table => [parent, fk, index columns].
     *
     * @var array<string, array{parent: string, fk: string, index: list<string>}>
     */
    private array $tables = [
        'staff_history' => ['parent' => 'staff_profiles', 'fk' => 'staff_profile_id', 'index' => ['merchant_id', 'created_at']],
        'merchant_user_permission_overrides' => ['parent' => 'merchant_users', 'fk' => 'merchant_user_id', 'index' => ['merchant_id']],
    ];

    public function up(): void
    {
        // 1. Expand.
        foreach ($this->tables as $table => $meta) {
            Schema::table($table, function (Blueprint $blueprint) use ($meta): void {
                $blueprint->unsignedBigInteger('merchant_id')->nullable()->after($meta['fk']);
            });
        }

        // 2. Backfill via the authoritative parent (parameterized, idempotent).
        foreach ($this->tables as $table => $meta) {
            foreach (DB::table($meta['parent'])->select('id', 'merchant_id')->cursor() as $parent) {
                DB::table($table)
                    ->where($meta['fk'], $parent->id)
                    ->whereNull('merchant_id')
                    ->update(['merchant_id' => $parent->merchant_id]);
            }
        }

        // 3. Fail safely.
        foreach ($this->tables as $table => $meta) {
            $unresolved = DB::table($table)->whereNull('merchant_id')->count();
            if ($unresolved > 0) {
                throw new RuntimeException(
                    "R5 backfill: {$table} has {$unresolved} row(s) with no resolvable merchant_id ".
                    "(orphaned {$meta['fk']}). Resolve the data before re-running."
                );
            }
        }

        // 4–7. Contract.
        foreach ($this->tables as $table => $meta) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $meta): void {
                $blueprint->unsignedBigInteger('merchant_id')->nullable(false)->change();

                $blueprint->index($meta['index'], "{$table}_merchant_id_index");

                $blueprint->foreign('merchant_id', "{$table}_merchant_id_foreign")
                    ->references('id')->on('merchants')->restrictOnDelete();

                $blueprint->foreign([$meta['fk'], 'merchant_id'], "{$table}_parent_merchant_foreign")
                    ->references(['id', 'merchant_id'])->on($meta['parent'])
                    ->cascadeOnDelete()->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $meta) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign("{$table}_parent_merchant_foreign");
                $blueprint->dropForeign("{$table}_merchant_id_foreign");
                $blueprint->dropIndex("{$table}_merchant_id_index");
                $blueprint->dropColumn('merchant_id');
            });
        }
    }
};
