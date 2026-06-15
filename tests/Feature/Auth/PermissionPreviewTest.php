<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('lets an admin preview what a target role would hold', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/hr/permission-preview?role=finance')
        ->assertStatus(200)
        ->assertJsonPath('data.role', 'finance')
        ->assertJsonPath('data.default_grants', fn ($g): bool => in_array('payments.validate', $g, true))
        ->assertJsonPath('data.grantable', fn ($g): bool => in_array('refunds.approve', $g, true));
});

it('lets HR preview a role', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/hr/permission-preview?role=front_office')
        ->assertStatus(200)
        ->assertJsonPath('data.role', 'front_office');
});

it('denies preview to a role without staff-management authority', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($personnel, 'sanctum')
        ->getJson('/api/v1/hr/permission-preview?role=finance')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('validates the previewed role', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/hr/permission-preview?role=not_a_role')
        ->assertStatus(422);
});

it('shows a specific staff members resolved permissions and overrides', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/staff/{$finance->ulid}/permissions")
        ->assertStatus(200)
        ->assertJsonPath('data.role', 'finance')
        ->assertJsonPath('data.permissions', fn ($p): bool => in_array('payments.validate', $p, true))
        ->assertJsonPath('data.grantable', fn ($g): bool => in_array('refunds.approve', $g, true));
});
