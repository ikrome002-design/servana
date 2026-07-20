<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CommissionHandoffEvent;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-api');

/*
 | Phase 20F compensation-plan API proof (Plan §59, §80; Scope §12.2-§12.9). Branch-scoped, HR-only.
 | Every mutation runs through the REAL domain action — no test ever writes a status directly.
 | Proves permission + policy denial for every other role, fresh step-up on approve, maker/checker,
 | server-owned-field rejection, tenant/branch isolation, and that NO financial fact is created.
 */

/** An HR maker + an HR checker in one branch, with the subject personnel. */
function planApiScenario(): array
{
    test()->seed(PermissionSeeder::class);

    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    [$maker] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$checker] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $subject] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    return compact('merchant', 'branch', 'maker', 'checker', 'subject');
}

function apiRule(array $scn, array $attributes = []): CommissionRule
{
    return CommissionRule::factory()->create(array_merge([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ], $attributes));
}

/** Create a draft through the REAL endpoint. */
function postPlan(array $scn, array $body = [], ?User $actor = null)
{
    return test()->actingAs($actor ?? $scn['maker'], 'sanctum')
        ->postJson('/api/v1/compensation-plans', array_merge([
            'staff_profile_id' => $scn['subject']->ulid,
            'compensation_model' => 'commission_only',
            'commission_rule_id' => apiRule($scn)->ulid,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Initial compensation plan.',
        ], $body));
}

function submitPlanApi(array $scn, string $ulid, ?User $actor = null)
{
    return test()->actingAs($actor ?? $scn['maker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/submit", ['change_reason' => 'Submitting for approval.']);
}

/** Approve through the REAL endpoint WITH a fresh step-up (never bypassed). */
function approvePlanApi(array $scn, string $ulid, array $body = [], ?User $actor = null)
{
    return test()->statefulMfa(now()->getTimestamp())->actingAs($actor ?? $scn['checker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/approve", array_merge([
            'change_reason' => 'Approved by HR.',
        ], $body));
}

/** Drive create → submit and return the plan ULID. */
function pendingPlanApi(array $scn, array $body = []): string
{
    $ulid = postPlan($scn, $body)->assertCreated()->json('data.id');
    submitPlanApi($scn, $ulid)->assertOk();

    return $ulid;
}

// ---- reads ----------------------------------------------------------------------

it('lets HR list branch compensation plans', function (): void {
    $scn = planApiScenario();
    postPlan($scn)->assertCreated();

    test()->actingAs($scn['maker'], 'sanctum')
        ->getJson('/api/v1/compensation-plans')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'draft');
});

it('lets HR view a compensation plan', function (): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['maker'], 'sanctum')
        ->getJson("/api/v1/compensation-plans/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $ulid)
        ->assertJsonPath('data.compensation_model', 'commission_only');
});

it('lets HR read a plan history', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    test()->actingAs($scn['maker'], 'sanctum')
        ->getJson("/api/v1/compensation-plans/{$ulid}/history")
        ->assertOk()
        ->assertJsonCount(2, 'data') // created + submitted, newest first
        ->assertJsonPath('data.0.event', 'submitted');
});

// ---- create / model shape --------------------------------------------------------

it('creates a draft with server-owned scope and status', function (): void {
    $scn = planApiScenario();

    $response = postPlan($scn)->assertCreated();

    $plan = PersonnelCompensationPlan::query()->where('ulid', $response->json('data.id'))->sole();

    expect($plan->status)->toBe(CompensationPlanStatus::Draft)
        ->and($plan->merchant_id)->toBe($scn['merchant']->id)
        ->and($plan->branch_id)->toBe($scn['branch']->id)
        ->and($plan->created_by)->toBe($scn['maker']->id)
        ->and($plan->is_backdated)->toBeFalse();
});

it('creates a salary_only plan with no commission rule', function (): void {
    $scn = planApiScenario();

    $response = postPlan($scn, [
        'compensation_model' => 'salary_only',
        'commission_rule_id' => null,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => 'monthly',
    ])->assertCreated();

    expect($response->json('data.commission_rule'))->toBeNull()
        ->and($response->json('data.salary_amount_minor'))->toBe(5000000);
});

it('creates a salary_plus_commission plan with both', function (): void {
    $scn = planApiScenario();

    postPlan($scn, [
        'compensation_model' => 'salary_plus_commission',
        'salary_amount_minor' => 3000000,
        'salary_currency' => 'KES',
        'salary_period' => 'monthly',
    ])->assertCreated()
        ->assertJsonPath('data.salary_amount_minor', 3000000);
});

it('rejects a salary_only plan that references a commission rule', function (): void {
    $scn = planApiScenario();

    postPlan($scn, [
        'compensation_model' => 'salary_only',
        'commission_rule_id' => apiRule($scn)->ulid,
        'salary_amount_minor' => 5000000,
        'salary_currency' => 'KES',
        'salary_period' => 'monthly',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['commission_rule_id']]]);
});

it('rejects a commission_only plan with no commission rule', function (): void {
    $scn = planApiScenario();

    postPlan($scn, ['commission_rule_id' => null])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['commission_rule_id']]]);
});

it('rejects a salary_plus_commission plan missing its salary', function (): void {
    $scn = planApiScenario();

    postPlan($scn, ['compensation_model' => 'salary_plus_commission'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['salary_amount_minor']]]);
});

it('rejects a float salary amount', function (): void {
    $scn = planApiScenario();

    postPlan($scn, [
        'compensation_model' => 'salary_only',
        'commission_rule_id' => null,
        'salary_amount_minor' => 5000.25, // money is INTEGER minor units
        'salary_currency' => 'KES',
        'salary_period' => 'monthly',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['salary_amount_minor']]]);
});

it('rejects a plan with no change reason', function (): void {
    $scn = planApiScenario();

    postPlan($scn, ['change_reason' => ''])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['change_reason']]]);
});

it('rejects an effective_to that is not after effective_from', function (): void {
    $scn = planApiScenario();

    postPlan($scn, ['effective_to' => today()->toDateString()])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['effective_to']]]);
});

// ---- server-owned fields ---------------------------------------------------------

it('ignores every server-owned field a caller tries to supply', function (): void {
    $scn = planApiScenario();
    $foreign = MerchantBranch::factory()->create();

    $response = postPlan($scn, [
        'status' => 'active',
        'is_backdated' => true,
        'merchant_id' => $foreign->merchant_id,
        'branch_id' => $foreign->id,
        'approved_by' => $scn['checker']->id,
        'approved_at' => now()->toIso8601String(),
        'submitted_by' => $scn['maker']->id,
        'created_by' => $scn['checker']->id,
        'supersedes_plan_id' => 999,
        'ulid' => '01JJJJJJJJJJJJJJJJJJJJJJJJ',
    ])->assertCreated();

    $plan = PersonnelCompensationPlan::query()->where('ulid', $response->json('data.id'))->sole();

    // Every server-owned field came from the server, never the request body.
    expect($plan->status)->toBe(CompensationPlanStatus::Draft)
        ->and($plan->is_backdated)->toBeFalse()
        ->and($plan->merchant_id)->toBe($scn['merchant']->id)
        ->and($plan->branch_id)->toBe($scn['branch']->id)
        ->and($plan->approved_by)->toBeNull()
        ->and($plan->approved_at)->toBeNull()
        ->and($plan->submitted_by)->toBeNull()
        ->and($plan->created_by)->toBe($scn['maker']->id)
        ->and($plan->supersedes_plan_id)->toBeNull()
        ->and($plan->ulid)->not->toBe('01JJJJJJJJJJJJJJJJJJJJJJJJ');
});

// ---- draft update ----------------------------------------------------------------

it('lets HR update a draft in place', function (): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['maker'], 'sanctum')
        ->patchJson("/api/v1/compensation-plans/{$ulid}/draft", [
            'compensation_model' => 'salary_only',
            'salary_amount_minor' => 4500000,
            'salary_currency' => 'KES',
            'salary_period' => 'monthly',
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Switched to salary only.',
        ])
        ->assertOk()
        ->assertJsonPath('data.compensation_model', 'salary_only')
        ->assertJsonPath('data.salary_amount_minor', 4500000);
});

it('refuses to update a submitted plan (supersede, never edit)', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    test()->actingAs($scn['maker'], 'sanctum')
        ->patchJson("/api/v1/compensation-plans/{$ulid}/draft", [
            'compensation_model' => 'commission_only',
            'commission_rule_id' => apiRule($scn)->ulid,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Should be rejected.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});

// ---- submit / approve ------------------------------------------------------------

it('lets HR submit a draft', function (): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');

    submitPlanApi($scn, $ulid)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');
});

it('approves a current-dated plan to active with a fresh step-up and a distinct approver', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    approvePlanApi($scn, $ulid)
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $plan = PersonnelCompensationPlan::query()->where('ulid', $ulid)->sole();

    expect($plan->approved_by)->toBe($scn['checker']->id)
        ->and($plan->submitted_by)->toBe($scn['maker']->id)
        ->and(AuditLog::query()->where('action', 'compensation.plan.approved')->exists())->toBeTrue();
});

it('approves a future-dated plan into scheduled', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn, ['effective_from' => today()->addDays(14)->toDateString()]);

    approvePlanApi($scn, $ulid)
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');
});

it('denies approval without a fresh step-up', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    // flushSession() drops the MFA assertion that actingAs() injected during the create/submit
    // setup calls above — without it the approval would carry a *fresh* assertion and this test
    // would silently prove nothing. The route's RequireFreshMfa then rejects before the action runs.
    test()->flushSession()->statefulMfa()->actingAs($scn['checker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/approve", ['change_reason' => 'No step-up.'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'step_up_required');

    expect(PersonnelCompensationPlan::query()->where('ulid', $ulid)->sole()->status)
        ->toBe(CompensationPlanStatus::PendingApproval);
});

it('denies approval with a stale step-up', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);
    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();

    test()->flushSession()->statefulMfa($stale)->actingAs($scn['checker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/approve", ['change_reason' => 'Stale step-up.'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'step_up_required');
});

it('denies the submitter approving their own submission (maker/checker)', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    approvePlanApi($scn, $ulid, [], $scn['maker'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'maker_checker_violation');

    expect(PersonnelCompensationPlan::query()->where('ulid', $ulid)->sole()->status)
        ->toBe(CompensationPlanStatus::PendingApproval);
});

it('writes no success audit when approval is denied', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    approvePlanApi($scn, $ulid, [], $scn['maker'])->assertStatus(403);

    expect(AuditLog::query()->where('action', 'compensation.plan.approved')->exists())->toBeFalse();
});

// ---- backdated approval (F8) -----------------------------------------------------

it('denies a backdated approval without an acknowledged impact preview', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn, ['effective_from' => today()->subDays(30)->toDateString()]);

    approvePlanApi($scn, $ulid)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'backdated_approval_requires_impact_preview');

    expect(PersonnelCompensationPlan::query()->where('ulid', $ulid)->sole()->status)
        ->toBe(CompensationPlanStatus::PendingApproval);
});

it('approves a backdated plan with reason + impact preview + fresh step-up and audits it CRITICAL', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn, ['effective_from' => today()->subDays(30)->toDateString()]);

    approvePlanApi($scn, $ulid, ['acknowledge_impact_preview' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.is_backdated', true);

    $critical = AuditLog::query()->where('action', 'compensation.plan.backdated_change_approved')->sole();

    expect($critical->severity->value)->toBe('critical');
});

it('emits no critical backdated event for an ordinary approval', function (): void {
    $scn = planApiScenario();
    approvePlanApi($scn, pendingPlanApi($scn), [])->assertOk();

    expect(AuditLog::query()->where('action', 'compensation.plan.backdated_change_approved')->exists())->toBeFalse();
});

// ---- reject / cancel -------------------------------------------------------------

it('lets an HR checker reject a pending plan', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);

    test()->actingAs($scn['checker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/reject", ['change_reason' => 'Rate too high.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('lets HR cancel a draft', function (): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['maker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$ulid}/cancel", ['change_reason' => 'Not needed.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('lets HR cancel a scheduled plan but never an active one', function (): void {
    $scn = planApiScenario();

    $scheduled = pendingPlanApi($scn, ['effective_from' => today()->addDays(14)->toDateString()]);
    approvePlanApi($scn, $scheduled)->assertOk();

    test()->actingAs($scn['maker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$scheduled}/cancel", ['change_reason' => 'Withdrawn.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // An ACTIVE plan must be superseded, never cancelled.
    $active = pendingPlanApi($scn);
    approvePlanApi($scn, $active)->assertOk();

    test()->actingAs($scn['maker'], 'sanctum')
        ->postJson("/api/v1/compensation-plans/{$active}/cancel", ['change_reason' => 'Should fail.'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');
});

// ---- role boundaries (Plan §10.2) ------------------------------------------------

it('forbids every non-HR role from configuring compensation', function (MerchantUserRole $role): void {
    $scn = planApiScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    // Plan §10.2: the Merchant Administrator never configures commissions; Branch Manager,
    // Finance, Front Office, Personnel and Audit hold no compensation key at all.
    test()->actingAs($actor, 'sanctum')
        ->postJson('/api/v1/compensation-plans', [
            'staff_profile_id' => $scn['subject']->ulid,
            'compensation_model' => 'commission_only',
            'commission_rule_id' => apiRule($scn)->ulid,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Should be denied.',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
})->with([
    MerchantUserRole::MerchantAdmin,
    MerchantUserRole::BranchManager,
    MerchantUserRole::Finance,
    MerchantUserRole::FrontOffice,
    MerchantUserRole::Personnel,
    MerchantUserRole::Audit,
]);

it('forbids every non-HR role from reading compensation plans', function (MerchantUserRole $role): void {
    $scn = planApiScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    test()->actingAs($actor, 'sanctum')
        ->getJson('/api/v1/compensation-plans')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
})->with([
    MerchantUserRole::MerchantAdmin,
    MerchantUserRole::BranchManager,
    MerchantUserRole::Finance,
    MerchantUserRole::Personnel,
    MerchantUserRole::Audit,
]);

it('forbids Audit from mutating compensation configuration', function (): void {
    $scn = planApiScenario();
    $ulid = pendingPlanApi($scn);
    [$audit] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit);

    // Audit is read-only over every source record.
    approvePlanApi($scn, $ulid, [], $audit)->assertStatus(403);
});

it('forbids Personnel from self-editing their own compensation', function (): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');
    [$personnel] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    test()->actingAs($personnel, 'sanctum')
        ->patchJson("/api/v1/compensation-plans/{$ulid}/draft", [
            'compensation_model' => 'salary_only',
            'salary_amount_minor' => 99000000,
            'salary_currency' => 'KES',
            'salary_period' => 'monthly',
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Giving myself a raise.',
        ])
        ->assertStatus(403);
});

// ---- tenant / branch isolation ---------------------------------------------------

it('404s a staff profile from another merchant', function (): void {
    $scn = planApiScenario();
    $foreignScn = planApiScenario();

    postPlan($scn, ['staff_profile_id' => $foreignScn['subject']->ulid])->assertNotFound();
});

it('404s a commission rule from another merchant', function (): void {
    $scn = planApiScenario();

    postPlan($scn, ['commission_rule_id' => CommissionRule::factory()->create()->ulid])->assertNotFound();
});

it('404s a foreign-tenant plan rather than revealing it exists', function (): void {
    $scn = planApiScenario();
    $foreignScn = planApiScenario();
    $foreignUlid = postPlan($foreignScn)->assertCreated()->json('data.id');

    test()->actingAs($scn['maker'], 'sanctum')
        ->getJson("/api/v1/compensation-plans/{$foreignUlid}")
        ->assertNotFound();
});

it('keeps another merchant plans out of the list', function (): void {
    $scn = planApiScenario();
    $foreignScn = planApiScenario();
    postPlan($foreignScn)->assertCreated();
    postPlan($scn)->assertCreated();

    test()->actingAs($scn['maker'], 'sanctum')
        ->getJson('/api/v1/compensation-plans')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('requires authentication', function (): void {
    test()->getJson('/api/v1/compensation-plans')->assertUnauthorized();
});

// ---- no forbidden routes / no financial runtime ----------------------------------

it('exposes no DELETE, generic status, or manual supersede route', function (string $method, string $path): void {
    $scn = planApiScenario();
    $ulid = postPlan($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['maker'], 'sanctum')
        ->json($method, str_replace('{ulid}', $ulid, $path))
        ->assertStatus(405); // route does not exist for this verb
})->with([
    ['DELETE', '/api/v1/compensation-plans/{ulid}'],
    ['PATCH', '/api/v1/compensation-plans/{ulid}'],
    ['PUT', '/api/v1/compensation-plans/{ulid}'],
]);

it('creates no ledger, payout, or earnings runtime through the API', function (): void {
    $scn = planApiScenario();
    approvePlanApi($scn, pendingPlanApi($scn))->assertOk();

    // Phase 20H payout/earnings tables still do not exist.
    foreach (['personnel_payout_runs', 'personnel_payout_items', 'earnings_statements'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} must not exist before Phase 20H");
    }

    // The Phase 20G ledger tables now exist, but a Phase 20F plan lifecycle writes NO rows to them.
    foreach (['salary_ledger', 'commission_ledger', 'compensation_adjustments'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "Phase 20F must write no {$table} rows");
    }

    // The Phase 18B hand-off seam exists but Phase 20F never writes to it.
    expect(CommissionHandoffEvent::query()->count())->toBe(0);
});
