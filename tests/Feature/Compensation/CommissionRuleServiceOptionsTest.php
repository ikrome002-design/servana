<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('compensation', 'phase20g', 'phase20g-selected-services');

/*
 | Phase 20G §9.1 — the HR selected-services OPTION endpoint (product-owner decision). A narrow, read-only
 | compensation read model authorized by `compensation.plan.view` — NOT `service.view` (HR cannot hold it).
 | It returns only the acting branch's ACTIVE services as {ulid, name}; a foreign branch/merchant service
 | can never appear, and no price/status/management field is exposed.
 */

const OPTIONS_URL = '/api/v1/commission-rule-service-options';

function optionScenario(): array
{
    test()->seed(PermissionSeeder::class);
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    return compact('merchant', 'branch', 'hr');
}

it('returns the acting branch active services as {ulid, name} in name order for HR', function (): void {
    $scn = optionScenario();
    $zebra = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'name' => 'Zebra trim']);
    $alpha = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'name' => 'Alpha cut']);

    $res = test()->actingAs($scn['hr'], 'sanctum')->getJson(OPTIONS_URL)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha cut')
        ->assertJsonPath('data.0.ulid', $alpha->ulid)
        ->assertJsonPath('data.1.ulid', $zebra->ulid);

    // Minimal masked shape — ONLY ulid + name; never an internal id, price, status or management field.
    expect(array_keys($res->json('data.0')))->toBe(['ulid', 'name']);
});

it('excludes archived services from the option list', function (): void {
    $scn = optionScenario();
    Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'name' => 'Active one']);
    Service::factory()->archived()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'name' => 'Archived one']);

    test()->actingAs($scn['hr'], 'sanctum')->getJson(OPTIONS_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active one');
});

it('never exposes a service from another branch of the same merchant', function (): void {
    $scn = optionScenario();
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $foreign = Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $otherBranch->id, 'name' => 'Other branch svc']);
    Service::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'name' => 'My branch svc']);

    $res = test()->actingAs($scn['hr'], 'sanctum')->getJson(OPTIONS_URL)->assertOk()->assertJsonCount(1, 'data');
    expect(collect($res->json('data'))->pluck('ulid'))->not->toContain($foreign->ulid);
});

it('never exposes a service from another merchant', function (): void {
    $scn = optionScenario();
    $foreign = Service::factory()->create(['name' => 'Foreign merchant svc']); // entirely different merchant+branch

    $res = test()->actingAs($scn['hr'], 'sanctum')->getJson(OPTIONS_URL)->assertOk();
    expect(collect($res->json('data'))->pluck('ulid'))->not->toContain($foreign->ulid);
});

it('denies a user without compensation.plan.view (Front Office)', function (): void {
    $scn = optionScenario();
    [$frontOffice] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

    test()->actingAs($frontOffice, 'sanctum')->getJson(OPTIONS_URL)->assertForbidden();
});

it('does NOT authorize a Branch Manager who holds service.view but not compensation.plan.view', function (): void {
    $scn = optionScenario();
    // Branch Manager holds service.view but no compensation.plan.* key — proof the endpoint is gated by the
    // compensation permission, never by service.view.
    [$branchManager] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);

    test()->actingAs($branchManager, 'sanctum')->getJson(OPTIONS_URL)->assertForbidden();
});

it('requires authentication', function (): void {
    optionScenario();
    test()->getJson(OPTIONS_URL)->assertUnauthorized();
});
