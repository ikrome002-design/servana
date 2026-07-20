<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Actions\ActivateScheduledCompensationPlan;
use App\Domain\Compensation\Actions\ApproveCompensationPlan;
use App\Domain\Compensation\Actions\BuildCompensationPlanImpactPreview;
use App\Domain\Compensation\Actions\CancelCompensationPlan;
use App\Domain\Compensation\Actions\CreateCompensationPlanDraft;
use App\Domain\Compensation\Actions\ExpireCompensationPlan;
use App\Domain\Compensation\Actions\RejectCompensationPlan;
use App\Domain\Compensation\Actions\SubmitCompensationPlan;
use App\Domain\Compensation\Actions\UpdateCompensationPlanDraft;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Exceptions\CompensationApprovalException;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-actions');

/*
 | Phase 20F domain-action proof (Plan §59, §80; Scope §12.2-§12.9). Every lifecycle change runs
 | through its named action and state machine — these tests never mutate a model directly to
 | simulate a transition. Proves history is written, maker/checker and fresh step-up hold, backdated
 | approval is critical, supersede is atomic and adjacent, and NO financial fact is created.
 */

/** A branch + its HR maker/checker actors and a subject personnel. */
function compScenario(): array
{
    $branch = MerchantBranch::factory()->create();
    $staffProfile = StaffProfile::factory()->create([
        'merchant_id' => $branch->merchant_id,
        'primary_branch_id' => $branch->id,
    ]);

    return [
        'branch' => $branch,
        'staff' => $staffProfile,
        'maker' => User::factory()->create(),
        'checker' => User::factory()->create(),
    ];
}

function compRule(array $scn, array $attributes = []): CommissionRule
{
    return CommissionRule::factory()->create(array_merge([
        'merchant_id' => $scn['branch']->merchant_id,
        'branch_id' => $scn['branch']->id,
    ], $attributes));
}

function createDraft(array $scn, array $overrides = []): PersonnelCompensationPlan
{
    return app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $scn['staff'],
        branchId: $scn['branch']->id,
        actor: $scn['maker'],
        model: $overrides['model'] ?? CompensationModel::CommissionOnly,
        effectiveFrom: $overrides['effective_from'] ?? today()->toDateString(),
        changeReason: $overrides['change_reason'] ?? 'Initial plan.',
        commissionRule: array_key_exists('rule', $overrides) ? $overrides['rule'] : compRule($scn),
        salaryAmountMinor: $overrides['salary_amount_minor'] ?? null,
        salaryCurrency: $overrides['salary_currency'] ?? null,
        salaryPeriod: $overrides['salary_period'] ?? null,
        effectiveTo: $overrides['effective_to'] ?? null,
    );
}

/** Drive a plan all the way to pending_approval through the real actions. */
function submittedPlan(array $scn, array $overrides = []): PersonnelCompensationPlan
{
    $plan = createDraft($scn, $overrides);

    return app(SubmitCompensationPlan::class)->handle($plan, $scn['maker'], 'Submitting for approval.');
}

function approve(array $scn, PersonnelCompensationPlan $plan, ?object $preview = null): PersonnelCompensationPlan
{
    return app(ApproveCompensationPlan::class)->handle(
        $plan,
        $scn['checker'],
        'Approved by HR.',
        hasFreshStepUp: true,
        impactPreview: $preview,
    );
}

// ---- create draft ---------------------------------------------------------------

it('creates a draft plan with server-owned scope and status', function (): void {
    $scn = compScenario();

    $plan = createDraft($scn);

    expect($plan->status)->toBe(CompensationPlanStatus::Draft)
        ->and($plan->merchant_id)->toBe($scn['branch']->merchant_id)
        ->and($plan->branch_id)->toBe($scn['branch']->id)
        ->and($plan->staff_profile_id)->toBe($scn['staff']->id)
        ->and($plan->created_by)->toBe($scn['maker']->id)
        ->and($plan->is_backdated)->toBeFalse()   // computed at SUBMISSION, never at create
        ->and($plan->approved_by)->toBeNull()
        ->and($plan->submitted_by)->toBeNull();
});

it('writes a created history row and a warning audit event on draft creation', function (): void {
    $scn = compScenario();

    $plan = createDraft($scn);

    $history = CompensationPlanHistory::query()->where('compensation_plan_id', $plan->id)->sole();

    expect($history->event)->toBe(CompensationPlanHistoryEvent::Created)
        ->and($history->from_status)->toBeNull() // `created` is the only event with no prior status
        ->and($history->to_status)->toBe(CompensationPlanStatus::Draft)
        ->and($history->actor_user_id)->toBe($scn['maker']->id)
        ->and(AuditEvent::CompensationPlanCreated->severity())->toBe(AuditSeverity::Warning)
        ->and(AuditLog::query()->where('action', 'compensation.plan.created')->exists())->toBeTrue();
});

it('rejects a draft for personnel in another branch', function (): void {
    $scn = compScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['branch']->merchant_id]);

    app(CreateCompensationPlanDraft::class)->handle(
        staffProfile: $scn['staff'], // belongs to $scn['branch'], not $otherBranch
        branchId: $otherBranch->id,
        actor: $scn['maker'],
        model: CompensationModel::SalaryOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Cross-branch attempt.',
        salaryAmountMinor: 5000000,
        salaryCurrency: 'KES',
        salaryPeriod: SalaryPeriod::Monthly,
    );
})->throws(CompensationScopeException::class);

it('rejects a draft referencing a commission rule from another merchant', function (): void {
    $scn = compScenario();
    $foreignRule = CommissionRule::factory()->create(); // its own merchant + branch

    createDraft($scn, ['rule' => $foreignRule]);
})->throws(CompensationScopeException::class);

// ---- model shape at the ACTION layer -------------------------------------------

it('rejects a salary_only draft that references a commission rule', function (): void {
    // Plan §80 named invariant, enforced at the action as well as the database.
    $scn = compScenario();

    createDraft($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => compRule($scn),
    ]);
})->throws(CompensationValidationException::class);

it('rejects a commission_only draft with no commission rule', function (): void {
    $scn = compScenario();

    createDraft($scn, ['model' => CompensationModel::CommissionOnly, 'rule' => null]);
})->throws(CompensationValidationException::class);

it('rejects a commission_only draft carrying salary terms', function (): void {
    $scn = compScenario();

    createDraft($scn, [
        'model' => CompensationModel::CommissionOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
    ]);
})->throws(CompensationValidationException::class);

it('rejects a salary_plus_commission draft missing its salary', function (): void {
    $scn = compScenario();

    createDraft($scn, ['model' => CompensationModel::SalaryPlusCommission]);
})->throws(CompensationValidationException::class);

it('rejects a zero salary amount', function (): void {
    $scn = compScenario();

    createDraft($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 0,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => null,
    ]);
})->throws(CompensationValidationException::class);

it('accepts each of the three compensation models through the action', function (): void {
    $scn = compScenario();

    $commissionOnly = createDraft($scn);
    $salaryOnly = createDraft($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => null,
    ]);
    $both = createDraft($scn, [
        'model' => CompensationModel::SalaryPlusCommission,
        'salary_amount_minor' => 3000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
    ]);

    expect($commissionOnly->commission_rule_id)->not->toBeNull()
        ->and($salaryOnly->commission_rule_id)->toBeNull()
        ->and($both->commission_rule_id)->not->toBeNull()
        ->and($both->salary_amount_minor)->toBe(3000000);
});

// ---- update draft ---------------------------------------------------------------

it('updates a draft in place and records the masked diff', function (): void {
    $scn = compScenario();
    $plan = createDraft($scn);

    $updated = app(UpdateCompensationPlanDraft::class)->handle(
        plan: $plan,
        actor: $scn['maker'],
        model: CompensationModel::SalaryOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Switched to salary only.',
        salaryAmountMinor: 4500000,
        salaryCurrency: 'KES',
        salaryPeriod: SalaryPeriod::Monthly,
    );

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->where('event', CompensationPlanHistoryEvent::UpdatedDraft)
        ->sole();

    expect($updated->compensation_model)->toBe(CompensationModel::SalaryOnly)
        ->and($updated->commission_rule_id)->toBeNull()
        ->and($updated->salary_amount_minor)->toBe(4500000)
        ->and($history->changed_fields['before']['compensation_model'])->toBe('commission_only')
        ->and($history->changed_fields['after']['compensation_model'])->toBe('salary_only');
});

it('refuses to update a non-draft plan', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    app(UpdateCompensationPlanDraft::class)->handle(
        plan: $plan,
        actor: $scn['maker'],
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Should not be allowed.',
        commissionRule: $plan->commissionRule,
    );
})->throws(CompensationStateException::class);

it('refuses to update an active plan (supersede, never edit)', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    expect($plan->status)->toBe(CompensationPlanStatus::Active);

    app(UpdateCompensationPlanDraft::class)->handle(
        plan: $plan,
        actor: $scn['maker'],
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Should not be allowed.',
        commissionRule: $plan->commissionRule,
    );
})->throws(CompensationStateException::class);

// ---- submit ---------------------------------------------------------------------

it('submits a draft, freezes terms, and records the submitter', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->where('event', CompensationPlanHistoryEvent::Submitted)
        ->sole();

    expect($plan->status)->toBe(CompensationPlanStatus::PendingApproval)
        ->and($plan->submitted_by)->toBe($scn['maker']->id)
        ->and($plan->submitted_at)->not->toBeNull()
        // Submission never approves and never activates.
        ->and($plan->approved_by)->toBeNull()
        ->and($history->from_status)->toBe(CompensationPlanStatus::Draft)
        ->and($history->to_status)->toBe(CompensationPlanStatus::PendingApproval);
});

it('submits the draft commission rule with its plan', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    expect($plan->commissionRule->refresh()->status)->toBe(CommissionRuleStatus::PendingApproval);
});

it('computes is_backdated at submission from the Africa/Nairobi business date', function (): void {
    $scn = compScenario();

    $backdated = submittedPlan($scn, ['effective_from' => today()->subDays(30)->toDateString()]);

    expect($backdated->is_backdated)->toBeTrue();
});

it('does not mark a plan effective today as backdated', function (): void {
    $scn = compScenario();

    expect(submittedPlan($scn)->is_backdated)->toBeFalse();
});

it('does not supersede the incumbent at submission time', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn));

    submittedPlan($scn, ['effective_from' => today()->addDays(10)->toDateString()]);

    expect($incumbent->refresh()->status)->toBe(CompensationPlanStatus::Active);
});

// ---- approve --------------------------------------------------------------------

it('approves a current-dated plan straight to active', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->where('event', CompensationPlanHistoryEvent::Approved)
        ->sole();

    expect($plan->status)->toBe(CompensationPlanStatus::Active)
        ->and($plan->approved_by)->toBe($scn['checker']->id)
        ->and($plan->approved_at)->not->toBeNull()
        ->and($history->to_status)->toBe(CompensationPlanStatus::Active)
        ->and(AuditEvent::CompensationPlanApproved->severity())->toBe(AuditSeverity::High);
});

it('approves a future-dated plan to scheduled, not active', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn, ['effective_from' => today()->addDays(14)->toDateString()]));

    expect($plan->status)->toBe(CompensationPlanStatus::Scheduled);
});

it('approves the pending commission rule with its plan', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    $rule = $plan->commissionRule->refresh();

    expect($rule->status)->toBe(CommissionRuleStatus::Active)
        ->and($rule->approved_by)->toBe($scn['checker']->id);
});

// ---- maker/checker --------------------------------------------------------------

it('refuses to let the submitter approve their own plan', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    app(ApproveCompensationPlan::class)->handle(
        $plan,
        $scn['maker'], // the very user who submitted it
        'Trying to self-approve.',
        hasFreshStepUp: true,
    );
})->throws(CompensationApprovalException::class);

it('lets a different authorized approver approve, and records both actors', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    expect($plan->submitted_by)->toBe($scn['maker']->id)
        ->and($plan->approved_by)->toBe($scn['checker']->id)
        ->and($plan->approved_by)->not->toBe($plan->submitted_by);
});

it('refuses to approve without a fresh step-up', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    app(ApproveCompensationPlan::class)->handle(
        $plan,
        $scn['checker'],
        'No step-up.',
        hasFreshStepUp: false,
    );
})->throws(CompensationApprovalException::class);

it('renders maker/checker and step-up failures as 403 with typed codes', function (): void {
    expect(CompensationApprovalException::makerChecker()->render(request())->getStatusCode())->toBe(403)
        ->and(CompensationApprovalException::makerChecker()->errorCode())->toBe('maker_checker_violation')
        ->and(CompensationApprovalException::freshStepUpRequired()->errorCode())->toBe('approval_requires_fresh_step_up');
});

// ---- backdated approval (F8) ----------------------------------------------------

it('refuses to approve a backdated plan without an impact preview', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn, ['effective_from' => today()->subDays(30)->toDateString()]);

    expect($plan->is_backdated)->toBeTrue();

    approve($scn, $plan); // no preview → fails closed
})->throws(CompensationValidationException::class);

it('refuses to approve without a reason', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn);

    app(ApproveCompensationPlan::class)->handle($plan, $scn['checker'], '   ', hasFreshStepUp: true);
})->throws(CompensationValidationException::class);

it('approves a backdated plan with a preview and emits the CRITICAL audit event', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn, ['effective_from' => today()->subDays(30)->toDateString()]);

    $preview = app(BuildCompensationPlanImpactPreview::class)->handle($plan);
    $approved = approve($scn, $plan, $preview);

    $critical = AuditLog::query()->where('action', 'compensation.plan.backdated_change_approved')->sole();

    expect($approved->status)->toBe(CompensationPlanStatus::Active)
        ->and(AuditEvent::CompensationPlanBackdatedChangeApproved->severity())->toBe(AuditSeverity::Critical)
        ->and($critical->severity)->toBe(AuditSeverity::Critical)
        // The ordinary approval event is still recorded alongside it.
        ->and(AuditLog::query()->where('action', 'compensation.plan.approved')->exists())->toBeTrue();
});

it('does not emit the critical backdated event for an ordinary approval', function (): void {
    $scn = compScenario();
    approve($scn, submittedPlan($scn));

    expect(AuditLog::query()->where('action', 'compensation.plan.backdated_change_approved')->exists())->toBeFalse();
});

it('records the impact preview in the approval history and audit context', function (): void {
    $scn = compScenario();
    $plan = submittedPlan($scn, ['effective_from' => today()->subDays(10)->toDateString()]);

    $preview = app(BuildCompensationPlanImpactPreview::class)->handle($plan);
    approve($scn, $plan, $preview);

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->where('event', CompensationPlanHistoryEvent::Approved)
        ->sole();

    expect($history->changed_fields['is_backdated'])->toBeTrue()
        ->and($history->changed_fields['plan_ulid'])->toBe($plan->ulid)
        ->and($history->was_backdated)->toBeTrue();
});

// ---- supersede (a consequence, not a permission) ---------------------------------

it('supersedes the incumbent when a successor is approved active', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn));

    $successorFrom = today()->addDays(10);
    $successor = submittedPlan($scn, ['effective_from' => $successorFrom->toDateString()]);

    // Approve the successor as of its own start date so it becomes active over the incumbent.
    $this->travelTo($successorFrom->copy()->addDay());
    $successor = approve($scn, $successor);

    $incumbent->refresh();

    expect($successor->status)->toBe(CompensationPlanStatus::Active)
        ->and($incumbent->status)->toBe(CompensationPlanStatus::Superseded)
        // Closed AT the successor's effective_from — adjacent, never overlapping.
        ->and($incumbent->effective_to->toDateString())->toBe($successorFrom->toDateString())
        ->and($successor->supersedes_plan_id)->toBe($incumbent->id);
});

it('writes a superseded history row and audit event on the incumbent', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn));

    $successorFrom = today()->addDays(10);
    $successor = submittedPlan($scn, ['effective_from' => $successorFrom->toDateString()]);
    $this->travelTo($successorFrom->copy()->addDay());
    approve($scn, $successor);

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $incumbent->id)
        ->where('event', CompensationPlanHistoryEvent::Superseded)
        ->sole();

    expect($history->from_status)->toBe(CompensationPlanStatus::Active)
        ->and($history->to_status)->toBe(CompensationPlanStatus::Superseded)
        ->and(AuditLog::query()->where('action', 'compensation.plan.superseded')->exists())->toBeTrue()
        ->and(AuditEvent::CompensationPlanSuperseded->severity())->toBe(AuditSeverity::High);
});

it('never rewrites the superseded plan monetary terms', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => null,
    ]));

    $successorFrom = today()->addDays(10);
    $successor = submittedPlan($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 9000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => null,
        'effective_from' => $successorFrom->toDateString(),
    ]);

    $this->travelTo($successorFrom->copy()->addDay());
    approve($scn, $successor);

    expect($incumbent->refresh()->salary_amount_minor)->toBe(5000000); // byte-identical
});

it('ends the incumbent commission rule without deleting it', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn));
    $oldRule = $incumbent->commissionRule;

    $successorFrom = today()->addDays(10);
    $successor = submittedPlan($scn, ['effective_from' => $successorFrom->toDateString()]);
    $this->travelTo($successorFrom->copy()->addDay());
    approve($scn, $successor);

    $oldRule->refresh();

    expect($oldRule->exists)->toBeTrue()
        ->and($oldRule->status)->toBe(CommissionRuleStatus::Superseded)
        ->and($oldRule->effective_to->toDateString())->toBe($successorFrom->toDateString())
        ->and(AuditLog::query()->where('action', 'commission_rule.ended')->exists())->toBeTrue();
});

// ---- activation boundary (the Increment 3 correction) ---------------------------

it('activates a scheduled plan at its boundary and writes an activated history row', function (): void {
    $scn = compScenario();
    $from = today()->addDays(7);
    $plan = approve($scn, submittedPlan($scn, ['effective_from' => $from->toDateString()]));

    expect($plan->status)->toBe(CompensationPlanStatus::Scheduled);

    $this->travelTo($from->copy()->addDay());
    $activated = app(ActivateScheduledCompensationPlan::class)->handle($plan, $scn['checker']);

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->where('event', CompensationPlanHistoryEvent::Activated)
        ->sole();

    expect($activated->status)->toBe(CompensationPlanStatus::Active)
        ->and($history->from_status)->toBe(CompensationPlanStatus::Scheduled)
        ->and($history->to_status)->toBe(CompensationPlanStatus::Active)
        ->and(AuditLog::query()->where('action', 'compensation.plan.activated')->exists())->toBeTrue()
        ->and(AuditEvent::CompensationPlanActivated->severity())->toBe(AuditSeverity::Info);
});

it('refuses to activate a scheduled plan before its boundary', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn, ['effective_from' => today()->addDays(7)->toDateString()]));

    app(ActivateScheduledCompensationPlan::class)->handle($plan, $scn['checker']);
})->throws(CompensationStateException::class);

it('refuses to activate a draft plan', function (): void {
    $scn = compScenario();

    app(ActivateScheduledCompensationPlan::class)->handle(createDraft($scn), $scn['checker']);
})->throws(CompensationStateException::class);

it('does not touch monetary terms when activating', function (): void {
    $scn = compScenario();
    $from = today()->addDays(7);
    $plan = approve($scn, submittedPlan($scn, [
        'model' => CompensationModel::SalaryOnly,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => SalaryPeriod::Monthly,
        'rule' => null,
        'effective_from' => $from->toDateString(),
    ]));

    $this->travelTo($from->copy()->addDay());
    $activated = app(ActivateScheduledCompensationPlan::class)->handle($plan, $scn['checker']);

    expect($activated->salary_amount_minor)->toBe(5000000)
        ->and($activated->effective_from->toDateString())->toBe($from->toDateString());
});

// ---- reject ---------------------------------------------------------------------

it('rejects a pending plan and leaves the incumbent untouched', function (): void {
    $scn = compScenario();
    $incumbent = approve($scn, submittedPlan($scn));

    $candidate = submittedPlan($scn, ['effective_from' => today()->addDays(10)->toDateString()]);
    $rejected = app(RejectCompensationPlan::class)->handle($candidate, $scn['checker'], 'Rate too high.');

    $history = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $rejected->id)
        ->where('event', CompensationPlanHistoryEvent::Rejected)
        ->sole();

    expect($rejected->status)->toBe(CompensationPlanStatus::Rejected)
        ->and($rejected->rejected_by)->toBe($scn['checker']->id)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($history->to_status)->toBe(CompensationPlanStatus::Rejected)
        // The personnel keeps earning exactly as before.
        ->and($incumbent->refresh()->status)->toBe(CompensationPlanStatus::Active);
});

it('refuses to reject a draft plan', function (): void {
    $scn = compScenario();

    app(RejectCompensationPlan::class)->handle(createDraft($scn), $scn['checker'], 'Nope.');
})->throws(CompensationStateException::class);

it('requires a reason to reject', function (): void {
    $scn = compScenario();

    app(RejectCompensationPlan::class)->handle(submittedPlan($scn), $scn['checker'], '  ');
})->throws(CompensationStateException::class);

// ---- cancel ---------------------------------------------------------------------

it('cancels a draft plan', function (): void {
    $scn = compScenario();
    $cancelled = app(CancelCompensationPlan::class)->handle(createDraft($scn), $scn['maker'], 'Not needed.');

    expect($cancelled->status)->toBe(CompensationPlanStatus::Cancelled)
        ->and(CompensationPlanHistory::query()
            ->where('compensation_plan_id', $cancelled->id)
            ->where('event', CompensationPlanHistoryEvent::Cancelled)
            ->exists())->toBeTrue();
});

it('cancels a scheduled plan before it takes effect', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn, ['effective_from' => today()->addDays(14)->toDateString()]));

    $cancelled = app(CancelCompensationPlan::class)->handle($plan, $scn['maker'], 'Superseded by a new offer.');

    expect($cancelled->status)->toBe(CompensationPlanStatus::Cancelled);
});

it('refuses to cancel an active plan (it must be superseded)', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    app(CancelCompensationPlan::class)->handle($plan, $scn['maker'], 'Should not be allowed.');
})->throws(CompensationStateException::class);

it('requires a reason to cancel', function (): void {
    $scn = compScenario();

    app(CancelCompensationPlan::class)->handle(createDraft($scn), $scn['maker'], '');
})->throws(CompensationStateException::class);

// ---- expire ---------------------------------------------------------------------

it('expires an active plan once its effective_to is reached', function (): void {
    $scn = compScenario();
    $to = today()->addDays(30);
    $plan = approve($scn, submittedPlan($scn, ['effective_to' => $to->toDateString()]));

    $this->travelTo($to->copy()->addDay());
    $expired = app(ExpireCompensationPlan::class)->handle($plan, $scn['checker']);

    expect($expired->status)->toBe(CompensationPlanStatus::Expired)
        ->and(CompensationPlanHistory::query()
            ->where('compensation_plan_id', $plan->id)
            ->where('event', CompensationPlanHistoryEvent::Expired)
            ->exists())->toBeTrue()
        ->and(AuditEvent::CompensationPlanExpired->severity())->toBe(AuditSeverity::Info);
});

it('refuses to expire a plan still inside its window', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn, ['effective_to' => today()->addDays(30)->toDateString()]));

    app(ExpireCompensationPlan::class)->handle($plan, $scn['checker']);
})->throws(CompensationStateException::class);

it('refuses to expire an open-ended plan', function (): void {
    $scn = compScenario();
    $plan = approve($scn, submittedPlan($scn));

    app(ExpireCompensationPlan::class)->handle($plan, $scn['checker']);
})->throws(CompensationStateException::class);

// ---- history is append-only and complete ----------------------------------------

it('records one history row per lifecycle moment', function (): void {
    $scn = compScenario();
    $plan = createDraft($scn);
    app(UpdateCompensationPlanDraft::class)->handle(
        plan: $plan,
        actor: $scn['maker'],
        model: CompensationModel::CommissionOnly,
        effectiveFrom: today()->toDateString(),
        changeReason: 'Tweaked.',
        commissionRule: $plan->commissionRule,
    );
    $plan = app(SubmitCompensationPlan::class)->handle($plan, $scn['maker'], 'Submitting.');
    approve($scn, $plan);

    $events = CompensationPlanHistory::query()
        ->where('compensation_plan_id', $plan->id)
        ->orderBy('id')
        ->pluck('event')
        ->map(fn (CompensationPlanHistoryEvent $e): string => $e->value)
        ->all();

    expect($events)->toBe(['created', 'updated_draft', 'submitted', 'approved']);
});

// ---- no financial runtime -------------------------------------------------------

it('creates no ledger, payout, or earnings runtime while driving a full lifecycle', function (): void {
    $scn = compScenario();
    approve($scn, submittedPlan($scn));

    // Phase 20H payout/earnings tables still do not exist.
    foreach (['personnel_payout_runs', 'personnel_payout_items', 'earnings_statements'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must not exist before Phase 20H");
    }

    // The Phase 20G ledger tables now exist, but a Phase 20F plan lifecycle writes NO rows to them.
    foreach (['salary_ledger', 'commission_ledger', 'compensation_adjustments'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "Phase 20F must write no {$table} rows");
    }

    // The Phase 18B hand-off seam exists but Phase 20F never writes to it — 20G consumes it.
    expect(Schema::hasTable('commission_handoff_events'))->toBeTrue()
        ->and(CommissionHandoffEvent::query()->count())->toBe(0);
});
