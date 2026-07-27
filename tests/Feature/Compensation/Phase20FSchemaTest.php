<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-schema');

/*
 | Phase 20F schema + database-guard proof (Plan §59, §80; Scope §12.2-§12.9, §18.3;
 | ADR-002/004/005). Runs on PostgreSQL 16. Proves the three CONFIGURATION tables exist with
 | their canonical constraints, tenant/branch ownership registration, F1 model shape, F3
 | one-active-plan overlap exclusion, F4 integer money bounds, F7 immutability, the append-only
 | history guard — and that Phase 20F introduces NO earned/ledger/payout runtime.
 */

/** @return list<string> constraint names of the given type on a table */
function phase20fConstraints(string $table, string $type): array
{
    return array_map(
        static fn (object $r): string => $r->conname,
        DB::select(
            'select conname from pg_constraint where conrelid = ?::regclass and contype = ?',
            [$table, $type],
        ),
    );
}

/** @return list<string> trigger names on a table */
function phase20fTriggers(string $table): array
{
    return array_map(
        static fn (object $r): string => $r->tgname,
        DB::select(
            'select tgname from pg_trigger where tgrelid = ?::regclass and not tgisinternal',
            [$table],
        ),
    );
}

/** @return list<string> index names on a table */
function phase20fIndexes(string $table): array
{
    return array_map(
        static fn (object $r): string => $r->indexname,
        DB::select('select indexname from pg_indexes where tablename = ?', [$table]),
    );
}

// ---- tables, columns, ownership ------------------------------------------------

it('creates the three Phase 20F tables', function (): void {
    expect(Schema::hasTable('commission_rules'))->toBeTrue()
        ->and(Schema::hasTable('personnel_compensation_plans'))->toBeTrue()
        ->and(Schema::hasTable('compensation_plan_history'))->toBeTrue();
});

it('creates every canonical commission_rules column', function (): void {
    expect(Schema::hasColumns('commission_rules', [
        'id', 'ulid', 'merchant_id', 'branch_id', 'calculation_type', 'percentage_basis_points',
        'fixed_amount_minor', 'currency', 'calculation_basis', 'applies_to', 'service_category_id',
        'applies_to_preferred_personnel_fee', 'effective_from', 'effective_to', 'status', 'notes',
        'change_reason', 'created_by', 'approved_by', 'approved_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates every canonical personnel_compensation_plans column', function (): void {
    expect(Schema::hasColumns('personnel_compensation_plans', [
        'id', 'ulid', 'merchant_id', 'branch_id', 'staff_profile_id', 'compensation_model',
        'salary_amount_minor', 'salary_currency', 'salary_period', 'salary_payout_day',
        'commission_rule_id', 'effective_from', 'effective_to', 'status', 'is_backdated',
        'supersedes_plan_id', 'notes', 'change_reason', 'created_by', 'submitted_by',
        'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates every canonical compensation_plan_history column and no updated_at', function (): void {
    expect(Schema::hasColumns('compensation_plan_history', [
        'id', 'ulid', 'merchant_id', 'branch_id', 'compensation_plan_id', 'staff_profile_id',
        'event', 'from_status', 'to_status', 'changed_fields', 'was_backdated', 'change_reason',
        'actor_user_id', 'effective_from', 'created_at',
    ]))->toBeTrue()
        // Append-only: the row has no mutable column at all.
        ->and(Schema::hasColumn('compensation_plan_history', 'updated_at'))->toBeFalse();
});

it('registers all three tables as branch-owned with composite consistency', function (): void {
    foreach (['commission_rules', 'personnel_compensation_plans', 'compensation_plan_history'] as $table) {
        expect(TenantOwnership::BRANCH_OWNED)->toContain($table)
            ->and(TenantOwnership::COMPOSITE_CONSISTENCY)->toHaveKey($table)
            ->and(TenantOwnership::COMPOSITE_CONSISTENCY[$table]['parent'])->toBe('merchant_branches');
    }

    expect(TenantOwnership::MODELS[CommissionRule::class])->toBe('branch')
        ->and(TenantOwnership::MODELS[PersonnelCompensationPlan::class])->toBe('branch')
        ->and(TenantOwnership::MODELS[CompensationPlanHistory::class])->toBe('branch');
});

it('builds valid rows from every Phase 20F factory and state', function (): void {
    expect(CommissionRule::factory()->create())->toBeInstanceOf(CommissionRule::class)
        ->and(CommissionRule::factory()->fixedAmount()->create()->percentage_basis_points)->toBeNull()
        ->and(CommissionRule::factory()->serviceCategory()->create()->service_category_id)->not->toBeNull()
        ->and(CommissionRule::factory()->includingPreferredPersonnelFee()->create()->applies_to_preferred_personnel_fee)->toBeTrue()
        ->and(CommissionRule::factory()->active()->create()->approved_by)->not->toBeNull()
        ->and(PersonnelCompensationPlan::factory()->create())->toBeInstanceOf(PersonnelCompensationPlan::class)
        ->and(PersonnelCompensationPlan::factory()->salaryOnly()->create()->commission_rule_id)->toBeNull()
        ->and(PersonnelCompensationPlan::factory()->salaryPlusCommission()->create()->commission_rule_id)->not->toBeNull()
        ->and(PersonnelCompensationPlan::factory()->active()->create()->status)->toBe(CompensationPlanStatus::Active)
        ->and(PersonnelCompensationPlan::factory()->scheduled()->create()->status)->toBe(CompensationPlanStatus::Scheduled)
        ->and(PersonnelCompensationPlan::factory()->pendingApproval()->create()->submitted_by)->not->toBeNull()
        ->and(PersonnelCompensationPlan::factory()->backdated()->create()->is_backdated)->toBeTrue()
        ->and(CompensationPlanHistory::factory()->create())->toBeInstanceOf(CompensationPlanHistory::class)
        ->and(CompensationPlanHistory::factory()->approved()->create()->from_status)->toBe(CompensationPlanStatus::PendingApproval);
});

// ---- constraints, FKs, indexes, triggers exist ----------------------------------

it('creates the required commission_rules CHECK constraints', function (): void {
    expect(phase20fConstraints('commission_rules', 'c'))->toContain(
        'commission_rules_calculation_type_check',
        'commission_rules_calculation_basis_check',
        'commission_rules_applies_to_check',
        'commission_rules_status_check',
        'commission_rules_basis_points_range_check',
        'commission_rules_fixed_amount_nonneg_check',
        'commission_rules_currency_check',
        'commission_rules_value_shape_check',
        'commission_rules_applies_to_category_check',
        'commission_rules_effective_range_check',
        'commission_rules_change_reason_check',
        'commission_rules_approval_pair_check',
    );
});

it('creates the required personnel_compensation_plans CHECK constraints', function (): void {
    expect(phase20fConstraints('personnel_compensation_plans', 'c'))->toContain(
        'personnel_compensation_plans_compensation_model_check',
        'personnel_compensation_plans_salary_period_check',
        'personnel_compensation_plans_status_check',
        'personnel_compensation_plans_salary_amount_positive_check',
        'personnel_compensation_plans_salary_currency_check',
        'personnel_compensation_plans_salary_payout_day_check',
        'personnel_compensation_plans_model_shape_check',
        'personnel_compensation_plans_effective_range_check',
        'personnel_compensation_plans_change_reason_check',
        'personnel_compensation_plans_maker_checker_check',
        'personnel_compensation_plans_approval_status_check',
    );
});

it('creates the required compensation_plan_history CHECK constraints', function (): void {
    expect(phase20fConstraints('compensation_plan_history', 'c'))->toContain(
        'compensation_plan_history_event_check',
        'compensation_plan_history_from_status_check',
        'compensation_plan_history_to_status_check',
        'compensation_plan_history_event_from_status_check',
        'compensation_plan_history_change_reason_check',
    );
});

it('creates the composite tenant-consistency foreign keys', function (): void {
    expect(phase20fConstraints('commission_rules', 'f'))
        ->toContain('commission_rules_branch_merchant_foreign')
        ->and(phase20fConstraints('personnel_compensation_plans', 'f'))->toContain(
            'personnel_compensation_plans_branch_merchant_foreign',
            'personnel_compensation_plans_staff_profile_merchant_foreign',
            'personnel_compensation_plans_commission_rule_merchant_foreign',
        )
        ->and(phase20fConstraints('compensation_plan_history', 'f'))->toContain(
            'compensation_plan_history_branch_merchant_foreign',
            'compensation_plan_history_plan_merchant_foreign',
            'compensation_plan_history_staff_profile_merchant_foreign',
        );
});

it('creates the required lookup indexes and ULID uniqueness', function (): void {
    expect(phase20fIndexes('commission_rules'))->toContain(
        'commission_rules_ulid_unique',
        'commission_rules_resolution_index',
        'commission_rules_id_merchant_id_unique',
    )
        ->and(phase20fIndexes('personnel_compensation_plans'))->toContain(
            'personnel_compensation_plans_ulid_unique',
            'personnel_compensation_plans_resolution_index',
            'personnel_compensation_plans_commission_rule_index',
            'personnel_compensation_plans_id_merchant_id_unique',
        )
        ->and(phase20fIndexes('compensation_plan_history'))->toContain(
            'compensation_plan_history_ulid_unique',
            'compensation_plan_history_subject_index',
            'compensation_plan_history_plan_index',
        );
});

it('creates the effective-window exclusion constraint on compensation plans', function (): void {
    expect(phase20fConstraints('personnel_compensation_plans', 'x'))
        ->toContain('personnel_compensation_plans_no_overlap');
});

it('creates the immutability and append-only triggers', function (): void {
    expect(phase20fTriggers('commission_rules'))->toContain('commission_rules_no_term_update')
        ->and(phase20fTriggers('personnel_compensation_plans'))->toContain('personnel_compensation_plans_no_term_update')
        ->and(phase20fTriggers('compensation_plan_history'))->toContain(
            'compensation_plan_history_no_update',
            'compensation_plan_history_no_delete',
        );
});

it('enforces ULID uniqueness on each Phase 20F table', function (): void {
    $rule = CommissionRule::factory()->create();

    CommissionRule::factory()->create(['ulid' => $rule->ulid]);
})->throws(QueryException::class);

// ---- F1 model shape (DB-authoritative) -----------------------------------------

it('rejects a salary_only plan that carries a commission rule', function (): void {
    // Plan §80 named invariant: salary-only has NO commission rule (Scope §12.5).
    $rule = CommissionRule::factory()->create();

    PersonnelCompensationPlan::factory()->salaryOnly()->create([
        'merchant_id' => $rule->merchant_id,
        'branch_id' => $rule->branch_id,
        'commission_rule_id' => $rule->id,
    ]);
})->throws(QueryException::class);

it('rejects a commission_only plan with no commission rule', function (): void {
    PersonnelCompensationPlan::factory()->commissionOnly()->create(['commission_rule_id' => null]);
})->throws(QueryException::class);

it('rejects a commission_only plan that carries salary terms', function (): void {
    PersonnelCompensationPlan::factory()->commissionOnly()->create([
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => 'monthly',
    ]);
})->throws(QueryException::class);

it('rejects a salary_only plan with no salary amount', function (): void {
    PersonnelCompensationPlan::factory()->salaryOnly()->create(['salary_amount_minor' => null]);
})->throws(QueryException::class);

it('rejects a salary_plus_commission plan missing its salary', function (): void {
    PersonnelCompensationPlan::factory()->salaryPlusCommission()->create([
        'salary_amount_minor' => null,
        'salary_currency' => null,
        'salary_period' => null,
    ]);
})->throws(QueryException::class);

it('rejects a salary_plus_commission plan missing its commission rule', function (): void {
    PersonnelCompensationPlan::factory()->salaryPlusCommission()->create(['commission_rule_id' => null]);
})->throws(QueryException::class);

it('accepts each of the three compensation models in its valid shape', function (): void {
    expect(PersonnelCompensationPlan::factory()->commissionOnly()->create()->exists)->toBeTrue()
        ->and(PersonnelCompensationPlan::factory()->salaryOnly()->create()->exists)->toBeTrue()
        ->and(PersonnelCompensationPlan::factory()->salaryPlusCommission()->create()->exists)->toBeTrue();
});

// ---- F4 money bounds -----------------------------------------------------------

it('rejects a zero or negative salary amount', function (): void {
    PersonnelCompensationPlan::factory()->salaryOnly()->create(['salary_amount_minor' => 0]);
})->throws(QueryException::class);

it('rejects a lowercase salary currency', function (): void {
    PersonnelCompensationPlan::factory()->salaryOnly()->create(['salary_currency' => 'kes']);
})->throws(QueryException::class);

it('rejects a salary payout day outside 1..31', function (): void {
    PersonnelCompensationPlan::factory()->salaryOnly()->create(['salary_payout_day' => 32]);
})->throws(QueryException::class);

it('rejects commission basis points above 10000', function (): void {
    CommissionRule::factory()->percentage()->create(['percentage_basis_points' => 10001]);
})->throws(QueryException::class);

it('rejects negative commission basis points', function (): void {
    CommissionRule::factory()->percentage()->create(['percentage_basis_points' => -1]);
})->throws(QueryException::class);

it('accepts the structural basis-points bounds 0 and 10000', function (): void {
    expect(CommissionRule::factory()->percentage(0)->create()->percentage_basis_points)->toBe(0)
        ->and(CommissionRule::factory()->percentage(10000)->create()->percentage_basis_points)->toBe(10000);
});

it('rejects a negative fixed commission amount', function (): void {
    CommissionRule::factory()->fixedAmount()->create(['fixed_amount_minor' => -1]);
})->throws(QueryException::class);

it('rejects a percentage rule that also carries a fixed amount', function (): void {
    CommissionRule::factory()->percentage()->create(['fixed_amount_minor' => 50000, 'currency' => 'KES']);
})->throws(QueryException::class);

it('rejects a fixed rule that also carries basis points', function (): void {
    CommissionRule::factory()->fixedAmount()->create(['percentage_basis_points' => 1000]);
})->throws(QueryException::class);

it('rejects a fixed rule with no currency', function (): void {
    CommissionRule::factory()->fixedAmount()->create(['currency' => null]);
})->throws(QueryException::class);

// ---- applicability, effective window, reason, maker/checker ---------------------

it('rejects a service_category rule with no category', function (): void {
    CommissionRule::factory()->serviceCategory()->create(['service_category_id' => null]);
})->throws(QueryException::class);

it('rejects an all_services rule that carries a category', function (): void {
    $rule = CommissionRule::factory()->serviceCategory()->create();

    CommissionRule::factory()->allServices()->create([
        'merchant_id' => $rule->merchant_id,
        'branch_id' => $rule->branch_id,
        'service_category_id' => $rule->service_category_id,
    ]);
})->throws(QueryException::class);

it('rejects an effective_to that is not after effective_from', function (): void {
    PersonnelCompensationPlan::factory()->create([
        'effective_from' => businessToday(),
        'effective_to' => businessToday(),
    ]);
})->throws(QueryException::class);

it('rejects a blank change reason on a plan', function (): void {
    PersonnelCompensationPlan::factory()->create(['change_reason' => '   ']);
})->throws(QueryException::class);

it('rejects a blank change reason on a commission rule', function (): void {
    CommissionRule::factory()->create(['change_reason' => '']);
})->throws(QueryException::class);

it('rejects a plan whose approver is its own submitter (maker/checker)', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['approved_by' => $plan->submitted_by]);
})->throws(QueryException::class);

it('rejects an approved-state plan with no recorded approver (backdating fails closed)', function (): void {
    PersonnelCompensationPlan::factory()->backdated()->create([
        'status' => CompensationPlanStatus::Active,
        'approved_by' => null,
        'approved_at' => null,
    ]);
})->throws(QueryException::class);

// ---- F2 tenant/branch consistency ----------------------------------------------

it('rejects a plan whose branch belongs to another merchant', function (): void {
    $plan = PersonnelCompensationPlan::factory()->create();
    $foreign = PersonnelCompensationPlan::factory()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['branch_id' => $foreign->branch_id]);
})->throws(QueryException::class);

it('rejects a plan whose staff profile belongs to another merchant', function (): void {
    $plan = PersonnelCompensationPlan::factory()->create();
    $foreignProfile = StaffProfile::factory()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['staff_profile_id' => $foreignProfile->id]);
})->throws(QueryException::class);

it('rejects a plan whose commission rule belongs to another merchant', function (): void {
    $plan = PersonnelCompensationPlan::factory()->create();
    $foreignRule = CommissionRule::factory()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['commission_rule_id' => $foreignRule->id]);
})->throws(QueryException::class);

// ---- F3 one active plan per personnel per branch (the DB EXCLUDE) --------------

it('rejects two overlapping active plans for the same personnel in the same branch', function (): void {
    $first = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $first->merchant_id,
        'branch_id' => $first->branch_id,
        'staff_profile_id' => $first->staff_profile_id,
        'effective_from' => businessToday()->addDays(5),
        'effective_to' => null,
    ]);
})->throws(QueryException::class);

it('rejects an active plan overlapping a scheduled plan for the same personnel', function (): void {
    $active = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    PersonnelCompensationPlan::factory()->scheduled()->create([
        'merchant_id' => $active->merchant_id,
        'branch_id' => $active->branch_id,
        'staff_profile_id' => $active->staff_profile_id,
        'effective_from' => businessToday()->addDays(10),
        'effective_to' => null,
    ]);
})->throws(QueryException::class);

it('accepts adjacent effective windows for the same personnel (half-open ranges)', function (): void {
    $first = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => businessToday()->addDays(10),
    ]);

    // Starts exactly where the incumbent ends — adjacent, not overlapping.
    $second = PersonnelCompensationPlan::factory()->scheduled()->create([
        'merchant_id' => $first->merchant_id,
        'branch_id' => $first->branch_id,
        'staff_profile_id' => $first->staff_profile_id,
        'effective_from' => businessToday()->addDays(10),
        'effective_to' => null,
    ]);

    expect($second->exists)->toBeTrue();
});

it('allows the same personnel an active plan in each of two different branches', function (): void {
    // The exclusion is keyed on (branch_id, staff_profile_id): scope is PER BRANCH (F2), so the
    // SAME personnel may hold one active plan in each of two branches of the same merchant.
    $first = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $first->merchant_id]);

    $second = PersonnelCompensationPlan::factory()->active()->create([
        'merchant_id' => $first->merchant_id,
        'branch_id' => $otherBranch->id,
        'staff_profile_id' => $first->staff_profile_id, // the SAME personnel
        'effective_from' => businessToday(),
        'effective_to' => null,
    ]);

    expect($second->exists)->toBeTrue()
        ->and($second->staff_profile_id)->toBe($first->staff_profile_id)
        ->and($second->branch_id)->not->toBe($first->branch_id);
});

it('lets no non-effective status block a window', function (string $status): void {
    $incumbent = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    // A draft/pending/terminal row over the SAME window must never block a new active plan.
    $blocker = PersonnelCompensationPlan::factory()->create([
        'merchant_id' => $incumbent->merchant_id,
        'branch_id' => $incumbent->branch_id,
        'staff_profile_id' => $incumbent->staff_profile_id,
        'effective_from' => businessToday(),
        'effective_to' => null,
    ]);

    DB::table('personnel_compensation_plans')->where('id', $blocker->id)->update([
        'status' => $status,
        'submitted_by' => $blocker->created_by,
        'submitted_at' => now(),
    ]);

    expect(DB::table('personnel_compensation_plans')->where('id', $blocker->id)->value('status'))->toBe($status);
})->with(['draft', 'pending_approval', 'rejected', 'cancelled']);

// ---- F7 immutability (supersede, never edit) ------------------------------------

it('blocks a raw UPDATE of an active plan salary amount', function (): void {
    $plan = PersonnelCompensationPlan::factory()->salaryOnly()->active()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['salary_amount_minor' => 9999999]);
})->throws(QueryException::class);

it('blocks a raw UPDATE of an active plan compensation model', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['compensation_model' => 'salary_only']);
})->throws(QueryException::class);

it('blocks a raw UPDATE of an active plan effective_from', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create();

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['effective_from' => businessToday()->subDays(30)]);
})->throws(QueryException::class);

it('blocks a raw UPDATE of an active plan subject', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create();
    $other = StaffProfile::factory()->create([
        'merchant_id' => $plan->merchant_id,
        'primary_branch_id' => $plan->branch_id,
    ]);

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['staff_profile_id' => $other->id]);
})->throws(QueryException::class);

it('allows a draft plan to be edited in place', function (): void {
    $plan = PersonnelCompensationPlan::factory()->salaryOnly()->create();

    $plan->update(['salary_amount_minor' => 7000000]);

    expect($plan->refresh()->salary_amount_minor)->toBe(7000000);
});

it('allows the approved supersede transition to close an open-ended active window', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    // active → superseded closing the window at the successor's effective_from is the ONE
    // permitted mutation of a non-draft row's effective_to.
    DB::table('personnel_compensation_plans')->where('id', $plan->id)->update([
        'status' => 'superseded',
        'effective_to' => businessToday()->addDays(10),
    ]);

    $row = DB::table('personnel_compensation_plans')->where('id', $plan->id)->first();

    expect($row->status)->toBe('superseded')
        ->and($row->effective_to)->not->toBeNull();
});

it('blocks re-opening or rewriting an already closed effective window', function (): void {
    $plan = PersonnelCompensationPlan::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => businessToday()->addDays(10),
    ]);

    DB::table('personnel_compensation_plans')->where('id', $plan->id)
        ->update(['status' => 'superseded', 'effective_to' => businessToday()->addDays(20)]);
})->throws(QueryException::class);

it('blocks a raw UPDATE of an active commission rule rate', function (): void {
    $rule = CommissionRule::factory()->active()->create();

    DB::table('commission_rules')->where('id', $rule->id)
        ->update(['percentage_basis_points' => 5000]);
})->throws(QueryException::class);

it('blocks a raw UPDATE of an active commission rule preferred-fee applicability', function (): void {
    $rule = CommissionRule::factory()->active()->create();

    DB::table('commission_rules')->where('id', $rule->id)
        ->update(['applies_to_preferred_personnel_fee' => true]);
})->throws(QueryException::class);

it('allows a draft commission rule to be edited in place', function (): void {
    $rule = CommissionRule::factory()->create();

    $rule->update(['percentage_basis_points' => 2500]);

    expect($rule->refresh()->percentage_basis_points)->toBe(2500);
});

it('ends an active commission rule without deleting it or changing its terms', function (): void {
    // Scope §12.7 Step 3C: a previously active rule is ENDED, not deleted.
    $rule = CommissionRule::factory()->active()->create([
        'effective_from' => businessToday(), 'effective_to' => null,
    ]);

    DB::table('commission_rules')->where('id', $rule->id)
        ->update(['status' => 'superseded', 'effective_to' => businessToday()->addDays(10)]);

    $ended = CommissionRule::query()->withoutGlobalScopes()->find($rule->id);

    expect($ended)->not->toBeNull()
        ->and($ended->percentage_basis_points)->toBe($rule->percentage_basis_points)
        ->and($ended->calculation_basis)->toBe($rule->calculation_basis);
});

// ---- append-only history --------------------------------------------------------

it('blocks any UPDATE of a compensation history row', function (): void {
    $history = CompensationPlanHistory::factory()->create();

    DB::table('compensation_plan_history')->where('id', $history->id)
        ->update(['change_reason' => 'rewritten']);
})->throws(QueryException::class);

it('blocks any DELETE of a compensation history row', function (): void {
    $history = CompensationPlanHistory::factory()->create();

    DB::table('compensation_plan_history')->where('id', $history->id)->delete();
})->throws(QueryException::class);

it('rejects a created history event that carries a prior status', function (): void {
    CompensationPlanHistory::factory()->create(['from_status' => CompensationPlanStatus::Draft]);
})->throws(QueryException::class);

it('rejects a transition history event with no prior status', function (): void {
    CompensationPlanHistory::factory()->approved()->create(['from_status' => null]);
})->throws(QueryException::class);

// ---- Phase 20F introduces no earned/ledger/payout runtime -----------------------

it('writes no payout or earnings (Phase 20H) runtime rows', function (string $table): void {
    // Phase 20F is CONFIGURATION ONLY. Phase 20H shipped the payout/earnings tables
    // (personnel_payout_runs / personnel_payout_items / earnings_queries); a Phase 20F schema state
    // writes NO rows to them. (salary_ledger / commission_ledger / compensation_adjustments are the
    // Phase 20G suite's concern. Earnings statements are 10F uploaded_files rows, not a bespoke table.)
    expect(DB::table($table)->count())->toBe(0, "Phase 20F must write no {$table} rows");
})->with([
    'personnel_payout_runs',
    'personnel_payout_items',
    'earnings_queries',
]);

it('adds no earned, paid, or payout column to the Phase 20F tables', function (): void {
    $forbidden = ['earned_amount_minor', 'paid_amount_minor', 'payable_minor', 'accrued_amount_minor',
        'payout_id', 'payout_run_id', 'settled_at', 'paid_at', 'wallet_transaction_id'];

    foreach (['commission_rules', 'personnel_compensation_plans', 'compensation_plan_history'] as $table) {
        foreach ($forbidden as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} must not exist in Phase 20F");
        }
    }
});

it('leaves the Phase 18B commission handoff seam untouched', function (): void {
    // The 20G hand-off seam is pre-existing and unmodified: still no rate/earned/payable.
    expect(Schema::hasTable('commission_handoff_events'))->toBeTrue()
        ->and(Schema::hasColumn('commission_handoff_events', 'commission_rule_id'))->toBeFalse()
        ->and(Schema::hasColumn('commission_handoff_events', 'earned_amount_minor'))->toBeFalse();
});
