<?php

declare(strict_types=1);

use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches');

// Operating hours are a Branch Manager capability (`branch.profile.manage`,
// Plan §10.3) within the manager's own branch scope.

it('upserts weekly operating hours for a branch', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/branches/{$branch->ulid}/operating-hours", [
            'hours' => [
                ['weekday' => 1, 'is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '18:00'],
                ['weekday' => 0, 'is_closed' => true],
            ],
        ])
        ->assertStatus(200);

    expect(BranchOperatingHour::query()->where('branch_id', $branch->id)->count())->toBe(2);
    $monday = BranchOperatingHour::query()->where('branch_id', $branch->id)->where('weekday', 1)->firstOrFail();
    expect($monday->is_closed)->toBeFalse();
});

it('is idempotent per weekday (updates rather than duplicates)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $payload = ['hours' => [['weekday' => 2, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']]];
    $this->actingAs($manager, 'sanctum')->putJson("/api/v1/branches/{$branch->ulid}/operating-hours", $payload)->assertStatus(200);
    $this->actingAs($manager, 'sanctum')->putJson("/api/v1/branches/{$branch->ulid}/operating-hours", $payload)->assertStatus(200);

    expect(BranchOperatingHour::query()->where('branch_id', $branch->id)->where('weekday', 2)->count())->toBe(1);
});

it('validates the weekday range', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/branches/{$branch->ulid}/operating-hours", [
            'hours' => [['weekday' => 9, 'is_closed' => true]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('denies a merchant admin editing operating hours (Branch Manager capability)', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/branches/{$branch->ulid}/operating-hours", [
            'hours' => [['weekday' => 1, 'is_closed' => true]],
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});
