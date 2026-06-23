<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('api', 'capabilities');

/*
 | Resource capability-map contract (Plan §12, §23; Correction 16.5). The `can`
 | map is derived server-side from policies/resolved permissions and differs by
 | the caller's authority. Only real current actions appear; values are booleans;
 | no internal ids are exposed.
 */

it('derives a branch can-map from policy for a merchant admin', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $can = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertOk()
        ->json('data.can');

    // Merchant Admin holds branches.create (archive) but NOT branch.profile.manage
    // (a Branch Manager capability) — the map reflects that exactly.
    expect($can['view'])->toBeTrue()
        ->and($can['archive'])->toBeTrue()
        ->and($can['update'])->toBeFalse()
        ->and($can['manage_operating_hours'])->toBeFalse()
        ->and($can['manage_day'])->toBeFalse();
});

it('derives a different branch can-map for a branch manager', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $can = $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertOk()
        ->json('data.can');

    // Branch Manager holds branch.profile.manage + day.open_close, NOT branches.create.
    expect($can['update'])->toBeTrue()
        ->and($can['manage_operating_hours'])->toBeTrue()
        ->and($can['manage_day'])->toBeTrue()
        ->and($can['archive'])->toBeFalse();
});

it('returns only boolean capability values', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $row = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKey('can');
    foreach ($row['can'] as $value) {
        expect($value)->toBeBool();
    }
});

it('does not attach a capability map to the health payload', function (): void {
    $body = $this->getJson('/health')->assertOk()->json();

    expect($body)->not->toHaveKey('can');
});
