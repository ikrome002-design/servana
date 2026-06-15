<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Exceptions\StaffLifecycleException;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('hr');

it('suspends a staff member and marks the profile inactive', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, $membership, $profile] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff/{$profile->ulid}/suspend", ['reason' => 'Policy breach'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'suspended');

    expect($membership->fresh()->status)->toBe(MerchantUserStatus::Suspended)
        ->and($profile->fresh()->is_active)->toBeFalse();
});

it('makes a suspended staff member lose merchant access on the next request', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, , $profile] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // Before suspension the staff member can reach the merchant surface.
    $this->actingAs($staffUser, 'sanctum')->getJson('/api/v1/branches')->assertStatus(200);

    app(StaffLifecycleService::class)->suspend($profile->merchantUser, $admin);

    // After suspension their membership is no longer active → no tenant context.
    $this->actingAs($staffUser, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_tenant_context');
});

it('reactivates a suspended staff member who still has a branch assignment', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, $membership, $profile] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    app(StaffLifecycleService::class)->suspend($membership, $admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff/{$profile->ulid}/activate")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active');
});

it('refuses to activate a branch-scoped staff member without an active assignment', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice, assigned: false);
    $membership->update(['status' => MerchantUserStatus::Suspended]);

    expect(fn () => app(StaffLifecycleService::class)->activate($membership->fresh(), $admin))
        ->toThrow(StaffLifecycleException::class);
});

it('refuses to deactivate the sole active merchant administrator', function (): void {
    [, $merchant, $adminMembership] = activeAdmin();

    expect(fn () => app(StaffLifecycleService::class)->deactivate($adminMembership, null))
        ->toThrow(StaffLifecycleException::class);

    expect($adminMembership->fresh()->status)->toBe(MerchantUserStatus::Active);
});

it('allows deactivating an admin when another active admin remains', function (): void {
    [$admin, $merchant, $adminMembership] = activeAdmin();
    // A second active admin so the merchant is not orphaned.
    MerchantUser::factory()->create([
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
        'status' => MerchantUserStatus::Active,
    ]);

    app(StaffLifecycleService::class)->deactivate($adminMembership, $admin);

    expect($adminMembership->fresh()->status)->toBe(MerchantUserStatus::Deactivated);
});
