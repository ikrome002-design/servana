<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches');

it('opens a branch day', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/open")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'open');

    expect(BranchDayRecord::query()->where('branch_id', $branch->id)->count())->toBe(1);
});

it('closes a branch day', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/branches/{$branch->ulid}/day/open")->assertStatus(200);
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/close")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'closed');
});

it('records a reopen when opening a previously closed day', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $today = now('Africa/Nairobi')->toDateString();
    BranchDayRecord::factory()->create([
        'branch_id' => $branch->id,
        'business_date' => $today,
        'status' => BranchDayStatus::Closed,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/branches/{$branch->ulid}/day/open")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'reopened');

    // Still one row for the business date (unique constraint upheld).
    expect(BranchDayRecord::query()->where('branch_id', $branch->id)->count())->toBe(1);
});
