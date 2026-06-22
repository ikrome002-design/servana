<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant/branch hardening (Plan §2.1, §8.2, §13.1; ADR-002; Phase R5,
 * REM-TEN-001). Forward-only.
 *
 * Adds `UNIQUE (id, merchant_id)` to the parent tables so the branch-owned and
 * history child tables can reference `(fk, merchant_id) → (id, merchant_id)` with
 * a composite foreign key — the database-level guarantee that a child's
 * merchant_id can never disagree with its parent. `id` is already the PK; the
 * extra unique pair exists only to be a composite-FK target.
 *
 * No shipped migration edited; no data change.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $parents = ['merchant_branches', 'staff_profiles', 'merchant_users'];

    public function up(): void
    {
        foreach ($this->parents as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique(['id', 'merchant_id'], "{$table}_id_merchant_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->parents as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_id_merchant_id_unique");
            });
        }
    }
};
