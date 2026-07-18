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

it('creates no Phase 20H, payout, earnings, or Wallet substrate', function (): void {
    foreach ([
        'personnel_payout_runs',
        'personnel_payout_items',
        'earnings_queries',
        'personnel_earnings_queries',
        'wallet_accounts',
        'wallet_transactions',
    ] as $forbidden) {
        expect(Schema::hasTable($forbidden))->toBeFalse("Phase 20G must not create {$forbidden}");
    }
});

it('adds no payout_item_id foreign key on the ledgers (Phase 20H expand adds it)', function (): void {
    // The column exists (nullable) but must carry NO foreign key until personnel_payout_items ships.
    foreach (['commission_ledger', 'salary_ledger'] as $table) {
        $fkCount = collect(Schema::getForeignKeys($table))
            ->filter(fn (array $fk): bool => in_array('payout_item_id', $fk['columns'], true))
            ->count();
        expect($fkCount)->toBe(0);
    }
});
