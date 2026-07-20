<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-selected-services');

/*
 | Phase 20G Increment 6A — the selected-services membership contract on the HR commission-rule draft API
 | (Plan §61 §9.1). `selected_service_ulids` is resolved to Service models INSIDE the acting merchant+branch,
 | persisted as immutable draft memberships transactionally, and returned by the masked Resource. The
 | database triggers (proven by Phase20GSchemaTest) are the last line of defence; these prove the API layer.
 */

function selScenario(): array
{
    test()->seed(PermissionSeeder::class);

    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    $serviceA = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'name' => 'Alpha cut']);
    $serviceB = Service::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id, 'name' => 'Beta colour']);

    return compact('merchant', 'branch', 'hr', 'serviceA', 'serviceB');
}

/** POST a selected-services commission-rule draft with the given service ULIDs. */
function postSelectedRule(array $scn, array $ulids, array $override = [])
{
    return test()->actingAs($scn['hr'], 'sanctum')->postJson('/api/v1/commission-rules', array_merge([
        'calculation_type' => 'percentage',
        'calculation_basis' => 'service_price',
        'applies_to' => 'selected_services',
        'percentage_basis_points' => 1000,
        'effective_from' => today()->toDateString(),
        'change_reason' => 'Selected-services rule.',
        'selected_service_ulids' => $ulids,
    ], $override));
}

function patchRuleDraft(array $scn, string $ulid, array $body)
{
    return test()->actingAs($scn['hr'], 'sanctum')->patchJson("/api/v1/commission-rules/{$ulid}/draft", array_merge([
        'calculation_type' => 'percentage',
        'calculation_basis' => 'service_price',
        'percentage_basis_points' => 1000,
        'effective_from' => today()->toDateString(),
        'change_reason' => 'Edit draft.',
    ], $body));
}

// ---- create -----------------------------------------------------------------------

it('creates a selected-services rule with one service and persists the membership', function (): void {
    $scn = selScenario();

    $res = postSelectedRule($scn, [$scn['serviceA']->ulid])
        ->assertCreated()
        ->assertJsonPath('data.applies_to', 'selected_services')
        ->assertJsonPath('data.selected_service_ulids', [$scn['serviceA']->ulid]);

    $rule = CommissionRule::query()->where('ulid', $res->json('data.id'))->sole();
    expect(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->count())->toBe(1)
        ->and(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->value('service_id'))->toBe($scn['serviceA']->id);
});

it('creates a selected-services rule with multiple services in deterministic name order', function (): void {
    $scn = selScenario();

    // Submitted out of alphabetical order; the Resource returns them ordered by service name.
    postSelectedRule($scn, [$scn['serviceB']->ulid, $scn['serviceA']->ulid])
        ->assertCreated()
        ->assertJsonPath('data.selected_service_ulids', [$scn['serviceA']->ulid, $scn['serviceB']->ulid])
        ->assertJsonPath('data.selected_services.0.name', 'Alpha cut')
        ->assertJsonPath('data.selected_services.1.name', 'Beta colour');
});

it('returns an empty selected-services array for an all_services rule', function (): void {
    $scn = selScenario();

    test()->actingAs($scn['hr'], 'sanctum')->postJson('/api/v1/commission-rules', [
        'calculation_type' => 'percentage', 'calculation_basis' => 'service_price', 'applies_to' => 'all_services',
        'percentage_basis_points' => 1000, 'effective_from' => today()->toDateString(), 'change_reason' => 'All.',
    ])->assertCreated()
        ->assertJsonStructure(['data' => ['selected_service_ulids', 'selected_services']])
        ->assertJsonPath('data.selected_service_ulids', [])
        ->assertJsonPath('data.selected_services', []);
});

// ---- update draft -----------------------------------------------------------------

it('replaces the membership set on a draft update (add + remove)', function (): void {
    $scn = selScenario();
    $ulid = postSelectedRule($scn, [$scn['serviceA']->ulid])->assertCreated()->json('data.id');

    patchRuleDraft($scn, $ulid, ['applies_to' => 'selected_services', 'selected_service_ulids' => [$scn['serviceB']->ulid]])
        ->assertOk()
        ->assertJsonPath('data.selected_service_ulids', [$scn['serviceB']->ulid]);

    $rule = CommissionRule::query()->where('ulid', $ulid)->sole();
    expect(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->pluck('service_id')->all())
        ->toBe([$scn['serviceB']->id]);
});

it('clears memberships when a draft moves selected_services -> all_services', function (): void {
    $scn = selScenario();
    $ulid = postSelectedRule($scn, [$scn['serviceA']->ulid, $scn['serviceB']->ulid])->assertCreated()->json('data.id');

    patchRuleDraft($scn, $ulid, ['applies_to' => 'all_services'])
        ->assertOk()
        ->assertJsonPath('data.selected_service_ulids', []);

    $rule = CommissionRule::query()->where('ulid', $ulid)->sole();
    expect(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->count())->toBe(0);
});

it('clears memberships when a draft moves selected_services -> service_category', function (): void {
    $scn = selScenario();
    $category = ServiceCategory::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    $ulid = postSelectedRule($scn, [$scn['serviceA']->ulid])->assertCreated()->json('data.id');

    patchRuleDraft($scn, $ulid, ['applies_to' => 'service_category', 'service_category_id' => $category->ulid])
        ->assertOk()
        ->assertJsonPath('data.selected_service_ulids', []);

    expect(CommissionRuleService::query()->where('commission_rule_id', CommissionRule::query()->where('ulid', $ulid)->value('id'))->count())->toBe(0);
});

it('inserts memberships when a draft moves all_services -> selected_services', function (): void {
    $scn = selScenario();
    $ulid = test()->actingAs($scn['hr'], 'sanctum')->postJson('/api/v1/commission-rules', [
        'calculation_type' => 'percentage', 'calculation_basis' => 'service_price', 'applies_to' => 'all_services',
        'percentage_basis_points' => 1000, 'effective_from' => today()->toDateString(), 'change_reason' => 'All.',
    ])->assertCreated()->json('data.id');

    patchRuleDraft($scn, $ulid, ['applies_to' => 'selected_services', 'selected_service_ulids' => [$scn['serviceA']->ulid]])
        ->assertOk()
        ->assertJsonPath('data.selected_service_ulids', [$scn['serviceA']->ulid]);
});

it('leaves the original membership set intact when a draft update fails on a foreign service', function (): void {
    $scn = selScenario();
    $ulid = postSelectedRule($scn, [$scn['serviceA']->ulid])->assertCreated()->json('data.id');

    $foreign = Service::factory()->create(); // another merchant/branch entirely

    patchRuleDraft($scn, $ulid, ['applies_to' => 'selected_services', 'selected_service_ulids' => [$foreign->ulid]])
        ->assertNotFound();

    // The rejected update replaced nothing — the original single membership survives (atomic).
    $rule = CommissionRule::query()->where('ulid', $ulid)->sole();
    expect(CommissionRuleService::query()->where('commission_rule_id', $rule->id)->pluck('service_id')->all())
        ->toBe([$scn['serviceA']->id]);
});

// ---- validation -------------------------------------------------------------------

it('rejects a selected_services rule with an empty membership set', function (): void {
    $scn = selScenario();
    postSelectedRule($scn, [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.fields.selected_service_ulids.0', fn ($m) => is_string($m));
});

it('rejects duplicate service ULIDs', function (): void {
    $scn = selScenario();
    postSelectedRule($scn, [$scn['serviceA']->ulid, $scn['serviceA']->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects an invalid ULID length', function (): void {
    $scn = selScenario();
    postSelectedRule($scn, ['not-a-ulid'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects selected_service_ulids supplied for an all_services rule', function (): void {
    $scn = selScenario();
    postSelectedRule($scn, [$scn['serviceA']->ulid], ['applies_to' => 'all_services'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.fields.selected_service_ulids.0', fn ($m) => is_string($m));
});

it('rejects selected_service_ulids supplied for a service_category rule', function (): void {
    $scn = selScenario();
    $category = ServiceCategory::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    postSelectedRule($scn, [$scn['serviceA']->ulid], ['applies_to' => 'service_category', 'service_category_id' => $category->ulid])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.fields.selected_service_ulids.0', fn ($m) => is_string($m));
});

// ---- scope + lifecycle ------------------------------------------------------------

it('404s a service from another merchant (never leaks existence)', function (): void {
    $scn = selScenario();
    $foreign = Service::factory()->create();
    postSelectedRule($scn, [$foreign->ulid])->assertNotFound();
    expect(CommissionRule::query()->count())->toBe(0);
});

it('404s a service from another branch of the same merchant', function (): void {
    $scn = selScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $crossBranch = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id]);
    postSelectedRule($scn, [$crossBranch->ulid])->assertNotFound();
});

it('rejects newly selecting an archived service', function (): void {
    $scn = selScenario();
    $archived = Service::factory()->archived()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    postSelectedRule($scn, [$archived->ulid])->assertStatus(422);
});

it('lets the same service belong to two separate versioned rules', function (): void {
    $scn = selScenario();
    postSelectedRule($scn, [$scn['serviceA']->ulid])->assertCreated();
    postSelectedRule($scn, [$scn['serviceA']->ulid])->assertCreated();
    expect(CommissionRuleService::query()->where('service_id', $scn['serviceA']->id)->count())->toBe(2);
});
