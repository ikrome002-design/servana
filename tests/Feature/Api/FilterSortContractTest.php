<?php

declare(strict_types=1);

use App\Domain\Branches\Enums\BranchStatus;
use App\Domain\Branches\Models\MerchantBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('api', 'pagination');

/*
 | Filter + sort contract (Plan §23, §24.2; Phase 10). Only allowlisted sorts and
 | validated filters are accepted; anything else is a 422. Ordering is stable and
 | deterministic across identical requests.
 */

it('applies an allowlisted descending sort', function (): void {
    [$admin, $merchant] = activeAdmin();
    foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
        MerchantBranch::factory()->create(['merchant_id' => $merchant->id, 'name' => $name]);
    }

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?sort=-name')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Charlie')
        ->assertJsonPath('data.2.name', 'Alpha');
});

it('rejects a sort field outside the allowlist with 422', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?sort=secret_column')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('applies an allowlisted, validated filter', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $merchant->id, 'status' => BranchStatus::Active->value]);
    MerchantBranch::factory()->create(['merchant_id' => $merchant->id, 'status' => BranchStatus::Archived->value]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'archived');
});

it('rejects a filter value outside the allowlist with 422', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches?status=not-a-status')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('produces stable deterministic ordering for equal sort keys', function (): void {
    [$admin, $merchant] = activeAdmin();
    // Duplicate names force the tiebreaker (id) to decide ordering.
    MerchantBranch::factory()->count(5)->create(['merchant_id' => $merchant->id, 'name' => 'Same']);

    $first = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/branches?sort=name')->assertOk()->json('data');
    $second = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/branches?sort=name')->assertOk()->json('data');

    $codes = array_column($first, 'code');
    expect(array_column($second, 'code'))->toBe($codes)
        ->and($codes)->toHaveCount(5);
});
