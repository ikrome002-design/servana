<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('tenancy');

it('resolves the merchant context into /me for a merchant user', function (): void {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->active()->create(['name' => 'Glow Salon']);
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.merchant.id', $merchant->ulid)
        ->assertJsonPath('data.merchant.name', 'Glow Salon')
        ->assertJsonPath('data.merchant.status', 'active')
        ->assertJsonPath('data.membership.role', 'merchant_admin')
        ->assertJsonPath('data.membership.status', 'active')
        ->assertJsonPath('data.setup.required', false)
        ->assertJsonPath('data.user.is_platform_staff', false);
});

it('reports setup required for a pending_setup merchant', function (): void {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create(); // pending_setup
    MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.merchant.status', 'pending_setup')
        ->assertJsonPath('data.setup.required', true)
        ->assertJsonPath('data.setup.current_step', 'service_fee_tier');
});

it('marks platform staff and exposes no merchant', function (): void {
    $user = User::factory()->platformStaff()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.user.is_platform_staff', true)
        ->assertJsonPath('data.merchant', null)
        ->assertJsonPath('data.membership', null);
});

it('never exposes another merchant — a user sees only their own context', function (): void {
    $userA = User::factory()->create();
    $merchantA = Merchant::factory()->active()->create(['name' => 'Merchant A']);
    MerchantUser::factory()->create([
        'user_id' => $userA->id,
        'merchant_id' => $merchantA->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    // A second, unrelated merchant exists.
    Merchant::factory()->active()->create(['name' => 'Merchant B']);

    $this->actingAs($userA, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.merchant.id', $merchantA->ulid)
        ->assertJsonPath('data.merchant.name', 'Merchant A');
});

it('returns an empty tenant context for a guest (401)', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
