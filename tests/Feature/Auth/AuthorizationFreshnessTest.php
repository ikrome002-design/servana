<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Authorization freshness (Plan §79 R6). Membership, role and the permission set
 | are re-resolved from the database on EVERY authenticated request
 | (TenantContextResolver). No request relies on authorization state copied from a
 | previous request, session payload or stale cache.
 */

it('reflects a membership role change on the next request', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $before = $this->actingAs($staffUser, 'sanctum')->getJson('/api/v1/me');
    $before->assertJsonPath('data.membership.role', 'front_office');
    expect($before->json('data.permissions'))->toContain('clients.create');

    // Change the role directly in the database (authority change, not a logout).
    $membership->update(['role' => MerchantUserRole::Personnel]);

    $after = $this->actingAs($staffUser->fresh(), 'sanctum')->getJson('/api/v1/me');
    $after->assertJsonPath('data.membership.role', 'personnel');
    // Personnel cannot create clients — the new role's set is what resolves.
    expect($after->json('data.permissions'))->not->toContain('clients.create');
});

it('loses merchant context on the next request when the membership is suspended', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $this->actingAs($staffUser, 'sanctum')->getJson('/api/v1/me')
        ->assertJsonPath('data.membership.role', 'front_office');

    // Flip the membership to suspended directly (no session deletion): the next
    // request must still re-resolve authoritative state and drop the membership.
    $membership->update(['status' => MerchantUserStatus::Suspended]);

    $after = $this->actingAs($staffUser->fresh(), 'sanctum')->getJson('/api/v1/me');
    $after->assertJsonPath('data.membership', null)
        ->assertJsonPath('data.permissions', []);
});

it('re-queries authoritative state every request (no cross-request authorization cache)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    // First request resolves the front-office set.
    $first = $this->actingAs($staffUser, 'sanctum')->getJson('/api/v1/me');
    expect($first->json('data.permissions'))->toContain('appointments.manage');

    // Deactivate the membership; the next request resolves the empty set.
    $membership->update(['status' => MerchantUserStatus::Deactivated]);
    $second = $this->actingAs($staffUser->fresh(), 'sanctum')->getJson('/api/v1/me');
    expect($second->json('data.permissions'))->toBe([]);
});
