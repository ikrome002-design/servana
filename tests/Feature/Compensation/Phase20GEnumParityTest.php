<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\CommissionLedgerEntryType;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\CommissionReversalReason;
use App\Domain\Compensation\Enums\CompensationAdjustmentType;
use App\Domain\Compensation\Enums\SalaryLedgerEntryType;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Enums\SuspensionSalaryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-enum-parity');

/*
 | Phase 20G canonical-enum parity (Plan §60, §61, §13.12). Proves each PHP enum's backing values
 | are EXACTLY the values in the matching PostgreSQL CHECK — zero mismatch, no alias.
 */

/** @return list<string> */
function phase20gCheckValues(string $table, string $constraint): array
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
function phase20gExpectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(phase20gCheckValues($table, $constraint))->toBe($enumValues);
}

it('commission_ledger.entry_type matches CommissionLedgerEntryType', function (): void {
    phase20gExpectParity('commission_ledger', 'commission_ledger_entry_type_check', CommissionLedgerEntryType::values());
});

it('commission_ledger.reversal_reason matches CommissionReversalReason', function (): void {
    phase20gExpectParity('commission_ledger', 'commission_ledger_reversal_reason_check', CommissionReversalReason::values());
});

it('commission_ledger.status matches CommissionLedgerStatus', function (): void {
    phase20gExpectParity('commission_ledger', 'commission_ledger_status_check', CommissionLedgerStatus::values());
});

it('salary_ledger.entry_type matches SalaryLedgerEntryType', function (): void {
    phase20gExpectParity('salary_ledger', 'salary_ledger_entry_type_check', SalaryLedgerEntryType::values());
});

it('salary_ledger.status matches SalaryLedgerStatus', function (): void {
    phase20gExpectParity('salary_ledger', 'salary_ledger_status_check', SalaryLedgerStatus::values());
});

it('compensation_adjustments.adjustment_type matches CompensationAdjustmentType', function (): void {
    phase20gExpectParity('compensation_adjustments', 'compensation_adjustments_type_check', CompensationAdjustmentType::values());
});

it('personnel_compensation_plans.suspension_salary_policy matches SuspensionSalaryPolicy', function (): void {
    phase20gExpectParity('personnel_compensation_plans', 'personnel_compensation_plans_suspension_salary_policy_check', SuspensionSalaryPolicy::values());
});
