<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\ResolveEffectiveCommissionRule;
use App\Domain\Compensation\Actions\ResolveEffectiveCompensationPlan;
use App\Domain\Compensation\Actions\ResolvePreferredPersonnelFeeApplicability;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationResolutionException;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-resolvers');

/*
 | Phase 20F resolver proof (Plan §59; Scope §12.5, §12.9; F5/F6). Resolution returns CONFIGURATION
 | only — it computes no money, creates no row, and has no side effects. It NEVER falls back to a
 | silent default and NEVER picks arbitrarily between conflicting rows.
 */

/** A branch + a subject personnel inside it. */
function resolverScenario(): array
{
    $branch = MerchantBranch::factory()->create();

    return [
        'branch' => $branch,
        'staff' => StaffProfile::factory()->create([
            'merchant_id' => $branch->merchant_id,
            'primary_branch_id' => $branch->id,
        ]),
    ];
}

function planFor(array $scn, array $attributes = []): PersonnelCompensationPlan
{
    return PersonnelCompensationPlan::factory()->create(array_merge([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
    ], $attributes));
}

/**
 * An ACTIVE plan built through the factory's own `active()` state, which carries the approval
 * metadata the DB approval/status CHECK requires — an approved state can never exist without a
 * recorded approver.
 */
function activePlanFor(array $scn, array $attributes = []): PersonnelCompensationPlan
{
    return PersonnelCompensationPlan::factory()->active()->create(array_merge([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
    ], $attributes));
}

function resolvePlan(array $scn, $date = null): ?PersonnelCompensationPlan
{
    return app(ResolveEffectiveCompensationPlan::class)->handle($scn['staff'], $scn['branch']->id, $date);
}

// ---- effective compensation plan ------------------------------------------------

it('resolves the active plan effective on the date', function (): void {
    $scn = resolverScenario();
    $plan = activePlanFor($scn, ['effective_from' => today()->subDays(5), 'effective_to' => null]);

    expect(resolvePlan($scn)?->id)->toBe($plan->id);
});

it('returns null when no plan is configured — never a silent default', function (): void {
    expect(resolvePlan(resolverScenario()))->toBeNull();
});

it('ignores every non-active status', function (string $status): void {
    $scn = resolverScenario();

    PersonnelCompensationPlan::factory()->status(CompensationPlanStatus::from($status))->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'effective_from' => today()->subDays(5),
        'effective_to' => null,
    ]);

    expect(resolvePlan($scn))->toBeNull();
})->with(['draft', 'pending_approval', 'rejected', 'cancelled']);

it('ignores scheduled, superseded and expired plans', function (string $status): void {
    $scn = resolverScenario();

    // Built through the factory's own lifecycle states, so the approval metadata the DB
    // approval/status CHECK demands is present — only the STATUS differs from an active plan.
    PersonnelCompensationPlan::factory()->status(CompensationPlanStatus::from($status))->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'effective_from' => today()->subDays(5),
        'effective_to' => null,
    ]);

    expect(resolvePlan($scn))->toBeNull();
})->with(['scheduled', 'superseded', 'expired']);

it('does not resolve a plan before its effective_from', function (): void {
    $scn = resolverScenario();
    $plan = activePlanFor($scn, ['effective_from' => today()->addDays(5), 'effective_to' => null]);

    expect(resolvePlan($scn, today()))->toBeNull()
        ->and(resolvePlan($scn, today()->addDays(5))?->id)->toBe($plan->id);
});

it('stops resolving a plan ON its effective_to (half-open window)', function (): void {
    $scn = resolverScenario();
    $plan = activePlanFor($scn, ['effective_from' => today(), 'effective_to' => today()->addDays(10)]);

    expect(resolvePlan($scn, today()->addDays(9))?->id)->toBe($plan->id)
        // [from, to) — the end date itself is NOT covered.
        ->and(resolvePlan($scn, today()->addDays(10)))->toBeNull();
});

it('resolves per branch: another branch plan never leaks in', function (): void {
    $scn = resolverScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['branch']->merchant_id]);

    $otherPlan = PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $otherBranch->id,
        'staff_profile_id' => $scn['staff']->id,
        'effective_from' => today(),
    ]);

    // Same personnel, different branch → nothing resolves in THIS branch.
    expect(resolvePlan($scn))->toBeNull()
        ->and(app(ResolveEffectiveCompensationPlan::class)
            ->handle($scn['staff'], $otherBranch->id)?->id)->toBe($otherPlan->id);
});

it('resolves per personnel: another personnel plan never leaks in', function (): void {
    $scn = resolverScenario();
    $colleague = StaffProfile::factory()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'primary_branch_id' => $scn['branch']->id,
    ]);

    activePlanFor($scn, ['staff_profile_id' => $colleague->id, 'effective_from' => today()]);

    expect(resolvePlan($scn))->toBeNull();
});

it('fails closed when the one-active-plan invariant is violated', function (): void {
    $scn = resolverScenario();

    // The DB EXCLUDE makes this unreachable in practice, so drop it for this test only and force
    // the broken state — the point is that the resolver refuses to guess rather than silently
    // picking one. RefreshDatabase restores the constraint afterwards.
    DB::statement('ALTER TABLE personnel_compensation_plans DROP CONSTRAINT personnel_compensation_plans_no_overlap');

    activePlanFor($scn, ['effective_from' => today()->subDays(5), 'effective_to' => null]);
    activePlanFor($scn, ['effective_from' => today()->subDays(3), 'effective_to' => null]);

    resolvePlan($scn);
})->throws(CompensationResolutionException::class);

// ---- effective commission rule --------------------------------------------------

it('returns null for a salary_only plan — always', function (): void {
    // Plan §80 named test; Scope §12.5: salary-only has NO commission rule, so 20G can never
    // earn commission for that personnel.
    $scn = resolverScenario();
    $plan = PersonnelCompensationPlan::factory()->salaryOnly()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
    ]);

    expect($plan->commission_rule_id)->toBeNull()
        ->and(app(ResolveEffectiveCommissionRule::class)->handle($plan))->toBeNull();
});

it('resolves the active rule for a salary_plus_commission plan', function (): void {
    $scn = resolverScenario();
    $rule = CommissionRule::factory()->active()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'effective_from' => today()->subDay(),
    ]);
    $plan = PersonnelCompensationPlan::factory()->salaryPlusCommission()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'commission_rule_id' => $rule->id,
    ]);

    expect(app(ResolveEffectiveCommissionRule::class)->handle($plan)?->id)->toBe($rule->id);
});

it('fails closed when a commission_only plan has no active rule', function (): void {
    $scn = resolverScenario();
    $rule = CommissionRule::factory()->create([ // draft — never resolvable
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
    ]);
    $plan = PersonnelCompensationPlan::factory()->commissionOnly()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'commission_rule_id' => $rule->id,
    ]);

    app(ResolveEffectiveCommissionRule::class)->handle($plan);
})->throws(CompensationResolutionException::class);

it('fails closed when the rule has been ended before the date', function (): void {
    $scn = resolverScenario();
    $rule = CommissionRule::factory()->active()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'effective_from' => today()->subDays(10),
        'effective_to' => today()->subDays(2),
    ]);
    $plan = PersonnelCompensationPlan::factory()->commissionOnly()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'commission_rule_id' => $rule->id,
    ]);

    app(ResolveEffectiveCommissionRule::class)->handle($plan);
})->throws(CompensationResolutionException::class);

it('renders a resolution failure as a typed 409', function (): void {
    $exception = CompensationResolutionException::effectiveCommissionRuleMissing();

    expect($exception->render(request())->getStatusCode())->toBe(409)
        ->and($exception->errorCode())->toBe('effective_commission_rule_missing')
        ->and(CompensationResolutionException::effectivePlanConflict()->errorCode())->toBe('effective_plan_conflict');
});

it('computes no money while resolving', function (): void {
    $scn = resolverScenario();
    $rule = CommissionRule::factory()->active()->percentage(1500)->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'effective_from' => today()->subDay(),
    ]);
    $plan = PersonnelCompensationPlan::factory()->commissionOnly()->create([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
        'staff_profile_id' => $scn['staff']->id,
        'commission_rule_id' => $rule->id,
    ]);

    $resolved = app(ResolveEffectiveCommissionRule::class)->handle($plan);

    // Configuration comes back as configuration: a rate, not an amount.
    expect($resolved->percentage_basis_points)->toBe(1500)
        ->and($resolved->fixed_amount_minor)->toBeNull()
        ->and(CommissionHandoffEvent::query()->count())->toBe(0);
});

// ---- preferred-personnel-fee applicability (F6) ---------------------------------

it('returns the exact preferred-personnel-fee inclusion flag', function (bool $flag): void {
    $rule = CommissionRule::factory()->create(['applies_to_preferred_personnel_fee' => $flag]);

    expect(app(ResolvePreferredPersonnelFeeApplicability::class)->handle($rule))->toBe($flag);
})->with([true, false]);

it('reports no preferred-personnel-fee inclusion when there is no rule', function (): void {
    // A salary_only plan resolves no rule — there is no basis to include anything in.
    expect(app(ResolvePreferredPersonnelFeeApplicability::class)->handle(null))->toBeFalse();
});

it('defaults preferred-personnel-fee inclusion to excluded', function (): void {
    $rule = CommissionRule::factory()->create();

    expect($rule->applies_to_preferred_personnel_fee)->toBeFalse()
        ->and(app(ResolvePreferredPersonnelFeeApplicability::class)->handle($rule))->toBeFalse();
});
