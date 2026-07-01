<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'audit');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('records a high-severity audit row when an admin grants a finance override', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
            'permission' => 'refunds.approve',
            'effect' => 'grant',
            'reason' => 'senior cashier',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.permissions', fn ($p): bool => in_array('refunds.approve', $p, true));

    $log = AuditLog::query()->where('action', 'permission.override.created')->firstOrFail();
    expect($log->severity)->toBe(AuditSeverity::High)
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->context['permission'])->toBe('refunds.approve');
});

it('audits an update then a revoke of an override', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // Create (grant), then update to deny, then revoke.
    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
        'permission' => 'refunds.approve', 'effect' => 'grant',
    ])->assertStatus(200);

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
        'permission' => 'refunds.approve', 'effect' => 'deny',
    ])->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/staff/{$finance->ulid}/permissions/refunds.approve")
        ->assertStatus(200);

    expect(AuditLog::query()->where('action', 'permission.override.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'permission.override.updated')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'permission.override.revoked')->count())->toBe(1);
});

it('makes a deny override beat the role default grant', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // customer_payment.view is a finance default grant; deny it via override.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
            'permission' => 'customer_payment.view',
            'effect' => 'deny',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.permissions', fn ($p): bool => ! in_array('customer_payment.view', $p, true));
});

it('rejects granting a key that is not grantable for the target role', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // service.create is a Branch Manager key, never grantable to finance.
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
            'permission' => 'service.create',
            'effect' => 'grant',
        ])
        ->assertStatus(403);
});
