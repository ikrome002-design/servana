<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy');

/*
 | Cross-merchant query isolation (Plan §8.2). The global MerchantScope constrains
 | every tenant-owned query to the resolved merchant, both over HTTP and when the
 | context is bound directly.
 */

it('lists only own-merchant resources over the API', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $merchant->id]);

    // Another merchant's branches must never appear.
    $other = Merchant::factory()->active()->create();
    MerchantBranch::factory()->count(3)->create(['merchant_id' => $other->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('applies the merchant global scope when a context is bound', function (): void {
    $a = Merchant::factory()->active()->create();
    $b = Merchant::factory()->active()->create();
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $a->id]);
    MerchantBranch::factory()->count(3)->create(['merchant_id' => $b->id]);

    $context = app(TenantContext::class);
    $context->bindForJob($a);
    expect(MerchantBranch::query()->count())->toBe(2);

    $context->bindForJob($b);
    expect(MerchantBranch::query()->count())->toBe(3);

    $context->reset();
});

it('auto-fills merchant_id from context on create', function (): void {
    $merchant = Merchant::factory()->active()->create();
    app(TenantContext::class)->bindForJob($merchant);

    $branch = MerchantBranch::query()->create([
        'name' => 'Auto Scoped',
        'code' => 'AUTO01',
    ]);

    expect($branch->merchant_id)->toBe($merchant->id);

    app(TenantContext::class)->reset();
});

it('throws MissingTenantContext when creating a tenant row with no context and no merchant_id', function (): void {
    app(TenantContext::class)->reset();

    expect(fn () => MerchantBranch::query()->create([
        'name' => 'Orphan',
        'code' => 'ORP001',
    ]))->toThrow(MissingTenantContext::class);
});

it('honours an explicit merchant_id on create (onboarding path) with no context', function (): void {
    app(TenantContext::class)->reset();
    $merchant = Merchant::factory()->active()->create();

    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    expect($branch->merchant_id)->toBe($merchant->id);
});

it('scopes staff queries to the resolved merchant', function (): void {
    $a = Merchant::factory()->active()->create();
    $b = Merchant::factory()->active()->create();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $a->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $b->id]);
    branchStaff($a, $branchA, MerchantUserRole::FrontOffice);
    branchStaff($b, $branchB, MerchantUserRole::FrontOffice);

    app(TenantContext::class)->bindForJob($a);
    expect(StaffProfile::query()->count())->toBe(1)
        ->and(MerchantUser::query()->count())->toBe(1);

    app(TenantContext::class)->reset();
});
