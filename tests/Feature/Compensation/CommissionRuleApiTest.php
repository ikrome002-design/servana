<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20f', 'phase20f-api');

/*
 | Phase 20F commission-rule API proof (Plan §59; Scope §12.7 Step 3A, §18.3). Branch-scoped,
 | HR-only, governed by the compensation.plan.* keys (the matrix declares no commission.rule.*).
 | Only CREATE + EDIT-DRAFT exist: a rule has no independent lifecycle, so there is no submit/
 | approve/cancel route and NO DELETE (an active rule is ENDED, never deleted).
 */

function ruleApiScenario(): array
{
    test()->seed(PermissionSeeder::class);

    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    return compact('merchant', 'branch', 'hr');
}

function postRule(array $scn, array $body = [], ?User $actor = null)
{
    return test()->actingAs($actor ?? $scn['hr'], 'sanctum')
        ->postJson('/api/v1/commission-rules', array_merge([
            'calculation_type' => 'percentage',
            'calculation_basis' => 'service_price',
            'applies_to' => 'all_services',
            'percentage_basis_points' => 1000,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Initial commission rule.',
        ], $body));
}

// ---- reads + create --------------------------------------------------------------

it('lets HR list branch commission rules', function (): void {
    $scn = ruleApiScenario();
    postRule($scn)->assertCreated();

    test()->actingAs($scn['hr'], 'sanctum')
        ->getJson('/api/v1/commission-rules')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'draft');
});

it('lets HR view a commission rule', function (): void {
    $scn = ruleApiScenario();
    $ulid = postRule($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['hr'], 'sanctum')
        ->getJson("/api/v1/commission-rules/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $ulid);
});

it('creates a percentage rule draft with server-owned scope and status', function (): void {
    $scn = ruleApiScenario();

    $response = postRule($scn)->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.percentage_basis_points', 1000)
        ->assertJsonPath('data.fixed_amount_minor', null)
        ->assertJsonPath('data.applies_to_preferred_personnel_fee', false);

    $rule = CommissionRule::query()->where('ulid', $response->json('data.id'))->sole();

    expect($rule->merchant_id)->toBe($scn['merchant']->id)
        ->and($rule->branch_id)->toBe($scn['branch']->id)
        ->and($rule->created_by)->toBe($scn['hr']->id)
        ->and($rule->status)->toBe(CommissionRuleStatus::Draft);
});

it('creates a fixed-amount rule draft', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, [
        'calculation_type' => 'fixed_amount',
        'percentage_basis_points' => null,
        'fixed_amount_minor' => 50000,
        'currency' => 'KES',
    ])->assertCreated()
        ->assertJsonPath('data.fixed_amount_minor', 50000)
        ->assertJsonPath('data.currency', 'KES')
        ->assertJsonPath('data.percentage_basis_points', null);
});

it('stores the F6 preferred-personnel-fee inclusion flag', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, ['applies_to_preferred_personnel_fee' => true])
        ->assertCreated()
        ->assertJsonPath('data.applies_to_preferred_personnel_fee', true);
});

it('creates a category-scoped rule', function (): void {
    $scn = ruleApiScenario();
    $category = ServiceCategory::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);

    postRule($scn, [
        'applies_to' => 'service_category',
        'service_category_id' => $category->ulid,
    ])->assertCreated()->assertJsonPath('data.applies_to', 'service_category');
});

// ---- F4 value shape --------------------------------------------------------------

it('rejects a percentage rule that also carries a fixed amount', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, ['fixed_amount_minor' => 50000, 'currency' => 'KES'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['fixed_amount_minor']]]);
});

it('rejects a fixed rule that also carries a percentage rate', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, [
        'calculation_type' => 'fixed_amount',
        'fixed_amount_minor' => 50000,
        'currency' => 'KES',
        // percentage_basis_points stays 1000 from the default body
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['percentage_basis_points']]]);
});

it('rejects a fixed rule with no currency', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, [
        'calculation_type' => 'fixed_amount',
        'percentage_basis_points' => null,
        'fixed_amount_minor' => 50000,
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['currency']]]);
});

it('rejects basis points above the 0..10000 structural ceiling', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, ['percentage_basis_points' => 10001])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['percentage_basis_points']]]);
});

it('rejects a float percentage rate', function (): void {
    $scn = ruleApiScenario();

    // Rates are INTEGER basis points (ADR-005) — never float.
    postRule($scn, ['percentage_basis_points' => 10.5])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['percentage_basis_points']]]);
});

it('rejects a negative fixed amount', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, [
        'calculation_type' => 'fixed_amount',
        'percentage_basis_points' => null,
        'fixed_amount_minor' => -1,
        'currency' => 'KES',
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['fixed_amount_minor']]]);
});

it('rejects a category-scoped rule with no category', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, ['applies_to' => 'service_category'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['service_category_id']]]);
});

it('rejects an all_services rule that carries a category', function (): void {
    $scn = ruleApiScenario();
    $category = ServiceCategory::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);

    postRule($scn, ['service_category_id' => $category->ulid])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['service_category_id']]]);
});

// ---- server-owned fields ---------------------------------------------------------

it('ignores every server-owned field a caller tries to supply', function (): void {
    $scn = ruleApiScenario();
    $foreign = MerchantBranch::factory()->create();

    $response = postRule($scn, [
        'status' => 'active',
        'merchant_id' => $foreign->merchant_id,
        'branch_id' => $foreign->id,
        'approved_by' => $scn['hr']->id,
        'approved_at' => now()->toIso8601String(),
        'created_by' => 999,
        'ulid' => '01JJJJJJJJJJJJJJJJJJJJJJJJ',
    ])->assertCreated();

    $rule = CommissionRule::query()->where('ulid', $response->json('data.id'))->sole();

    expect($rule->status)->toBe(CommissionRuleStatus::Draft)
        ->and($rule->merchant_id)->toBe($scn['merchant']->id)
        ->and($rule->branch_id)->toBe($scn['branch']->id)
        ->and($rule->approved_by)->toBeNull()
        ->and($rule->approved_at)->toBeNull()
        ->and($rule->created_by)->toBe($scn['hr']->id)
        ->and($rule->ulid)->not->toBe('01JJJJJJJJJJJJJJJJJJJJJJJJ');
});

// ---- draft edit ------------------------------------------------------------------

it('lets HR update a draft rule in place', function (): void {
    $scn = ruleApiScenario();
    $ulid = postRule($scn)->assertCreated()->json('data.id');

    test()->actingAs($scn['hr'], 'sanctum')
        ->patchJson("/api/v1/commission-rules/{$ulid}/draft", [
            'calculation_type' => 'percentage',
            'calculation_basis' => 'net_after_discount',
            'applies_to' => 'all_services',
            'percentage_basis_points' => 2500,
            'applies_to_preferred_personnel_fee' => true,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Raised the rate.',
        ])
        ->assertOk()
        ->assertJsonPath('data.percentage_basis_points', 2500)
        ->assertJsonPath('data.calculation_basis', 'net_after_discount')
        ->assertJsonPath('data.applies_to_preferred_personnel_fee', true);
});

it('refuses to edit an active rule (supersede, never edit)', function (): void {
    $scn = ruleApiScenario();
    $rule = CommissionRule::factory()->active()->create([
        'merchant_id' => $scn['merchant']->id,
        'branch_id' => $scn['branch']->id,
    ]);

    test()->actingAs($scn['hr'], 'sanctum')
        ->patchJson("/api/v1/commission-rules/{$rule->ulid}/draft", [
            'calculation_type' => 'percentage',
            'calculation_basis' => 'service_price',
            'applies_to' => 'all_services',
            'percentage_basis_points' => 5000,
            'effective_from' => today()->toDateString(),
            'change_reason' => 'Should be rejected.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect($rule->refresh()->percentage_basis_points)->not->toBe(5000);
});

// ---- role boundaries -------------------------------------------------------------

it('forbids every non-HR role from creating a commission rule', function (MerchantUserRole $role): void {
    $scn = ruleApiScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    postRule($scn, [], $actor)
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

it('forbids every non-HR role from reading commission rules', function (MerchantUserRole $role): void {
    $scn = ruleApiScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], $role);

    test()->actingAs($actor, 'sanctum')
        ->getJson('/api/v1/commission-rules')
        ->assertStatus(403);
})->with([
    MerchantUserRole::MerchantAdmin,
    MerchantUserRole::BranchManager,
    MerchantUserRole::Finance,
    MerchantUserRole::Personnel,
    MerchantUserRole::Audit,
]);

// ---- isolation + forbidden routes ------------------------------------------------

it('404s a service category from another merchant', function (): void {
    $scn = ruleApiScenario();

    postRule($scn, [
        'applies_to' => 'service_category',
        'service_category_id' => ServiceCategory::factory()->create()->ulid,
    ])->assertNotFound();
});

it('404s a foreign-tenant rule rather than revealing it exists', function (): void {
    $scn = ruleApiScenario();
    $foreignScn = ruleApiScenario();
    $foreignUlid = postRule($foreignScn)->assertCreated()->json('data.id');

    test()->actingAs($scn['hr'], 'sanctum')
        ->getJson("/api/v1/commission-rules/{$foreignUlid}")
        ->assertNotFound();
});

it('keeps another merchant rules out of the list', function (): void {
    $scn = ruleApiScenario();
    $foreignScn = ruleApiScenario();
    postRule($foreignScn)->assertCreated();
    postRule($scn)->assertCreated();

    test()->actingAs($scn['hr'], 'sanctum')
        ->getJson('/api/v1/commission-rules')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('exposes no DELETE or lifecycle route for a commission rule', function (string $method, string $path): void {
    $scn = ruleApiScenario();
    $ulid = postRule($scn)->assertCreated()->json('data.id');

    // A rule is ENDED by its plan's supersede — never deleted, never independently approved.
    test()->actingAs($scn['hr'], 'sanctum')
        ->json($method, str_replace('{ulid}', $ulid, $path))
        ->assertStatus(in_array($method, ['POST'], true) ? 404 : 405);
})->with([
    ['DELETE', '/api/v1/commission-rules/{ulid}'],
    ['PUT', '/api/v1/commission-rules/{ulid}'],
    ['POST', '/api/v1/commission-rules/{ulid}/approve'],
    ['POST', '/api/v1/commission-rules/{ulid}/submit'],
]);

it('requires authentication', function (): void {
    test()->getJson('/api/v1/commission-rules')->assertUnauthorized();
});
