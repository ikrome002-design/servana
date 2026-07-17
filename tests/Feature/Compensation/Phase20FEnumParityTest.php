<?php

declare(strict_types=1);

use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Hr\Enums\StaffEmploymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-enum-parity');

/*
 | Phase 20F canonical-enum parity (Plan §59, §80; Scope §12.9). Proves each PHP enum's backing
 | values are EXACTLY the values in the matching PostgreSQL CHECK — zero mismatch, no alias.
 | Uniquely-named helpers avoid colliding with other phases' global parity functions.
 */

/** @return list<string> */
function phase20fCheckValues(string $table, string $constraint): array
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
function phase20fExpectParity(string $table, string $constraint, array $enumValues): void
{
    sort($enumValues);
    expect(phase20fCheckValues($table, $constraint))->toBe($enumValues);
}

it('commission_rules.calculation_type matches CommissionCalculationType', function (): void {
    phase20fExpectParity('commission_rules', 'commission_rules_calculation_type_check', CommissionCalculationType::values());
});

it('commission_rules.calculation_basis matches CommissionCalculationBasis', function (): void {
    phase20fExpectParity('commission_rules', 'commission_rules_calculation_basis_check', CommissionCalculationBasis::values());
});

it('commission_rules.applies_to matches CommissionAppliesTo', function (): void {
    phase20fExpectParity('commission_rules', 'commission_rules_applies_to_check', CommissionAppliesTo::values());
});

it('commission_rules.status matches CommissionRuleStatus', function (): void {
    phase20fExpectParity('commission_rules', 'commission_rules_status_check', CommissionRuleStatus::values());
});

it('personnel_compensation_plans.compensation_model matches CompensationModel', function (): void {
    phase20fExpectParity(
        'personnel_compensation_plans',
        'personnel_compensation_plans_compensation_model_check',
        CompensationModel::values(),
    );
});

it('personnel_compensation_plans.salary_period matches SalaryPeriod', function (): void {
    phase20fExpectParity(
        'personnel_compensation_plans',
        'personnel_compensation_plans_salary_period_check',
        SalaryPeriod::values(),
    );
});

it('personnel_compensation_plans.status matches CompensationPlanStatus', function (): void {
    phase20fExpectParity(
        'personnel_compensation_plans',
        'personnel_compensation_plans_status_check',
        CompensationPlanStatus::values(),
    );
});

it('compensation_plan_history.event matches CompensationPlanHistoryEvent', function (): void {
    phase20fExpectParity(
        'compensation_plan_history',
        'compensation_plan_history_event_check',
        CompensationPlanHistoryEvent::values(),
    );
});

it('records activation as its own history event, symmetric with expired (Increment 3)', function (): void {
    // The scheduled → active boundary is a real transition and must be visible in compensation
    // history. `approved` would collapse two distinct lifecycle moments; omitting it would make
    // activation invisible. Enum, DB CHECK, state-machine spec and data dictionary stay in parity.
    expect(CompensationPlanHistoryEvent::values())->toContain('activated')
        ->and(CompensationPlanHistoryEvent::values())->toContain('expired')
        ->and(phase20fCheckValues('compensation_plan_history', 'compensation_plan_history_event_check'))
        ->toContain('activated');

    // The boundary partners are distinct events, not aliases of the approval decision.
    expect(CompensationPlanHistoryEvent::Activated->value)->not->toBe(CompensationPlanHistoryEvent::Approved->value)
        ->and(CompensationPlanHistoryEvent::Activated->hasFromStatus())->toBeTrue();
});

it('carries the nine canonical history events and no financial event', function (): void {
    expect(CompensationPlanHistoryEvent::values())->toBe([
        'created', 'updated_draft', 'submitted', 'approved', 'activated',
        'rejected', 'cancelled', 'superseded', 'expired',
    ]);
});

it('compensation_plan_history from/to status mirror the plan status vocabulary', function (): void {
    phase20fExpectParity(
        'compensation_plan_history',
        'compensation_plan_history_to_status_check',
        CompensationPlanStatus::values(),
    );
    phase20fExpectParity(
        'compensation_plan_history',
        'compensation_plan_history_from_status_check',
        CompensationPlanStatus::values(),
    );
});

// ---- vocabulary invariants (F1, F3, F5) ----------------------------------------

it('carries exactly the F1 compensation-model vocabulary', function (): void {
    expect(CompensationModel::values())->toBe(['commission_only', 'salary_plus_commission', 'salary_only']);
});

it('keeps the compensation model distinct from staff employment type (F1; Scope §12.2)', function (): void {
    // F1: `commission_only` DELIBERATELY exists in BOTH vocabularies — staff_profiles.
    // employment_type (an HR employment fact) and personnel_compensation_plans.
    // compensation_model (how the personnel earns). The shared label does NOT make the two
    // fields interchangeable, and the vocabularies must never be merged. This test pins the
    // overlap so it can never silently widen into a de-facto merge.
    $employmentTypes = array_column(StaffEmploymentType::cases(), 'value');

    expect(CompensationModel::class)->not->toBe(StaffEmploymentType::class)
        ->and(array_values(array_intersect(CompensationModel::values(), $employmentTypes)))->toBe(['commission_only'])
        ->and(CompensationModel::values())->not->toBe($employmentTypes);

    // Neither column's CHECK accepts the other vocabulary's exclusive values: an employment
    // type is never a valid compensation model, and vice versa.
    $planModels = phase20fCheckValues('personnel_compensation_plans', 'personnel_compensation_plans_compensation_model_check');
    $staffTypes = phase20fCheckValues('staff_profiles', 'staff_profiles_employment_type_check');

    expect(array_intersect(['full_time', 'part_time', 'contract'], $planModels))->toBe([])
        ->and(array_intersect(['salary_only', 'salary_plus_commission'], $staffTypes))->toBe([]);
});

it('carries the Scope §12.9 eight-status plan vocabulary', function (): void {
    expect(CompensationPlanStatus::values())->toHaveCount(8)
        ->and(CompensationPlanStatus::values())->toEqualCanonicalizing([
            'draft', 'pending_approval', 'scheduled', 'active',
            'expired', 'superseded', 'rejected', 'cancelled',
        ]);
});

it('declares no earned, paid, or settled status anywhere in Phase 20F', function (): void {
    // Those belong to Phases 20G/20H — a configuration aggregate never carries them.
    $forbidden = ['paid', 'settled', 'earned', 'accrued', 'disbursed'];

    foreach ([CompensationPlanStatus::values(), CommissionRuleStatus::values(), CompensationPlanHistoryEvent::values()] as $values) {
        expect(array_intersect($values, $forbidden))->toBe([]);
    }
});

it('guards only active and scheduled plans in the overlap exclusion (F3)', function (): void {
    expect(CompensationPlanStatus::overlapGuardedValues())->toBe(['active', 'scheduled']);

    $def = DB::select(
        'select pg_get_constraintdef(oid) as def from pg_constraint
         where conrelid = ?::regclass and conname = ?',
        ['personnel_compensation_plans', 'personnel_compensation_plans_no_overlap'],
    )[0]->def;

    // The partial predicate covers active + scheduled only; the range is half-open.
    expect($def)->toContain("'active'")
        ->and($def)->toContain("'scheduled'")
        ->and($def)->toContain('[)')
        ->and($def)->not->toContain("'draft'")
        ->and($def)->not->toContain("'pending_approval'")
        ->and($def)->not->toContain("'superseded'")
        ->and($def)->not->toContain("'expired'")
        ->and($def)->not->toContain("'rejected'")
        ->and($def)->not->toContain("'cancelled'");
});

it('agrees with the state-machine spec on which model requires a commission rule (F5)', function (): void {
    expect(CompensationModel::SalaryOnly->requiresCommissionRule())->toBeFalse()
        ->and(CompensationModel::CommissionOnly->requiresCommissionRule())->toBeTrue()
        ->and(CompensationModel::SalaryPlusCommission->requiresCommissionRule())->toBeTrue()
        ->and(CompensationModel::SalaryOnly->requiresSalary())->toBeTrue()
        ->and(CompensationModel::CommissionOnly->requiresSalary())->toBeFalse();
});
