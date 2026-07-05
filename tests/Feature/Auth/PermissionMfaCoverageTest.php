<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix', 'mfa');

/*
 | §19.3 MFA closure (Increment 3 deferral): a key whose matrix row says
 | mfa_required MUST be enforced by backend middleware — not frontend visibility.
 | finance.audit.view (Finance group, MFA Y) is the canonical Phase 19 case.
 */

it('declares MFA on the finance/platform audit surface in the contract', function (): void {
    $matrix = app(PermissionMatrix::class);

    expect($matrix->get('finance.audit.view')['mfa_required'])->toBeTrue();
    expect($matrix->get('finance.audit.view')['step_up_required'])->toBeFalse();
    expect($matrix->get('platform.audit.view')['mfa_required'])->toBeTrue();
});

it('serves the finance audit trail to a Finance principal WITH an MFA assertion', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // Default actingAs() seeds a confirmed credential + MFA session assertion.
    $this->actingAs($finance, 'sanctum')
        ->getJson('/api/v1/audit-logs/finance?branch='.$branch->ulid)
        ->assertOk();
});

it('blocks the finance audit trail at the MFA gate when the Finance principal has no MFA assertion', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // statefulMfa() with no assertion → the privileged-MFA gate denies BEFORE the
    // permission check (proving MFA is a real backend boundary, not UX).
    $response = test()->statefulMfa()->actingAs($finance, 'sanctum')
        ->getJson('/api/v1/audit-logs/finance?branch='.$branch->ulid)
        ->assertForbidden();

    expect($response->json('error.code'))
        ->toBeIn(['mfa_enrollment_required', 'mfa_challenge_required']);
});

it('does not attach a fresh-step-up guard to an MFA-only read route', function (): void {
    $middleware = Route::getRoutes()->getByName('audit-logs.finance')->gatherMiddleware();

    $hasFreshStepUp = collect($middleware)->contains(fn ($m): bool => str_contains((string) $m, 'RequireFreshMfa'));

    expect($hasFreshStepUp)->toBeFalse('finance.audit.view requires only authenticated MFA, never a fresh step-up');
});
