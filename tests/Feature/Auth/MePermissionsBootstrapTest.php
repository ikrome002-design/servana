<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

it('returns a merchant admin resolved permissions in /me', function (): void {
    [$admin] = activeAdmin();
    $registry = app(PermissionRegistry::class);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/me')->assertStatus(200);

    $permissions = $response->json('data.permissions');
    sort($permissions);
    $expected = $registry->defaultGrantsFor('merchant_admin');
    sort($expected);

    expect($permissions)->toBe($expected)
        ->and($permissions)->toContain('branches.create')
        ->and($permissions)->not->toContain('services.manage');
});

it('returns a front office member their scoped permissions in /me', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $permissions = $this->actingAs($fo, 'sanctum')->getJson('/api/v1/me')->json('data.permissions');

    expect($permissions)->toContain('payments.record')
        ->and($permissions)->toContain('clients.create')
        ->and($permissions)->not->toContain('payments.validate')
        ->and($permissions)->not->toContain('receipts.reissue');
});

it('returns no permissions for a suspended membership', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $membership->update(['status' => MerchantUserStatus::Suspended]);

    // A suspended membership is no longer the active membership, so tenant
    // context resolves no merchant and the permission set is empty.
    $permissions = $this->actingAs($fo, 'sanctum')->getJson('/api/v1/me')->json('data.permissions');

    expect($permissions)->toBe([]);
});
