<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | Mandatory privileged-role MFA enforcement (Plan §18, §9.4 step 2; Phase R3).
 | The non-allowlisted `testing/privileged-probe` route stands in for any
 | privileged application route.
 */

it('blocks a merchant_admin with no credential (enrollment required)', function (): void {
    [$admin] = activeAdmin();

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_enrollment_required');
});

it('blocks a confirmed merchant_admin without a session assertion (challenge required)', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_challenge_required');
});

it('allows a confirmed merchant_admin with a fresh session assertion', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $this->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(200);
});

it('requires MFA for a finance member', function (): void {
    [$finance] = memberWithRole(MerchantUserRole::Finance);

    $this->statefulMfa()->actingAs($finance, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_enrollment_required');
});

it('requires MFA for a platform super administrator', function (): void {
    $super = User::factory()->create(['is_platform_staff' => true]);

    $this->statefulMfa()->actingAs($super, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_enrollment_required');
});

it('does not block a non-mandatory role without MFA', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$frontOffice] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $this->statefulMfa()->actingAs($frontOffice, 'sanctum')
        ->getJson('/api/v1/testing/privileged-probe')
        ->assertStatus(200);
});

it('always allows the MFA bootstrap routes and /me while MFA is incomplete', function (): void {
    [$admin] = activeAdmin(); // mandatory, no credential

    $this->statefulMfa()->actingAs($admin, 'sanctum')->getJson('/api/v1/me')->assertStatus(200);
    $this->getJson('/api/v1/auth/mfa')->assertStatus(200);
});

it('does not let MFA bypass permission authorization', function (): void {
    $this->seed(PermissionSeeder::class);
    // Finance is mandatory MFA but lacks branches.create — even fully asserted,
    // authorization still denies the mutation (MFA is not authorization).
    [$finance] = memberWithRole(MerchantUserRole::Finance);
    confirmedTotp($finance);

    $this->statefulMfa(now()->getTimestamp())->actingAs($finance, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'X', 'code' => 'X1'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});
