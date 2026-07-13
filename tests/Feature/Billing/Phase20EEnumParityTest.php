<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeAdjustmentType;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('billing', 'phase20e', 'phase20e-enum-parity');

/*
 | Phase 20E canonical-enum parity (Plan §13.10, §51). Proves each PHP enum's backing values are
 | EXACTLY the values in the matching PostgreSQL CHECK — zero mismatch, no alias. Uniquely-named
 | helpers avoid colliding with other phases' global parity functions.
 */

/** @return list<string> */
function phase20eCheckValues(string $table, string $constraint): array
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
function phase20eExpectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(phase20eCheckValues($table, $constraint))->toBe($enumValues);
}

it('platform_fee_configurations.billing_mode matches BillingMode', function (): void {
    phase20eExpectParity('platform_fee_configurations', 'platform_fee_configurations_billing_mode_check', BillingMode::values());
});

it('platform_fee_configurations.tier_behavior matches CanonicalPlatformFeeTier', function (): void {
    phase20eExpectParity('platform_fee_configurations', 'platform_fee_configurations_tier_behavior_check', CanonicalPlatformFeeTier::values());
});

it('platform_fee_configurations.fee_basis_type matches PlatformFeeBasisType', function (): void {
    phase20eExpectParity('platform_fee_configurations', 'platform_fee_configurations_fee_basis_type_check', PlatformFeeBasisType::values());
});

it('platform_fee_configurations.status matches PlatformFeeConfigurationStatus', function (): void {
    phase20eExpectParity('platform_fee_configurations', 'platform_fee_configurations_status_check', PlatformFeeConfigurationStatus::values());
});

it('platform_fee_ledger_entries.entry_type matches PlatformFeeEntryType', function (): void {
    phase20eExpectParity('platform_fee_ledger_entries', 'platform_fee_ledger_entries_entry_type_check', PlatformFeeEntryType::values());
});

it('platform_fee_ledger_entries.status matches PlatformFeeLedgerStatus', function (): void {
    phase20eExpectParity('platform_fee_ledger_entries', 'platform_fee_ledger_entries_status_check', PlatformFeeLedgerStatus::values());
});

it('platform_fee_ledger_entries.service_fee_tier_snapshot matches CanonicalPlatformFeeTier', function (): void {
    phase20eExpectParity('platform_fee_ledger_entries', 'platform_fee_ledger_entries_tier_snapshot_check', CanonicalPlatformFeeTier::values());
});

it('platform_fee_ledger_entries.fee_basis_type matches PlatformFeeBasisType', function (): void {
    phase20eExpectParity('platform_fee_ledger_entries', 'platform_fee_ledger_entries_fee_basis_type_check', PlatformFeeBasisType::values());
});

it('platform_fee_adjustments.adjustment_type matches PlatformFeeAdjustmentType', function (): void {
    phase20eExpectParity('platform_fee_adjustments', 'platform_fee_adjustments_type_check', PlatformFeeAdjustmentType::values());
});

it('platform_fee_disputes.status matches PlatformFeeDisputeStatus', function (): void {
    phase20eExpectParity('platform_fee_disputes', 'platform_fee_disputes_status_check', PlatformFeeDisputeStatus::values());
});

it('platform-fee dispute status deliberately excludes escalated', function (): void {
    expect(PlatformFeeDisputeStatus::values())->toBe(['open', 'under_review', 'resolved', 'rejected'])
        ->and(PlatformFeeDisputeStatus::values())->not->toContain('escalated');
});

it('platform-fee ledger status excludes provisional, billable, and settled', function (): void {
    expect(PlatformFeeLedgerStatus::values())->toBe(['pending', 'aggregated', 'invoiced', 'reversed', 'adjusted'])
        ->and(PlatformFeeLedgerStatus::values())->not->toContain('provisional')
        ->and(PlatformFeeLedgerStatus::values())->not->toContain('billable')
        ->and(PlatformFeeLedgerStatus::values())->not->toContain('settled');
});
