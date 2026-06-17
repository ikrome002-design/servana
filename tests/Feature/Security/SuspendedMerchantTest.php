<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy', 'security');

/*
 | Suspended-merchant access (Plan §8.1, §8.4). A user whose merchant is not active
 | is blocked at EnsureMerchantActive (403 merchant_suspended) on every operational
 | endpoint — both reads and mutating routes.
 */

/** Active admin user whose merchant is then suspended. */
function suspendedAdmin(): User
{
    $merchant = Merchant::factory()->create(['status' => MerchantStatus::Suspended]);
    $user = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    return $user;
}

it('blocks a suspended merchant on a mutating endpoint with 403 merchant_suspended', function (): void {
    $admin = suspendedAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'X', 'code' => 'SUS001'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('blocks a suspended merchant on a read endpoint', function (): void {
    $admin = suspendedAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('blocks a suspended merchant on the dashboard', function (): void {
    $admin = suspendedAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('still lets a suspended user read /me (no merchant scope required)', function (): void {
    $admin = suspendedAdmin();

    // /me is outside EnsureMerchantActive so the SPA can render a suspended state.
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200);
});
