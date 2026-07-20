<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-schema');

/*
 | Phase 20G schema + database-guard proof (Plan §60, §61, §13.12; §9.1; ADR-002/004/005).
 | Runs on PostgreSQL 16. Proves the four tables exist with their canonical constraints,
 | tenant/branch registration, append-only guards, idempotency uniques, and the selected-services
 | membership immutability — the DB is the last line of defence. Every throwing statement is the
 | LAST DB write in its test: a failed statement aborts the RefreshDatabase test transaction, so a
 | positive assertion after a throw is a separate test.
 */

it('creates the four Phase 20G tables', function (): void {
    expect(Schema::hasTable('commission_ledger'))->toBeTrue();
    expect(Schema::hasTable('salary_ledger'))->toBeTrue();
    expect(Schema::hasTable('compensation_adjustments'))->toBeTrue();
    expect(Schema::hasTable('commission_rule_services'))->toBeTrue();
});

it('registers the four tables as branch-owned with composite consistency', function (): void {
    foreach (['commission_ledger', 'salary_ledger', 'compensation_adjustments', 'commission_rule_services'] as $table) {
        expect(TenantOwnership::BRANCH_OWNED)->toContain($table);
        expect(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey($table);
    }
});

it('allows a commission_ledger status + payout_item_id transition', function (): void {
    $entry = CommissionLedgerEntry::factory()->create();

    DB::update('update commission_ledger set status = ?, payout_item_id = ? where id = ?', ['included_in_payout', 99, $entry->id]);

    $row = DB::table('commission_ledger')->where('id', $entry->id)->first();
    expect($row->status)->toBe('included_in_payout');
    expect((int) $row->payout_item_id)->toBe(99);
});

it('blocks UPDATE of a commission_ledger monetary column (append-only)', function (): void {
    $entry = CommissionLedgerEntry::factory()->create();

    expect(fn () => DB::update('update commission_ledger set amount_minor = amount_minor + 1 where id = ?', [$entry->id]))
        ->toThrow(QueryException::class);
});

it('blocks DELETE of a commission_ledger row (append-only)', function (): void {
    $entry = CommissionLedgerEntry::factory()->create();

    expect(fn () => DB::delete('delete from commission_ledger where id = ?', [$entry->id]))
        ->toThrow(QueryException::class);
});

it('enforces one earned commission entry per (validation event, invoice item, staff)', function (): void {
    $entry = CommissionLedgerEntry::factory()->create();

    expect(fn () => CommissionLedgerEntry::factory()->create([
        'merchant_id' => $entry->merchant_id,
        'branch_id' => $entry->branch_id,
        'staff_profile_id' => $entry->staff_profile_id,
        'compensation_plan_id' => $entry->compensation_plan_id,
        'commission_rule_id' => $entry->commission_rule_id,
        'invoice_id' => $entry->invoice_id,
        'invoice_item_id' => $entry->invoice_item_id,
        'service_session_id' => $entry->service_session_id,
        'payment_validation_event_id' => $entry->payment_validation_event_id,
    ]))->toThrow(QueryException::class);
});

it('rejects an earned commission row without a validation event or earned_at', function (): void {
    $base = CommissionLedgerEntry::factory()->create();

    expect(fn () => DB::table('commission_ledger')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => $base->merchant_id,
        'branch_id' => $base->branch_id,
        'staff_profile_id' => $base->staff_profile_id,
        'compensation_plan_id' => $base->compensation_plan_id,
        'commission_rule_id' => $base->commission_rule_id,
        'invoice_id' => $base->invoice_id,
        'invoice_item_id' => $base->invoice_item_id,
        'entry_type' => 'earned',
        'calculation_basis_minor' => 1000,
        'amount_minor' => 100,
        'currency' => 'KES',
        'status' => 'earned',
        'payment_validation_event_id' => null,
        'earned_at' => null,
    ]))->toThrow(QueryException::class);
});

it('blocks DELETE of a salary_ledger row (append-only)', function (): void {
    $accrual = SalaryLedgerEntry::factory()->create();

    expect(fn () => DB::delete('delete from salary_ledger where id = ?', [$accrual->id]))
        ->toThrow(QueryException::class);
});

it('enforces one salary accrual per (plan, staff, pay-period segment)', function (): void {
    $accrual = SalaryLedgerEntry::factory()->create();

    expect(fn () => SalaryLedgerEntry::factory()->create([
        'merchant_id' => $accrual->merchant_id,
        'branch_id' => $accrual->branch_id,
        'staff_profile_id' => $accrual->staff_profile_id,
        'compensation_plan_id' => $accrual->compensation_plan_id,
        'pay_period_segment_key' => $accrual->pay_period_segment_key,
    ]))->toThrow(QueryException::class);
});

it('blocks DELETE of a compensation_adjustments row (append-only)', function (): void {
    $adjustment = CompensationAdjustment::factory()->create();

    expect(fn () => DB::delete('delete from compensation_adjustments where id = ?', [$adjustment->id]))
        ->toThrow(QueryException::class);
});

it('rejects a zero-amount compensation adjustment', function (): void {
    expect(fn () => CompensationAdjustment::factory()->create(['amount_minor' => 0]))
        ->toThrow(QueryException::class);
});

it('allows adding selected-services memberships while the rule is draft', function (): void {
    $membership = CommissionRuleService::factory()->create();
    $rule = $membership->commissionRule;

    $service2 = Service::factory()->create(['merchant_id' => $rule->merchant_id, 'branch_id' => $rule->branch_id]);
    $second = CommissionRuleService::factory()->create([
        'merchant_id' => $rule->merchant_id,
        'branch_id' => $rule->branch_id,
        'commission_rule_id' => $rule->id,
        'service_id' => $service2->id,
    ]);

    expect(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->count())->toBe(2);
    expect($second->id)->not->toBeNull();
});

it('freezes selected-services membership once the rule leaves draft (insert blocked)', function (): void {
    $membership = CommissionRuleService::factory()->create();
    $rule = $membership->commissionRule;
    DB::update('update commission_rules set status = ? where id = ?', [CommissionRuleStatus::PendingApproval->value, $rule->id]);

    $service = Service::factory()->create(['merchant_id' => $rule->merchant_id, 'branch_id' => $rule->branch_id]);

    expect(fn () => CommissionRuleService::factory()->create([
        'merchant_id' => $rule->merchant_id,
        'branch_id' => $rule->branch_id,
        'commission_rule_id' => $rule->id,
        'service_id' => $service->id,
    ]))->toThrow(QueryException::class);
});

it('freezes selected-services membership once the rule leaves draft (delete blocked)', function (): void {
    $membership = CommissionRuleService::factory()->create();
    $rule = $membership->commissionRule;
    DB::update('update commission_rules set status = ? where id = ?', [CommissionRuleStatus::PendingApproval->value, $rule->id]);

    expect(fn () => DB::delete('delete from commission_rule_services where id = ?', [$membership->id]))
        ->toThrow(QueryException::class);
});

it('blocks a selected_services rule from leaving draft with zero memberships', function (): void {
    $rule = CommissionRule::factory()->create([
        'applies_to' => CommissionAppliesTo::SelectedServices,
        'service_category_id' => null,
    ]);

    expect(fn () => DB::update('update commission_rules set status = ? where id = ?', [CommissionRuleStatus::PendingApproval->value, $rule->id]))
        ->toThrow(QueryException::class);
});

it('rejects a membership whose service is in a different branch', function (): void {
    $membership = CommissionRuleService::factory()->create();
    $rule = $membership->commissionRule;

    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $rule->merchant_id]);
    $foreignService = Service::factory()->create(['merchant_id' => $rule->merchant_id, 'branch_id' => $otherBranch->id]);

    expect(fn () => CommissionRuleService::factory()->create([
        'merchant_id' => $rule->merchant_id,
        'branch_id' => $rule->branch_id,
        'commission_rule_id' => $rule->id,
        'service_id' => $foreignService->id,
    ]))->toThrow(QueryException::class);
});
