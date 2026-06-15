<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'authority');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('denies HR editing its own membership permissions and audits the attempt', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr, , $hrProfile] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$hrProfile->ulid}/permissions", [
            'permission' => 'staff.suspend',
            'effect' => 'grant',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');

    expect(AuditLog::query()->where('action', 'permission.override.denied_self_escalation')->exists())->toBeTrue();
});

it('denies HR granting a subordinate a non-grantable capability and audits it', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    // personnel has no grantable (◐) keys at all — escalation is impossible.
    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$personnel->ulid}/permissions", [
            'permission' => 'payments.validate',
            'effect' => 'grant',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');

    expect(AuditLog::query()->where('action', 'permission.override.denied_self_escalation')->exists())->toBeTrue();
});

it('denies HR granting a finance member a key HR does not itself hold', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // refunds.approve IS grantable for finance, but HR does not hold it → blocked.
    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
            'permission' => 'refunds.approve',
            'effect' => 'grant',
        ])
        ->assertStatus(403);
});

it('allows HR to manage an in-scope subordinate (deny override) — proving authority exists', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$personnel->ulid}/permissions", [
            'permission' => 'clients.view',
            'effect' => 'deny',
            'reason' => 'data minimisation',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.permissions', fn ($p): bool => ! in_array('clients.view', $p, true));
});
