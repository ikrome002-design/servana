<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('tenancy');

function ownerForMerchant(Merchant $merchant, MerchantUserRole $role = MerchantUserRole::MerchantAdmin): User
{
    $user = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => $role,
        'status' => MerchantUserStatus::Active,
    ]);

    return $user;
}

it('lets a pending_setup owner reach the setup endpoint', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant-registration/first-time-setup')
        ->assertStatus(200);
});

it('blocks a pending_setup owner from the dashboard', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'pending_setup_only');
});

it('lets an active merchant owner reach the dashboard', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->active()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(200)
        ->assertJsonPath('data.overview.get_started.setup_complete', true);
});

it('blocks an active merchant owner from the setup endpoint', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->active()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant-registration/first-time-setup')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'setup_already_completed');
});

it('denies a suspended merchant on the dashboard', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->suspended()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('denies a deactivated merchant on the dashboard', function (): void {
    $owner = ownerForMerchant(Merchant::factory()->deactivated()->create());

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('denies a user with no membership on the dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_tenant_context');
});

it('denies a non-admin membership from the setup endpoint', function (): void {
    // A branch_manager membership must not drive first-time setup.
    $user = ownerForMerchant(Merchant::factory()->create(), MerchantUserRole::BranchManager);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/merchant-registration/first-time-setup')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_tenant_context');
});
