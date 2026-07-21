<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\EarningsQueryAssignedRole;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-enum-parity');

/*
 | Phase 20H canonical-enum parity (Plan §62, §63, §13.12). Proves each PHP enum's backing values are
 | EXACTLY the values in the matching PostgreSQL CHECK — zero mismatch, no alias.
 */

/** @return list<string> */
function phase20hCheckValues(string $table, string $constraint): array
{
    $rows = DB::select(
        'select pg_get_constraintdef(oid) as def from pg_constraint
         where conrelid = ?::regclass and conname = ?',
        [$table, $constraint],
    );

    expect($rows)->not->toBeEmpty("constraint {$constraint} on {$table} must exist");

    preg_match_all("/'([^']+)'/", $rows[0]->def, $matches);

    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/** @param list<string> $enumValues */
function phase20hExpectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(phase20hCheckValues($table, $constraint))->toBe($enumValues);
}

it('personnel_payout_runs.status matches PayoutRunStatus', function (): void {
    phase20hExpectParity('personnel_payout_runs', 'personnel_payout_runs_status_check', PayoutRunStatus::values());
});

it('personnel_payout_items.status matches PayoutItemStatus', function (): void {
    phase20hExpectParity('personnel_payout_items', 'personnel_payout_items_status_check', PayoutItemStatus::values());
});

it('payout run and item statuses share the same value set (item mirrors run)', function (): void {
    expect(PayoutItemStatus::values())->toBe(PayoutRunStatus::values());
});

it('earnings_queries.subject_type matches EarningsQuerySubjectType', function (): void {
    phase20hExpectParity('earnings_queries', 'earnings_queries_subject_type_check', EarningsQuerySubjectType::values());
});

it('earnings_queries.query_type matches EarningsQueryType', function (): void {
    phase20hExpectParity('earnings_queries', 'earnings_queries_query_type_check', EarningsQueryType::values());
});

it('earnings_queries.status matches EarningsQueryStatus', function (): void {
    phase20hExpectParity('earnings_queries', 'earnings_queries_status_check', EarningsQueryStatus::values());
});

it('earnings_queries.assigned_role matches EarningsQueryAssignedRole', function (): void {
    phase20hExpectParity('earnings_queries', 'earnings_queries_assigned_role_check', EarningsQueryAssignedRole::values());
});
