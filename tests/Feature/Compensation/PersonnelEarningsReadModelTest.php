<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\PersonnelEarningsReadModel;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20h', 'phase20h-earnings');

/*
 | Phase 20H personnel earnings read model (Plan §63; §H10). Own-scope only; source-ledger money view
 | (no double counting with payout items); currency grouping; tab visibility by compensation model.
 */

function earningsReadModel(): PersonnelEarningsReadModel
{
    return app(PersonnelEarningsReadModel::class);
}

function activePlan(MerchantBranch $branch, StaffProfile $staff, CompensationModel $model): PersonnelCompensationPlan
{
    $ruleId = $model->requiresCommissionRule()
        ? CommissionRule::factory()->create([
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $branch->id,
        ])->id
        : null;

    return PersonnelCompensationPlan::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'compensation_model' => $model,
        'commission_rule_id' => $ruleId,
        'salary_amount_minor' => $model->requiresSalary() ? 5000000 : null,
        'salary_currency' => $model->requiresSalary() ? 'KES' : null,
        'salary_period' => $model->requiresSalary() ? 'monthly' : null,
        'status' => CompensationPlanStatus::Active,
        // An active 20F plan must carry its approval provenance (approval_status CHECK).
        'approved_by' => User::factory(),
        'approved_at' => now(),
    ]);
}

it('shows only the personnel own earnings and isolates other staff', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);

    [$otherBranch, $otherStaff] = payoutBranchStaff();
    earnedCommission($otherBranch, $otherStaff, 99999);

    $rows = earningsReadModel()->overview($staff);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['currency'])->toBe('KES')
        ->and($rows[0]['commission_unpaid_minor'])->toBe(50000)
        ->and($rows[0]['unpaid_minor'])->toBe(50000)
        ->and($rows[0]['paid_minor'])->toBe(0);
});

it('groups by currency and never combines them', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000, 'KES');
    earnedCommission($branch, $staff, 12345, 'USD');

    $rows = collect(earningsReadModel()->overview($staff))->keyBy('currency');

    expect($rows)->toHaveCount(2)
        ->and($rows['KES']['unpaid_minor'])->toBe(50000)
        ->and($rows['USD']['unpaid_minor'])->toBe(12345);
});

it('nets a reversed unpaid commission to zero in the overview', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    $reversed = earnedCommission($branch, $staff, 30000);
    $reversed->update(['status' => 'reversed']);
    CommissionLedgerEntry::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'branch_id' => $branch->id,
        'staff_profile_id' => $staff->id,
        'amount_minor' => -30000,
        'currency' => 'KES',
        'earned_at' => '2026-07-15 09:00:00',
        'entry_type' => 'reversal',
        'reversal_reason' => 'refund_finalized',
        'status' => 'earned',
        'source_entry_id' => $reversed->id,
    ]);

    // status <> paid includes the reversed (+30000) and its reversal (-30000): they net to zero.
    expect(earningsReadModel()->overview($staff)[0]['unpaid_minor'])->toBe(50000);
});

it('applies a negative adjustment and a positive adjustment to the net', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000);
    CompensationAdjustment::factory()->create([
        'merchant_id' => $branch->merchant_id, 'branch_id' => $branch->id, 'staff_profile_id' => $staff->id,
        'amount_minor' => -1000, 'currency' => 'KES',
    ]);
    CompensationAdjustment::factory()->create([
        'merchant_id' => $branch->merchant_id, 'branch_id' => $branch->id, 'staff_profile_id' => $staff->id,
        'amount_minor' => 500, 'currency' => 'KES',
    ]);

    $row = earningsReadModel()->overview($staff)[0];
    expect($row['adjustment_unpaid_minor'])->toBe(-500)
        ->and($row['unpaid_minor'])->toBe(49500);
});

it('shows salary tab only for salary_only', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    activePlan($branch, $staff, CompensationModel::SalaryOnly);

    $vis = earningsReadModel()->tabVisibility($staff);
    expect($vis['salary_tab'])->toBeTrue()->and($vis['commission_tab'])->toBeFalse()
        ->and($vis['model'])->toBe('salary_only');
});

it('shows commission tab only for commission_only', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    activePlan($branch, $staff, CompensationModel::CommissionOnly);

    $vis = earningsReadModel()->tabVisibility($staff);
    expect($vis['salary_tab'])->toBeFalse()->and($vis['commission_tab'])->toBeTrue();
});

it('shows both tabs for salary_plus_commission', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    activePlan($branch, $staff, CompensationModel::SalaryPlusCommission);

    $vis = earningsReadModel()->tabVisibility($staff);
    expect($vis['salary_tab'])->toBeTrue()->and($vis['commission_tab'])->toBeTrue();
});

it('keeps historical earnings visible when there is no current plan', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    earnedCommission($branch, $staff, 50000); // historical commission, no active plan

    $vis = earningsReadModel()->tabVisibility($staff);
    expect($vis['has_current_plan'])->toBeFalse()
        ->and($vis['commission_tab'])->toBeTrue()  // historical facts exist
        ->and($vis['salary_tab'])->toBeFalse();
});

it('fails closed on conflicting active plans (hides both tabs)', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    activePlan($branch, $staff, CompensationModel::SalaryOnly);
    // The per-branch exclusion constraint forbids two active plans in ONE branch; a second active plan
    // for the same staff in ANOTHER branch of the same merchant is allowed, and the own-scope read
    // model (querying by staff_profile_id) then sees two active plans and must fail closed.
    $branch2 = MerchantBranch::factory()->create(['merchant_id' => $branch->merchant_id]);
    activePlan($branch2, $staff, CompensationModel::CommissionOnly);

    $vis = earningsReadModel()->tabVisibility($staff);
    expect($vis['conflicting'])->toBeTrue()
        ->and($vis['salary_tab'])->toBeFalse()
        ->and($vis['commission_tab'])->toBeFalse();
});

it('exposes compensation terms for the current plan and a safe no-plan state', function (): void {
    [$branch, $staff] = payoutBranchStaff();
    $terms = earningsReadModel()->compensationTerms($staff);
    expect($terms['has_current_plan'])->toBeFalse();

    activePlan($branch, $staff, CompensationModel::SalaryOnly);
    $terms = earningsReadModel()->compensationTerms($staff);
    expect($terms['has_current_plan'])->toBeTrue()
        ->and($terms['compensation_model'])->toBe('salary_only')
        ->and($terms['salary_amount_minor'])->toBe(5000000);
});
