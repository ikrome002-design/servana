<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('branches');

it('lets a merchant admin create a branch for their merchant', function (): void {
    [$admin, $merchant] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/branches', [
            'name' => 'Kilimani Branch',
            'code' => 'KIL001',
            'address' => '1 Wood Ave',
            'town' => 'Nairobi',
            'phone' => '+254700000000',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Kilimani Branch')
        ->assertJsonPath('data.code', 'KIL001')
        ->assertJsonPath('data.status', 'active');

    expect(MerchantBranch::query()->where('merchant_id', $merchant->id)->where('code', 'KIL001')->exists())->toBeTrue();
});

it('blocks a non-admin from creating a branch', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bm] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($bm, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'New Branch', 'code' => 'XYZ001'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('rejects a duplicate branch code within the same merchant', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->create(['merchant_id' => $merchant->id, 'code' => 'DUP001']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'Another', 'code' => 'DUP001'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('lists only the admin own-merchant branches', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $merchant->id]);
    // A branch belonging to a different merchant must not appear.
    MerchantBranch::factory()->create(['merchant_id' => Merchant::factory()->active()->create()->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('lets a branch manager update their branch profile', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->patchJson("/api/v1/branches/{$branch->ulid}", ['name' => 'Renamed Branch'])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Renamed Branch');
});

it('denies a merchant admin editing a branch profile (Branch Manager capability)', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/branches/{$branch->ulid}", ['name' => 'Renamed Branch'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('returns 404 for a branch belonging to another merchant (no leak)', function (): void {
    [$admin] = activeAdmin();
    $otherBranch = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$otherBranch->ulid}")
        ->assertStatus(404);
});
