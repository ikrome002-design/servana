<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'audit-exports', 'mfa');

/*
 | Phase 19 (ADR-010; §19.3 audit.export SU Y): requesting an export requires a FRESH
 | step-up. A request without a fresh MFA assertion is denied and creates no export row.
 */

it('denies an export request without a fresh step-up', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    // Holds audit.export, but statefulMfa() with NO timestamp disables the default MFA
    // session without providing a fresh step-up assertion → RequireFreshMfa denies.
    test()->statefulMfa()->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/audit-exports', ['branch' => $branch->ulid, 'reason' => 'Review reason.'])
        ->assertForbidden();

    expect(AuditExport::query()->count())->toBe(0);
});

it('allows an export request with a fresh step-up', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    test()->statefulMfa(now()->getTimestamp())->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/audit-exports', ['branch' => $branch->ulid, 'reason' => 'Review reason.'])
        ->assertCreated();

    expect(AuditExport::query()->count())->toBe(1);
});
