<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches', 'security', 'phase23');

/*
 | Phase 23 §14.1 — the NARROW Branch Manager personnel-options endpoint (product-owner
 | decision). It exists so the shipped Phase 15B read-only personnel-schedule screen keeps
 | its picker after `GET /api/v1/staff` was correctly gated by the HR-only `staff.view`.
 |
 | It is authorized by the `branch.dashboard.view` the Branch Manager ALREADY holds — no new
 | permission key, no widened role grant — and it returns ONLY {id, display_name}: never the
 | phone/role/status/branch metadata that made the unguarded roster a contact-extraction path.
 */

// Uniquely named: Pest file-scope `const` is a GLOBAL constant, so a generic name such as
// OPTIONS_URL would silently collide with tests/Feature/Compensation/CommissionRuleServiceOptionsTest.php
// and redirect that suite's requests to this endpoint.
const BRANCH_PERSONNEL_OPTIONS_URL = '/api/v1/branch/personnel-options';

/** @return array{merchant: Merchant, branch: MerchantBranch, other: MerchantBranch} */
function optionsScenario(): array
{
    test()->seed(PermissionSeeder::class);
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return compact('merchant', 'branch', 'other');
}

it('requires authentication', function (): void {
    optionsScenario();

    test()->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertUnauthorized();
});

it('returns the acting branch personnel as {id, display_name} in display-name order', function (): void {
    $scn = optionsScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);

    $zebra = StaffProfile::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'primary_branch_id' => $scn['branch']->id,
        'display_name' => 'Zebra Zulu',
    ]);
    $alpha = StaffProfile::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'primary_branch_id' => $scn['branch']->id,
        'display_name' => 'Alpha Able',
    ]);

    $res = test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)
        ->assertOk()
        // The Branch Manager's own staff profile is in its branch too.
        ->assertJsonPath('data.0.display_name', 'Alpha Able')
        ->assertJsonPath('data.0.id', $alpha->ulid);

    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->toContain($alpha->ulid)->toContain($zebra->ulid);

    // Deterministic order by display_name.
    $names = collect($res->json('data'))->pluck('display_name')->all();
    $sorted = $names;
    sort($sorted);
    expect($names)->toBe($sorted);
});

it('exposes ONLY the public id and display name — no contact, role, status or branch field', function (): void {
    $scn = optionsScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);
    $personnel = StaffProfile::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'primary_branch_id' => $scn['branch']->id,
        'display_name' => 'Jane Doe',
    ]);

    $res = test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertOk();

    foreach ($res->json('data') as $row) {
        expect(array_keys($row))->toBe(['id', 'display_name']);
    }

    // The exfiltration-relevant values must not appear ANYWHERE in the payload.
    $body = (string) $res->getContent();
    expect($body)
        ->not->toContain($personnel->phone)
        ->and($body)->not->toContain((string) $personnel->id)          // internal numeric PK
        ->and($body)->not->toContain($scn['branch']->ulid)
        ->and($body)->not->toContain('phone')
        ->and($body)->not->toContain('employment_status')
        ->and($body)->not->toContain('primary_branch_id')
        ->and($body)->not->toContain('profile_photo');
});

it('never exposes personnel from another branch of the same merchant', function (): void {
    $scn = optionsScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);
    $foreign = StaffProfile::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'primary_branch_id' => $scn['other']->id,
        'display_name' => 'Other Branch Person',
    ]);

    $res = test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertOk();
    expect(collect($res->json('data'))->pluck('id'))->not->toContain($foreign->ulid);
});

it('never exposes personnel from another merchant', function (): void {
    $scn = optionsScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    $foreign = StaffProfile::factory()->create([
        'merchant_id' => $foreignMerchant->id,
        'primary_branch_id' => $foreignBranch->id,
        'display_name' => 'Foreign Merchant Person',
    ]);

    $res = test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertOk();
    expect(collect($res->json('data'))->pluck('id'))->not->toContain($foreign->ulid);
});

it('denies every role that does not hold branch.dashboard.view', function (string $role): void {
    $scn = optionsScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::from($role));

    test()->actingAs($actor, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertForbidden();
})->with(['front_office', 'personnel', 'audit', 'finance', 'hr']);

it('separates the two authorities completely — neither key grants the other endpoint', function (): void {
    $scn = optionsScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);

    // HR holds staff.view but NOT branch.dashboard.view → roster yes, options no.
    test()->actingAs($hr, 'sanctum')->getJson('/api/v1/staff')->assertOk();
    test()->actingAs($hr, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertForbidden();

    // Branch Manager holds branch.dashboard.view but NOT staff.view → options yes, roster no.
    test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL)->assertOk();
    test()->actingAs($bm, 'sanctum')->getJson('/api/v1/staff')->assertForbidden();
});

it('ignores caller-supplied merchant, branch, role and owner filters', function (): void {
    $scn = optionsScenario();
    [$bm] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::BranchManager);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    $foreign = StaffProfile::factory()->create([
        'merchant_id' => $foreignMerchant->id,
        'primary_branch_id' => $foreignBranch->id,
        'display_name' => 'Foreign Merchant Person',
    ]);
    $otherBranchPerson = StaffProfile::factory()->create([
        'merchant_id' => $scn['merchant']->id,
        'primary_branch_id' => $scn['other']->id,
        'display_name' => 'Other Branch Person',
    ]);

    $query = http_build_query([
        'merchant_id' => $foreignMerchant->id,
        'merchant' => $foreignMerchant->ulid,
        'branch_id' => $scn['other']->id,
        'branch' => $scn['other']->ulid,
        'primary_branch_id' => $scn['other']->id,
        'role' => 'personnel',
        'staff_profile_id' => $foreign->id,
    ]);

    $res = test()->actingAs($bm, 'sanctum')->getJson(BRANCH_PERSONNEL_OPTIONS_URL.'?'.$query)->assertOk();

    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->not->toContain($foreign->ulid)
        ->and($ids)->not->toContain($otherBranchPerson->ulid);
});
