<?php

declare(strict_types=1);

use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-schema');

/*
 | Phase 20H schema + database-guard proof (Plan §62, §63, §13.12; §25.4/§25.5; ADR-002/004/005).
 | Runs on PostgreSQL 16. Proves the three tables exist with their canonical constraints, the
 | branch-owned tenant registration, the payout-item freeze guard, and the three expand FKs linking
 | the 20G ledgers to personnel_payout_items. Every throwing statement is the LAST DB write in its
 | test (a failed statement aborts the RefreshDatabase transaction).
 */

it('creates the three Phase 20H tables', function (): void {
    expect(Schema::hasTable('personnel_payout_runs'))->toBeTrue();
    expect(Schema::hasTable('personnel_payout_items'))->toBeTrue();
    expect(Schema::hasTable('earnings_queries'))->toBeTrue();
});

it('registers the three tables as branch-owned with composite consistency', function (): void {
    foreach (['personnel_payout_runs', 'personnel_payout_items', 'earnings_queries'] as $table) {
        expect(TenantOwnership::BRANCH_OWNED)->toContain($table);
        expect(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey($table);
    }
});

it('accepts a valid factory row for each Phase 20H model', function (): void {
    expect(PersonnelPayoutRun::factory()->create())->not->toBeNull();
    expect(PersonnelPayoutItem::factory()->create())->not->toBeNull();
    expect(EarningsQuery::factory()->create())->not->toBeNull();
});

it('adds the payout_item_id foreign key to all three 20G ledgers', function (): void {
    foreach (['commission_ledger', 'salary_ledger', 'compensation_adjustments'] as $table) {
        $fk = collect(Schema::getForeignKeys($table))
            ->first(fn (array $f): bool => in_array('payout_item_id', $f['columns'], true));
        expect($fk)->not->toBeNull("{$table}.payout_item_id must carry a foreign key");
        expect($fk['foreign_table'])->toBe('personnel_payout_items');
    }
});

it('enforces the item gross-sum CHECK', function (): void {
    $item = PersonnelPayoutItem::factory()->create();

    expect(fn () => DB::update(
        'update personnel_payout_items set salary_amount_minor = 100 where id = ?',
        [$item->id],
    ))->toThrow(QueryException::class); // gross (0) != salary(100)+commission(0)+adjustment(0)
});

it('enforces one item per (run, staff, currency)', function (): void {
    $item = PersonnelPayoutItem::factory()->create();

    expect(fn () => PersonnelPayoutItem::factory()->create([
        'payout_run_id' => $item->payout_run_id,
        'staff_profile_id' => $item->staff_profile_id,
        'currency' => $item->currency,
    ]))->toThrow(QueryException::class);
});

it('rejects a payout run whose period ends before it starts', function (): void {
    expect(fn () => PersonnelPayoutRun::factory()->create([
        'period_start' => '2026-07-31',
        'period_end' => '2026-07-01',
    ]))->toThrow(QueryException::class);
});

it('allows a payout-item status mirror transition', function (): void {
    $item = PersonnelPayoutItem::factory()->create();

    DB::update('update personnel_payout_items set status = ? where id = ?', ['submitted', $item->id]);

    expect(DB::table('personnel_payout_items')->where('id', $item->id)->value('status'))->toBe('submitted');
});

it('blocks UPDATE of a payout-item snapshot column (frozen)', function (): void {
    $item = PersonnelPayoutItem::factory()->create();

    // Move gross + salary together so the gross-sum CHECK passes; the freeze guard must still reject.
    expect(fn () => DB::update(
        'update personnel_payout_items set salary_amount_minor = 500, gross_amount_minor = 500 where id = ?',
        [$item->id],
    ))->toThrow(QueryException::class);
});

it('blocks DELETE of a non-draft payout item and allows DELETE of a draft item', function (): void {
    $draft = PersonnelPayoutItem::factory()->create(['status' => 'draft']);
    DB::delete('delete from personnel_payout_items where id = ?', [$draft->id]);
    expect(DB::table('personnel_payout_items')->where('id', $draft->id)->exists())->toBeFalse();

    $submitted = PersonnelPayoutItem::factory()->create(['status' => 'submitted']);
    expect(fn () => DB::delete('delete from personnel_payout_items where id = ?', [$submitted->id]))
        ->toThrow(QueryException::class);
});

it('rejects a cross-merchant payout_item_id link on a ledger (composite FK)', function (): void {
    $entry = CommissionLedgerEntry::factory()->create();
    // A payout item in a DIFFERENT merchant.
    $foreignItem = PersonnelPayoutItem::factory()->create();

    expect($foreignItem->merchant_id)->not->toBe($entry->merchant_id);

    expect(fn () => DB::update(
        'update commission_ledger set payout_item_id = ? where id = ?',
        [$foreignItem->id, $entry->id],
    ))->toThrow(QueryException::class);
});

it('links a same-merchant payout item on all three 20G ledgers', function (): void {
    $run = PersonnelPayoutRun::factory()->create();
    $item = PersonnelPayoutItem::factory()->create([
        'payout_run_id' => $run->id,
        'staff_profile_id' => fn () => StaffProfile::factory()->create([
            'merchant_id' => $run->merchant_id,
            'primary_branch_id' => $run->branch_id,
        ])->id,
    ]);

    $commission = CommissionLedgerEntry::factory()->create([
        'merchant_id' => $run->merchant_id,
        'branch_id' => $run->branch_id,
    ]);
    $salary = SalaryLedgerEntry::factory()->create([
        'merchant_id' => $run->merchant_id,
        'branch_id' => $run->branch_id,
    ]);
    $adjustment = CompensationAdjustment::factory()->create([
        'merchant_id' => $run->merchant_id,
        'branch_id' => $run->branch_id,
    ]);

    DB::update('update commission_ledger set payout_item_id = ? where id = ?', [$item->id, $commission->id]);
    DB::update('update salary_ledger set payout_item_id = ? where id = ?', [$item->id, $salary->id]);
    DB::update('update compensation_adjustments set payout_item_id = ? where id = ?', [$item->id, $adjustment->id]);

    expect((int) DB::table('commission_ledger')->where('id', $commission->id)->value('payout_item_id'))->toBe($item->id);
    expect((int) DB::table('salary_ledger')->where('id', $salary->id)->value('payout_item_id'))->toBe($item->id);
    expect((int) DB::table('compensation_adjustments')->where('id', $adjustment->id)->value('payout_item_id'))->toBe($item->id);
});
