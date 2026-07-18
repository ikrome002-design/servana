<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\AccrueSalaryForPayPeriod;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Enums\SuspensionSalaryPolicy;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\Hr\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-salary');

/*
 | Phase 20G salary accrual (Plan §60; G8/G9/G10). Exercises the segmenter + proration calculator +
 | append-only salary_ledger through AccrueSalaryForPayPeriod: Actual/Actual proration, mid-period
 | plan change, suspension pause, termination, attendance fail-closed, idempotency, and period locks.
 */

function salaryStaff(): array
{
    $branch = MerchantBranch::factory()->create();
    $staff = StaffProfile::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'primary_branch_id' => $branch->id,
    ]);

    return [$staff, (int) $branch->id];
}

function salaryPlan(StaffProfile $staff, int $branchId, array $overrides = []): PersonnelCompensationPlan
{
    // ->active() supplies the maker/checker approval metadata the DB CHECK requires for an
    // effective plan; overrides carry the salary terms + effective window.
    return PersonnelCompensationPlan::factory()->active()->create(array_merge([
        'merchant_id' => $staff->merchant_id,
        'branch_id' => $branchId,
        'staff_profile_id' => $staff->id,
        'commission_rule_id' => null,
        'compensation_model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'suspension_salary_policy' => SuspensionSalaryPolicy::Continue,
    ], $overrides));
}

function accrue(StaffProfile $staff, int $branchId, string $periodStart, SalaryPeriod $cadence = SalaryPeriod::Monthly)
{
    return app(AccrueSalaryForPayPeriod::class)->handle(
        $staff, $branchId, $cadence, CarbonImmutable::parse($periodStart, 'Africa/Nairobi'),
    );
}

it('accrues one full monthly salary for an unchanged plan', function (): void {
    [$staff, $branchId] = salaryStaff();
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => null, 'salary_amount_minor' => 5000000]);

    $outcome = accrue($staff, $branchId, '2026-06-01');

    expect($outcome->isAccrued())->toBeTrue();
    $rows = SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->sum('amount_minor'))->toBe(5000000);
    expect(AuditLog::query()->where('action', 'compensation.salary.accrued')->count())->toBe(1);
});

it('accrues a full monthly salary regardless of month length (Feb 28, leap Feb 29, 31)', function (): void {
    foreach (['2026-02-01' => 5000000, '2028-02-01' => 5000000, '2026-07-01' => 5000000] as $start => $expected) {
        [$staff, $branchId] = salaryStaff();
        salaryPlan($staff, $branchId, ['effective_from' => $start, 'effective_to' => null]);
        accrue($staff, $branchId, $start);
        expect((int) SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->sum('amount_minor'))->toBe($expected);
    }
});

it('splits a mid-period salary change into two segments totalling KES 36,000 (product-owner example)', function (): void {
    [$staff, $branchId] = salaryStaff();
    // 30-day month; old KES 30,000 through day 15; new KES 42,000 from day 16.
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => '2026-06-16', 'salary_amount_minor' => 3000000]);
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-16', 'effective_to' => null, 'salary_amount_minor' => 4200000]);

    accrue($staff, $branchId, '2026-06-01');

    $rows = SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->orderBy('pay_period_start')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->amount_minor)->toBe(1500000);
    expect($rows[1]->amount_minor)->toBe(2100000);
    expect($rows->sum('amount_minor'))->toBe(3600000);
});

it('does not accrue paused days (prospective suspension) but accrues the continue window', function (): void {
    [$staff, $branchId] = salaryStaff();
    // Continue days 1-15, then a superseding PAUSE version days 16-end of a 30-day month.
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => '2026-06-16', 'salary_amount_minor' => 3000000]);
    salaryPlan($staff, $branchId, [
        'effective_from' => '2026-06-16', 'effective_to' => null,
        'salary_amount_minor' => 3000000, 'suspension_salary_policy' => SuspensionSalaryPolicy::Pause,
    ]);

    accrue($staff, $branchId, '2026-06-01');

    $rows = SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->get();
    expect($rows)->toHaveCount(1);
    // 15 of 30 days at KES 30,000.00.
    expect($rows->sum('amount_minor'))->toBe(1500000);
});

it('treats a termination (plan lineage ends mid-month) as final payable day, accruing only covered days', function (): void {
    [$staff, $branchId] = salaryStaff();
    // Terminated with final payable day 2026-06-20 => effective_to exclusive boundary 2026-06-21.
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => '2026-06-21', 'salary_amount_minor' => 3000000]);

    accrue($staff, $branchId, '2026-06-01');

    // 20 of 30 days at KES 30,000.00 = KES 20,000.00.
    expect((int) SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->sum('amount_minor'))->toBe(2000000);
});

it('fails closed for a daily cadence with no approved attendance source and writes no ledger row or success audit', function (): void {
    [$staff, $branchId] = salaryStaff();
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => null, 'salary_period' => SalaryPeriod::Daily]);

    $outcome = accrue($staff, $branchId, '2026-06-01', SalaryPeriod::Daily);

    expect($outcome->status)->toBe('fail_closed_attendance');
    expect(SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'compensation.salary.accrued')->count())->toBe(0);
});

it('is idempotent: a replay creates no duplicate accrual', function (): void {
    [$staff, $branchId] = salaryStaff();
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => null]);

    accrue($staff, $branchId, '2026-06-01');
    $second = accrue($staff, $branchId, '2026-06-01');

    expect($second->createdEntryIds)->toBe([]);
    expect(SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->count())->toBe(1);
});

it('skips (no original accrual) when the pay period is financially locked', function (): void {
    $this->mock(PeriodLockRepository::class, function ($mock): void {
        $mock->shouldReceive('isLocked')->andReturn(true);
    });

    [$staff, $branchId] = salaryStaff();
    salaryPlan($staff, $branchId, ['effective_from' => '2026-06-01', 'effective_to' => null]);

    $outcome = accrue($staff, $branchId, '2026-06-01');

    expect($outcome->status)->toBe('skipped_period_locked');
    expect(SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->count())->toBe(0);
});

it('accrues nothing for a commission-only plan', function (): void {
    [$staff, $branchId] = salaryStaff();
    PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $staff->merchant_id, 'branch_id' => $branchId, 'staff_profile_id' => $staff->id,
        'compensation_model' => CompensationModel::CommissionOnly,
        'salary_amount_minor' => null, 'salary_currency' => null, 'salary_period' => null,
        'effective_from' => '2026-06-01', 'effective_to' => null,
    ]);

    $outcome = accrue($staff, $branchId, '2026-06-01');

    expect($outcome->status)->toBe('nothing_payable');
    expect(SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->count())->toBe(0);
});

it('accrues one full weekly salary for a 7-day week', function (): void {
    [$staff, $branchId] = salaryStaff();
    salaryPlan($staff, $branchId, [
        'effective_from' => '2026-07-06', 'effective_to' => null,
        'salary_period' => SalaryPeriod::Weekly, 'salary_amount_minor' => 700000,
    ]);

    accrue($staff, $branchId, '2026-07-06', SalaryPeriod::Weekly);

    expect((int) SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->sum('amount_minor'))->toBe(700000);
});
