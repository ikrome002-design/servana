<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches');

// Day open/close is a Branch Manager capability (`day.open_close`, Plan §10.3).

it('opens a branch day', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/open")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'open');

    expect(BranchDayRecord::query()->where('branch_id', $branch->id)->count())->toBe(1);
});

it('closes a branch day', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/branches/{$branch->ulid}/day/open")->assertStatus(200);
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/close")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'closed');
});

it('records a reopen when opening a previously closed day', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    $today = now('Africa/Nairobi')->toDateString();
    BranchDayRecord::factory()->create([
        'branch_id' => $branch->id,
        'business_date' => $today,
        'status' => BranchDayStatus::Closed,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/open")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'reopened');

    // Still one row for the business date (unique constraint upheld).
    expect(BranchDayRecord::query()->where('branch_id', $branch->id)->count())->toBe(1);
});
