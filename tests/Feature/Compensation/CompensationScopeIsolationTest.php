<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Actions\CreateCommissionRuleDraft;
use App\Domain\Compensation\Actions\CreateCompensationPlanDraft;
use App\Domain\Compensation\Actions\UpdateCompensationPlanDraft;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-scope');

/*
 | Phase 20F tenant/branch isolation proof (Plan §59; ADR-002; guardrail §6.3). HR is same-branch
 | only, so a foreign subject or rule must be indistinguishable from one that does not exist — 404,
 | never 403. The composite FKs are the authoritative guard; the actions fail earlier and friendlier.
 */

function scopeBranch(): MerchantBranch
{
    return MerchantBranch::factory()->create();
}

function staffIn(MerchantBranch $branch): StaffProfile
{
    return StaffProfile::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'primary_branch_id' => $branch->id,
    ]);
}

// ---- staff-profile scope --------------------------------------------------------

it('rejects a plan draft for a staff profile from another merchant', function (): void {
    $branch = scopeBranch();
    $foreignStaff = StaffProfile::factory()->create(); // its own merchant + branch

    app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $foreignStaff,
        branchId: $branch->id,
        actor: User::factory()->create(),
        model: CompensationModel::SalaryOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Cross-merchant attempt.',
        salaryAmountMinor: 5000000,
        salaryCurrency: 'KES',
        salaryPeriod: SalaryPeriod::Monthly,
    );
})->throws(CompensationScopeException::class);

it('rejects a plan draft for a staff profile from another branch of the same merchant', function (): void {
    $branch = scopeBranch();
    $sibling = MerchantBranch::factory()->create(['merchant_id' => $branch->merchant_id]);
    $staffElsewhere = staffIn($sibling);

    app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $staffElsewhere,
        branchId: $branch->id,
        actor: User::factory()->create(),
        model: CompensationModel::SalaryOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Cross-branch attempt.',
        salaryAmountMinor: 5000000,
        salaryCurrency: 'KES',
        salaryPeriod: SalaryPeriod::Monthly,
    );
})->throws(CompensationScopeException::class);

it('renders a foreign subject as 404, never 403', function (): void {
    // A 403 would confirm to an unauthorized caller that the row exists.
    $response = CompensationScopeException::staffProfile()->render(request());

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true)['error']['code'])->toBe('staff_profile_scope_mismatch')
        ->and(CompensationScopeException::commissionRule()->render(request())->getStatusCode())->toBe(404);
});

it('echoes no foreign identifier in a scope-mismatch message', function (): void {
    $foreignStaff = StaffProfile::factory()->create();

    expect(CompensationScopeException::staffProfile()->getMessage())
        ->not->toContain($foreignStaff->ulid)
        ->and(CompensationScopeException::staffProfile()->getMessage())
        ->toBe('The requested personnel was not found in this branch.');
});

// ---- commission-rule scope ------------------------------------------------------

it('rejects a plan draft referencing a rule from another merchant', function (): void {
    $branch = scopeBranch();

    app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: staffIn($branch),
        branchId: $branch->id,
        actor: User::factory()->create(),
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Foreign rule attempt.',
        commissionRule: CommissionRule::factory()->create(), // another merchant entirely
    );
})->throws(CompensationScopeException::class);

it('rejects a plan draft referencing a rule from another branch of the same merchant', function (): void {
    $branch = scopeBranch();
    $sibling = MerchantBranch::factory()->create(['merchant_id' => $branch->merchant_id]);

    app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: staffIn($branch),
        branchId: $branch->id,
        actor: User::factory()->create(),
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Cross-branch rule attempt.',
        commissionRule: CommissionRule::factory()->create([
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $sibling->id,
        ]),
    );
})->throws(CompensationScopeException::class);

it('rejects a draft update that swaps in a foreign commission rule', function (): void {
    $branch = scopeBranch();
    $staff = staffIn($branch);
    $actor = User::factory()->create();

    $plan = app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $staff,
        branchId: $branch->id,
        actor: $actor,
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Initial.',
        commissionRule: CommissionRule::factory()->create([
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $branch->id,
        ]),
    );

    app(UpdateCompensationPlanDraft::class)->handle(
        plan: $plan,
        actor: $actor,
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Swapping in a foreign rule.',
        commissionRule: CommissionRule::factory()->create(),
    );
})->throws(CompensationScopeException::class);

it('rejects a commission rule draft bound to a service category from another branch', function (): void {
    $branch = scopeBranch();
    $sibling = MerchantBranch::factory()->create(['merchant_id' => $branch->merchant_id]);

    app(CreateCommissionRuleDraft::class)->handle(
        branch: $branch,
        actor: User::factory()->create(),
        calculationType: CommissionCalculationType::Percentage,
        calculationBasis: CommissionCalculationBasis::ServicePrice,
        appliesTo: CommissionAppliesTo::ServiceCategory,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Cross-branch category attempt.',
        percentageBasisPoints: 1000,
        serviceCategory: ServiceCategory::factory()->create([
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $sibling->id,
        ]),
    );
})->throws(CompensationScopeException::class);

// ---- the database is the final arbiter ------------------------------------------

it('rejects history that points at a plan from another merchant', function (): void {
    $history = CompensationPlanHistory::factory()->create();
    $foreignPlan = PersonnelCompensationPlan::factory()->create();

    DB::table('compensation_plan_history')->where('id', $history->id)
        ->update(['compensation_plan_id' => $foreignPlan->id]);
})->throws(QueryException::class);

it('rejects history whose branch belongs to another merchant', function (): void {
    $history = CompensationPlanHistory::factory()->create();
    $foreignBranch = MerchantBranch::factory()->create();

    DB::table('compensation_plan_history')->where('id', $history->id)
        ->update(['branch_id' => $foreignBranch->id]);
})->throws(QueryException::class);

it('rejects a commission rule whose branch belongs to another merchant', function (): void {
    $rule = CommissionRule::factory()->create();
    $foreignBranch = MerchantBranch::factory()->create();

    DB::table('commission_rules')->where('id', $rule->id)
        ->update(['branch_id' => $foreignBranch->id]);
})->throws(QueryException::class);

it('keeps history inside the branch of the plan it describes', function (): void {
    $branch = scopeBranch();
    $staff = staffIn($branch);

    $plan = app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $staff,
        branchId: $branch->id,
        actor: User::factory()->create(),
        model: CompensationModel::SalaryOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Initial.',
        salaryAmountMinor: 5000000,
        salaryCurrency: 'KES',
        salaryPeriod: SalaryPeriod::Monthly,
    );

    $history = CompensationPlanHistory::query()->where('compensation_plan_id', $plan->id)->sole();

    expect($history->merchant_id)->toBe($branch->merchant_id)
        ->and($history->branch_id)->toBe($branch->id)
        ->and($history->staff_profile_id)->toBe($staff->id);
});
