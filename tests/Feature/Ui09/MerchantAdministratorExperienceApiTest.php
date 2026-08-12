<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui09', 'merchant-administrator');

it('returns a truthful tenant-scoped owner dashboard without fabricated gated metrics', function (): void {
    [$admin, $merchant] = activeAdmin();
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $merchant->id]);

    $foreignMerchant = Merchant::factory()->active()->create();
    MerchantBranch::factory()->count(3)->create(['merchant_id' => $foreignMerchant->id]);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertOk()
        ->assertJsonPath('data.overview.branches.total', 2)
        ->assertJsonPath('data.overview.reporting.available', false)
        ->assertJsonPath('data.overview.reporting.reason', 'External Gate W — Wallet by Citrus collections readiness')
        ->assertJsonPath('data.overview.billing.payment_runtime.available', false)
        ->assertJsonPath('data.overview.get_started.setup_complete', true)
        ->assertJsonPath('data.overview.get_started.first_branch_created', true)
        ->assertJsonPath('data.overview.get_started.daily_reports.available', false);

    expect((string) $response->getContent())
        ->not->toContain('validated_revenue_minor')
        ->not->toContain('payment_success');
});

it('denies the owner dashboard to a non-owner membership', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/merchant/dashboard')
        ->assertForbidden();
});

it('gives the Merchant Administrator a paginated safe lifecycle projection without widening HR staff read', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $profile] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    [, , $foreignProfile] = branchStaff($foreignMerchant, $foreignBranch, MerchantUserRole::Personnel);

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/merchant/staff-overview?per_page=25')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure(['data' => [['id', 'staff_profile_id', 'display_name', 'email', 'role', 'status', 'branches', 'can']], 'meta']);

    expect((string) $response->getContent())
        ->not->toContain($profile->phone)
        ->not->toContain($foreignProfile->display_name)
        ->not->toContain($foreignProfile->phone)
        ->not->toContain('phone');

    // The hardened HR roster remains a separate authority and Merchant Admin does not acquire it.
    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/staff')->assertForbidden();
});

it('denies the owner lifecycle projection to a non-owner account', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/merchant/staff-overview')
        ->assertForbidden();
});

it('validates and bounds the owner lifecycle collection', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/merchant/staff-overview?per_page=101')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});
