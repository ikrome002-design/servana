<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use Illuminate\Support\Facades\Route;

uses()->group('auth', 'permissions', 'matrix', 'mfa');

/*
 | §19.3 step-up closure: a key whose matrix row says step_up_required MUST have a
 | RequireFreshMfa guard on its owning route, and a key that does NOT must not.
 | audit.export (SU Y) is the canonical Phase 19 case; behavioural allow/deny for
 | it lives in AuditExportStepUpTest.
 */

function routeHasFreshStepUp(string $routeName): bool
{
    $route = Route::getRoutes()->getByName($routeName);

    return $route !== null && collect($route->gatherMiddleware())
        ->contains(fn ($m): bool => str_contains((string) $m, 'RequireFreshMfa'));
}

it('declares fresh step-up for audit.export and not for the audit reads', function (): void {
    $matrix = app(PermissionMatrix::class);

    expect($matrix->get('audit.export')['step_up_required'])->toBeTrue();
    expect($matrix->get('audit.branch_events.view')['step_up_required'])->toBeFalse();
    expect($matrix->get('audit.finance.view')['step_up_required'])->toBeFalse();
    expect($matrix->get('audit.compensation.view')['step_up_required'])->toBeFalse();
});

it('guards the audit-export request route with a fresh step-up', function (): void {
    expect(routeHasFreshStepUp('audit-exports.store'))->toBeTrue();
});

it('does not guard the audit read/list routes with a fresh step-up', function (): void {
    expect(routeHasFreshStepUp('audit-logs.index'))->toBeFalse();
    expect(routeHasFreshStepUp('audit-logs.finance'))->toBeFalse();
    expect(routeHasFreshStepUp('audit-exports.index'))->toBeFalse();
    expect(routeHasFreshStepUp('audit-flagged-events.index'))->toBeFalse();
});

it('keeps platform.audit.export metadata-only (no runtime route) while retaining its step-up metadata', function (): void {
    $matrix = app(PermissionMatrix::class);

    // Metadata retains the §19.3 attributes (MFA Y / SU Y / planned) ...
    expect($matrix->get('platform.audit.export')['implementation_status'])->toBe('planned');
    expect($matrix->get('platform.audit.export')['step_up_required'])->toBeTrue();

    // ... but no runtime route is registered for it (§5.3 — metadata-only).
    $hasRoute = collect(Route::getRoutes()->getRoutes())
        ->contains(fn ($r): bool => str_contains((string) $r->uri(), 'platform')
            && str_contains((string) $r->uri(), 'audit')
            && str_contains((string) $r->uri(), 'export'));

    expect($hasRoute)->toBeFalse();
});
