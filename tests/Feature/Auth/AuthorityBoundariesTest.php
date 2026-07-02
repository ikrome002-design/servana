<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'authority');

/*
 | Hard authority boundaries (Plan §10.2). Asserts the SEEDED §10.3 grants never
 | give a role a capability the Plan forbids — the security boundary is the
 | registry + policies, not the UI.
 */

function grants(string $roleKey): array
{
    return app(PermissionRegistry::class)->defaultGrantsFor($roleKey);
}

it('forbids Merchant Admin from configuring services, commissions, personnel assignment or payment validation', function (): void {
    $admin = grants('merchant_admin');

    expect($admin)->not->toContain('service.create')
        ->and($admin)->not->toContain('commissions.manage')
        ->and($admin)->not->toContain('staff.invite')
        ->and($admin)->not->toContain('personnel.availability.manage')
        ->and($admin)->not->toContain('customer_payment.record_exception')
        ->and($admin)->not->toContain('customer_payment.record');
});

it('limits Merchant Admin invitations to branch_manager and hr', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // branch_manager is allowed.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/staff-invitations', [
            'email' => 'manager@example.com',
            'branch_id' => $branch->ulid,
            'role' => MerchantUserRole::BranchManager->value,
        ])
        ->assertStatus(201);

    // personnel (operational) is denied for an admin.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/staff-invitations', [
            'email' => 'stylist@example.com',
            'branch_id' => $branch->ulid,
            'role' => MerchantUserRole::Personnel->value,
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('denies a Branch Manager managing staff lifecycle (no staff assignment authority)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    [, , $target] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/staff/{$target->ulid}/suspend")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');

    expect(grants('branch_manager'))->not->toContain('staff.suspend')
        ->and(grants('branch_manager'))->not->toContain('branches.manage_users_lifecycle');
});

it('keeps HR to its own branch when managing staff', function (): void {
    [, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branchA, MerchantUserRole::Hr);
    [, , $targetInB] = branchStaff($merchant, $branchB, MerchantUserRole::Personnel);

    // HR assigned to branch A cannot suspend a staff member in branch B.
    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$targetInB->ulid}/suspend")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('forbids Finance from bypassing branch scope', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    $foreign = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($finance, 'sanctum')
        ->getJson("/api/v1/branches/{$foreign->ulid}")
        ->assertStatus(404);
});

it('forbids Front Office from validating payments or issuing receipts', function (): void {
    $fo = grants('front_office');

    expect($fo)->toContain('customer_payment.record')
        ->and($fo)->not->toContain('customer_payment.view')
        ->and($fo)->not->toContain('customer_payment.record_exception')
        ->and($fo)->not->toContain('receipts.reissue');
});

it('gives Personnel no export capability anywhere (no key, no route)', function (): void {
    $personnel = grants('personnel');

    // No export key of any kind for personnel (Plan §10.3 **never**).
    foreach ($personnel as $key) {
        expect(str_starts_with($key, 'exports.'))->toBeFalse("personnel must hold no export key, found {$key}");
    }

    // And no contact-export endpoint exists anywhere (guardrail §6.8).
    $hasExportRoute = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($route): bool => str_contains((string) $route->uri(), 'contact')
            && str_contains((string) $route->uri(), 'export'));

    expect($hasExportRoute)->toBeFalse();
});

it('keeps the Audit role read-only (no mutating merchant capability)', function (): void {
    $registry = app(PermissionRegistry::class);
    $audit = grants('audit');

    foreach ($audit as $key) {
        // audit.flag is audit's one in-domain write; everything else must be a read.
        if ($key === 'audit.flag') {
            continue;
        }
        expect($registry->isMutating($key))->toBeFalse("audit must not hold mutating key {$key}");
    }
});
