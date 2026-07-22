<?php

declare(strict_types=1);

use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-isolation');

/*
 | Phase 20G tenant isolation + scope purity (Plan §2.1, §10.2; ADR-002). Composite FKs make a
 | cross-merchant reference impossible at the database; Phase 20G creates NO Phase 20H / Wallet /
 | payout / earnings substrate.
 */

it('forbids a commission_ledger row referencing a foreign-merchant staff profile', function (): void {
    $foreign = StaffProfile::factory()->create();

    expect(fn () => CommissionLedgerEntry::factory()->create(['staff_profile_id' => $foreign->id]))
        ->toThrow(QueryException::class);
});

it('forbids a salary_ledger row referencing a foreign-merchant staff profile', function (): void {
    $foreign = StaffProfile::factory()->create();

    expect(fn () => SalaryLedgerEntry::factory()->create(['staff_profile_id' => $foreign->id]))
        ->toThrow(QueryException::class);
});

it('introduces no Wallet substrate (Gate W CLOSED; 20D-W blocked)', function (): void {
    // Phase 20H legitimately shipped personnel_payout_runs / personnel_payout_items / earnings_queries,
    // so those are no longer forbidden. Wallet substrate remains forbidden while Gate W is CLOSED, and
    // no misnamed earnings table exists.
    foreach ([
        'personnel_earnings_queries',
        'wallet_accounts',
        'wallet_transactions',
    ] as $forbidden) {
        expect(Schema::hasTable($forbidden))->toBeFalse("no {$forbidden} substrate must exist");
    }
});

it('has a payout_item_id foreign key on the ledgers (Phase 20H expand added it)', function (): void {
    // Phase 20H shipped personnel_payout_items and the expand FK, so the column now carries a
    // composite (payout_item_id, merchant_id) foreign key on all three 20G ledgers.
    foreach (['commission_ledger', 'salary_ledger', 'compensation_adjustments'] as $table) {
        $fkCount = collect(Schema::getForeignKeys($table))
            ->filter(fn (array $fk): bool => in_array('payout_item_id', $fk['columns'], true))
            ->count();
        expect($fkCount)->toBeGreaterThan(0);
    }
});
