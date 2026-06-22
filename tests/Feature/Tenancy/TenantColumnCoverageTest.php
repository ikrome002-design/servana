<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('tenancy', 'isolation');

/*
 | Schema coverage (Plan §2.1, §13.1, §13.3; ADR-002; R5). Inspects the REAL
 | PostgreSQL schema and fails when a tenant-/branch-owned table lacks its
 | required ownership columns, nullability, FKs/indexes, or the DB consistency
 | constraint — or when a table is unclassified (undocumented exemption).
 */

/** YES/NO/null(absent) nullability of a column (parameterized — no raw concat). */
function columnNullable(string $table, string $column): ?bool
{
    $row = DB::selectOne(
        'SELECT is_nullable FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        ['public', $table, $column],
    );

    return $row === null ? null : ($row->is_nullable === 'YES');
}

/** True when an index whose definition starts with `(merchant_id` exists. */
function hasMerchantIndex(string $table): bool
{
    $indexes = DB::select('SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = ?', ['public', $table]);

    foreach ($indexes as $index) {
        if (str_contains((string) $index->indexdef, '(merchant_id')) {
            return true;
        }
    }

    return false;
}

/** True when a 2-column composite FK from $table → $parent exists (consistency FK). */
function hasCompositeConsistencyFk(string $table, string $parent): bool
{
    $row = DB::selectOne(
        'SELECT 1 AS ok FROM pg_constraint con
         JOIN pg_class rel ON rel.oid = con.conrelid
         JOIN pg_class fref ON fref.oid = con.confrelid
         WHERE rel.relname = ? AND fref.relname = ? AND con.contype = ? AND array_length(con.conkey, 1) = 2
         LIMIT 1',
        [$table, $parent, 'f'],
    );

    return $row !== null;
}

it('classifies every existing base table (no undocumented table)', function (): void {
    $tables = collect(DB::select(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
    ))->pluck('table_name');

    $classified = array_merge(
        TenantOwnership::BRANCH_OWNED,
        TenantOwnership::TENANT_OWNED,
        array_keys(TenantOwnership::EXEMPT),
    );

    $undocumented = $tables->reject(fn (string $t): bool => in_array($t, $classified, true))->values();

    expect($undocumented->all())->toBe([], 'Undocumented tables: '.$undocumented->implode(', '));
});

it('every tenant-owned table has a non-null merchant_id with FK + index', function (): void {
    foreach (TenantOwnership::TENANT_OWNED as $table) {
        expect(columnNullable($table, 'merchant_id'))->toBe(false, "{$table}.merchant_id must exist and be NOT NULL")
            ->and(hasMerchantIndex($table))->toBeTrue("{$table} must have an index beginning with merchant_id");
    }
});

it('every branch-owned table has non-null merchant_id + branch_id', function (): void {
    foreach (TenantOwnership::BRANCH_OWNED as $table) {
        expect(columnNullable($table, 'merchant_id'))->toBe(false, "{$table}.merchant_id must be NOT NULL")
            ->and(columnNullable($table, 'branch_id'))->toBe(false, "{$table}.branch_id must be NOT NULL")
            ->and(hasMerchantIndex($table))->toBeTrue("{$table} must have an index beginning with merchant_id");
    }
});

it('enforces merchant_id/parent consistency with a composite FK', function (): void {
    foreach (TenantOwnership::COMPOSITE_CONSISTENCY as $table => $meta) {
        expect(hasCompositeConsistencyFk($table, $meta['parent']))
            ->toBeTrue("{$table} must have a composite FK → {$meta['parent']}(id, merchant_id)");
    }
});

it('keeps idempotency_keys cross-cutting (nullable merchant_id by design)', function (): void {
    // R4 invariant must survive R5: platform/webhook scopes have null merchant.
    expect(columnNullable('idempotency_keys', 'merchant_id'))->toBe(true)
        ->and(TenantOwnership::EXEMPT)->toHaveKey('idempotency_keys')
        ->and(TenantOwnership::EXEMPT)->toHaveKey('audit_logs');
});
