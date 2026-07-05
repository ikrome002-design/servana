<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Auth\Services\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses()->group('auth', 'permissions', 'matrix', 'authority');

uses(RefreshDatabase::class);

/*
 | §5.6 / Plan §3.1 named role boundaries at the security boundary (registry +
 | resolver), not the UI.
 */

function boundaryGrants(string $role): array
{
    return app(PermissionRegistry::class)->defaultGrantsFor($role);
}

it('keeps the Merchant Administrator from being an operational superuser', function (): void {
    $admin = boundaryGrants('merchant_admin');

    foreach (['service.create', 'customer_payment.record', 'customer_payment.validate', 'staff.invite', 'audit.branch_events.view', 'invoice.create'] as $forbidden) {
        expect($admin)->not->toContain($forbidden);
    }
});

it('keeps the Branch Manager out of Finance, HR and Audit authority', function (): void {
    $bm = boundaryGrants('branch_manager');

    foreach (['customer_payment.validate', 'invoice.create', 'staff.invite', 'audit.branch_events.view', 'audit.finance.view', 'period_lock.create'] as $forbidden) {
        expect($bm)->not->toContain($forbidden);
    }
});

it('lets Front Office record but never validate payments', function (): void {
    $fo = boundaryGrants('front_office');

    expect($fo)->toContain('customer_payment.record')
        ->and($fo)->not->toContain('customer_payment.validate')
        ->and($fo)->not->toContain('customer_payment.record_exception')
        ->and($fo)->not->toContain('receipt.reissue');
});

it('gives Finance validation and period-lock authority', function (): void {
    $finance = boundaryGrants('finance');

    expect($finance)->toContain('customer_payment.validate')
        ->and($finance)->toContain('period_lock.create')
        ->and($finance)->toContain('period_lock.reopen')
        ->and($finance)->toContain('finance.audit.view');
});

it('keeps HR from marking or approving payouts', function (): void {
    $hr = boundaryGrants('hr');

    foreach (['payout_run.mark_paid', 'payout_run.approve_standard', 'merchant.payout.approve_high_value', 'customer_payment.validate'] as $forbidden) {
        expect($hr)->not->toContain($forbidden);
    }
});

it('lets Audit mutate flagged-review metadata only, never a source record', function (): void {
    $registry = app(PermissionRegistry::class);
    $audit = boundaryGrants('audit');
    $inDomainWrites = ['audit.flagged_event.create', 'audit.flagged_event.update_status', 'audit.flagged_event.resolve_metadata', 'audit.export'];

    foreach ($audit as $key) {
        if ($registry->isMutating($key)) {
            expect($key)->toBeIn($inDomainWrites);
        }
    }
});

it('keeps the Super Administrator out of merchant operations', function (): void {
    foreach (app(PermissionResolver::class)->forPlatformStaff() as $key) {
        expect(str_starts_with($key, 'platform.'))->toBeTrue();
    }
});

it('404s guessed Personnel contact-export routes (no such endpoint exists)', function (): void {
    $user = eligibleOwner('boundary-owner@example.com');

    foreach (['/api/v1/personnel/contacts/export', '/api/v1/personnel/clients/export', '/api/v1/clients/contacts/export'] as $guess) {
        $this->actingAs($user, 'sanctum')->getJson($guess)->assertNotFound();
    }

    // And no route in the whole collection exports contacts.
    $hasExport = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($r): bool => str_contains((string) $r->uri(), 'contact') && str_contains((string) $r->uri(), 'export'));
    expect($hasExport)->toBeFalse();
});
