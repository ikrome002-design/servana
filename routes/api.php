<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\StepUpAction;
use App\Http\Controllers\Api\V1\Audit\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\MagicLinkController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Branches\BranchController;
use App\Http\Controllers\Api\V1\Branches\BranchDayController;
use App\Http\Controllers\Api\V1\Branches\BranchOperatingHoursController;
use App\Http\Controllers\Api\V1\Hr\PermissionOverrideController;
use App\Http\Controllers\Api\V1\Hr\PermissionPreviewController;
use App\Http\Controllers\Api\V1\Hr\StaffController;
use App\Http\Controllers\Api\V1\Hr\StaffInvitationAcceptController;
use App\Http\Controllers\Api\V1\Hr\StaffInvitationController;
use App\Http\Controllers\Api\V1\Merchant\MerchantDashboardController;
use App\Http\Controllers\Api\V1\Onboarding\FirstTimeSetupController;
use App\Http\Controllers\Api\V1\Onboarding\MerchantRegistrationController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuditLogController;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\EnsureFirstTimeSetupAccess;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureMerchantActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\RequireFreshMfa;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
 | API v1 routes (prefix /api/v1, registered in bootstrap/app.php).
 |
 | Phase 5 added authentication (Plan §9). Phase 6 adds the account & tenant
 | model: merchant self-registration, first-time setup, tenant context, and the
 | merchant dashboard shell. The broader versioned resource surface (Plan §11.2)
 | is still built in Phase 10.
 */

Route::middleware('throttle:api')->group(function (): void {
    // Phase 10 adds versioned resource routes here.
});

/*
 | Authentication (Plan §9.1–§9.3). Public endpoints carry only their named
 | Magic Link limiters — NOT the per-user `api` limiter — because the caller is
 | unauthenticated. Authenticated endpoints require the Sanctum session guard.
 */
Route::prefix('auth')->group(function (): void {
    Route::post('magic-link', [MagicLinkController::class, 'request'])
        ->middleware('throttle:magic-link-request')
        ->name('auth.magic-link.request');

    Route::post('magic-link/verify', [MagicLinkController::class, 'verify'])
        ->middleware('throttle:magic-link-verify')
        ->name('auth.magic-link.verify');

    Route::post('logout', [MagicLinkController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    /*
     | MFA enrollment / challenge (Plan §17, §18; Phase R3). Authenticated but
     | identity-level — no ResolveTenantContext here (MFA is resolved before
     | tenant context). EnsurePrivilegedMfa runs after auth (proving order) and
     | allowlists these bootstrap/recovery routes so an enrolling/challenging
     | mandatory user can reach them. Confirm/challenge are rate-limited.
     */
    Route::prefix('mfa')
        ->middleware(['auth:sanctum', EnforceIdleTimeout::class, EnsurePrivilegedMfa::class])
        ->group(function (): void {
            Route::get('/', [MfaController::class, 'status'])->name('auth.mfa.status');

            Route::post('enroll', [MfaController::class, 'enroll'])
                ->middleware('throttle:mfa-confirm')
                ->name('auth.mfa.enroll');

            Route::post('confirm', [MfaController::class, 'confirm'])
                ->middleware('throttle:mfa-confirm')
                ->name('auth.mfa.confirm');

            Route::post('challenge', [MfaController::class, 'challenge'])
                ->middleware('throttle:mfa-challenge')
                ->name('auth.mfa.challenge');

            Route::post('recovery-challenge', [MfaController::class, 'recoveryChallenge'])
                ->middleware('throttle:mfa-challenge')
                ->name('auth.mfa.recovery-challenge');

            // Recovery-code regeneration is a sensitive MFA self-management
            // action: it requires a confirmed credential (not allowlisted, so a
            // session assertion is enforced) AND a *fresh* step-up.
            Route::post('recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])
                ->middleware([
                    'throttle:mfa-confirm',
                    RequireFreshMfa::class.':'.StepUpAction::RecoveryCodeRegeneration->value,
                ])
                ->name('auth.mfa.recovery-codes.regenerate');
        });
});

/*
 | Merchant Administrator self-registration (Scope §3.1/§3.2). PUBLIC — the user
 | has no account yet. Rate-limited by the named `registration` limiter. There is
 | NO platform / Super Admin merchant-creation route anywhere (Scope §3.1).
 */
Route::prefix('merchant-registration')->group(function (): void {
    Route::post('self-register', [MerchantRegistrationController::class, 'selfRegister'])
        ->middleware('throttle:registration')
        ->name('merchant-registration.self-register');
});

/*
 | Staff invitation acceptance (Scope §3.4). PUBLIC — the invitee has no session
 | yet; the raw token from the email link is the credential. Rate-limited by the
 | named `invitation-accept` limiter.
 */
Route::post('staff-invitations/accept', [StaffInvitationAcceptController::class, 'store'])
    ->middleware('throttle:invitation-accept')
    ->name('staff-invitations.accept');

/*
 | Authenticated surface. ResolveTenantContext binds the per-request tenant
 | context after auth:sanctum so /me, setup, and the dashboard read a consistent
 | view. Per-route gates (EnsureFirstTimeSetupAccess / EnsureMerchantActive) are
 | the security boundary for setup vs. operational access (Plan §8.1).
 */
Route::middleware(['auth:sanctum', EnforceIdleTimeout::class, 'throttle:api', EnsurePrivilegedMfa::class, ResolveTenantContext::class])
    ->group(function (): void {
        Route::get('me', [MeController::class, 'show'])->name('me');

        // First-time setup — pending_setup + merchant_admin only.
        Route::middleware(EnsureFirstTimeSetupAccess::class)
            ->prefix('merchant-registration')
            ->group(function (): void {
                Route::get('first-time-setup', [FirstTimeSetupController::class, 'show'])
                    ->name('merchant-registration.first-time-setup.show');
                Route::post('first-time-setup', [FirstTimeSetupController::class, 'store'])
                    ->name('merchant-registration.first-time-setup.store');
            });

        // Operational surface — active merchant only (Plan §8.1).
        Route::middleware(EnsureMerchantActive::class)->group(function (): void {
            Route::get('merchant/dashboard', [MerchantDashboardController::class, 'show'])
                ->name('merchant.dashboard');

            // Branches (Scope §3.3, Plan §10.3). Index/show are scoped reads.
            // Mutating routes carry EnsurePermission (the backend authorization
            // boundary): branches.create for create/archive, branch.profile.manage
            // for profile/hours, day.open_close for day open/close. Per-branch
            // routes also carry EnsureBranchScope (foreign branch ULID → 404).
            Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
            Route::post('branches', [BranchController::class, 'store'])
                ->middleware(EnsurePermission::class.':branches.create')
                ->name('branches.store');

            Route::middleware(EnsureBranchScope::class)->group(function (): void {
                Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
                Route::patch('branches/{branch}', [BranchController::class, 'update'])
                    ->middleware(EnsurePermission::class.':branch.profile.manage')
                    ->name('branches.update');
                Route::post('branches/{branch}/archive', [BranchController::class, 'archive'])
                    ->middleware(EnsurePermission::class.':branches.create')
                    ->name('branches.archive');

                Route::get('branches/{branch}/operating-hours', [BranchOperatingHoursController::class, 'show'])
                    ->name('branches.operating-hours.show');
                Route::put('branches/{branch}/operating-hours', [BranchOperatingHoursController::class, 'update'])
                    ->middleware(EnsurePermission::class.':branch.profile.manage')
                    ->name('branches.operating-hours.update');

                Route::post('branches/{branch}/day/open', [BranchDayController::class, 'open'])
                    ->middleware(EnsurePermission::class.':day.open_close')
                    ->name('branches.day.open');
                Route::post('branches/{branch}/day/close', [BranchDayController::class, 'close'])
                    ->middleware(EnsurePermission::class.':day.open_close')
                    ->name('branches.day.close');
            });

            // Staff invitations (Scope §3.2/§3.4). Authority is StaffInvitationPolicy
            // (capability) + §3.2/§3.4 target-role boundary in the controller.
            Route::get('staff-invitations', [StaffInvitationController::class, 'index'])->name('staff-invitations.index');
            Route::post('staff-invitations', [StaffInvitationController::class, 'store'])->name('staff-invitations.store');
            Route::post('staff-invitations/{invitation}/resend', [StaffInvitationController::class, 'resend'])
                ->name('staff-invitations.resend');
            Route::post('staff-invitations/{invitation}/revoke', [StaffInvitationController::class, 'revoke'])
                ->name('staff-invitations.revoke');

            // Staff roster + lifecycle (Scope §3.4). Authority is StaffProfilePolicy.
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
            Route::post('staff/{staff}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
            Route::post('staff/{staff}/activate', [StaffController::class, 'activate'])->name('staff.activate');
            Route::post('staff/{staff}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');

            // Staff permission overrides + HR permission preview (Plan §10.3).
            // Managed by Merchant Admin (merchant-wide) or HR (own-branch
            // operational staff); changes are audited; self-escalation is denied.
            Route::get('staff/{staff}/permissions', [PermissionPreviewController::class, 'show'])
                ->name('staff.permissions.show');
            Route::post('staff/{staff}/permissions', [PermissionOverrideController::class, 'store'])
                ->name('staff.permissions.store');
            Route::delete('staff/{staff}/permissions/{permission}', [PermissionOverrideController::class, 'destroy'])
                ->name('staff.permissions.destroy');

            // HR permission preview (Plan §10.3): what a target role/user would
            // hold. Branch- and merchant-scoped; never enables self-escalation.
            Route::get('hr/permission-preview', [PermissionPreviewController::class, 'preview'])
                ->name('hr.permission-preview');

            // Merchant audit-log reads (Scope §4.8, Plan §70). READ-ONLY, masked,
            // merchant-scoped (branch-scoped for the Audit role via the policy).
            // `audit.view_full` is the backend authorization boundary.
            Route::middleware(EnsurePermission::class.':audit.view_full')->group(function (): void {
                Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
                Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
            });
        });

        // Platform / governance audit reads (Scope §4.8, Plan §70). OUTSIDE the
        // merchant gate — platform staff have no merchant. READ-ONLY, masked,
        // platform-chain only (merchant_id null); `platform.audit.view` required.
        Route::middleware(EnsurePermission::class.':platform.audit.view')
            ->prefix('platform')
            ->group(function (): void {
                Route::get('audit-logs', [PlatformAuditLogController::class, 'index'])->name('platform.audit-logs.index');
                Route::get('audit-logs/{auditLog}', [PlatformAuditLogController::class, 'show'])->name('platform.audit-logs.show');
            });
    });

/*
 | Test-only security harness (Plan §18 / Phase R3). NEVER registered outside the
 | `testing` environment, so no fake business route ships. The designated
 | business step-up routes are owned by their feature phases (see StepUpAction);
 | here we only exercise the REUSABLE controls:
 |   - `testing/privileged-probe` — a non-allowlisted authenticated route proving
 |     EnsurePrivilegedMfa blocks mandatory roles and passes non-mandatory roles.
 |   - `testing/step-up/{action}` — one route per designated business action,
 |     proving RequireFreshMfa denies a missing/stale assertion and passes a
 |     fresh one, for every central classification.
 */
if (app()->environment('testing')) {
    Route::middleware(['auth:sanctum', EnforceIdleTimeout::class, EnsurePrivilegedMfa::class, ResolveTenantContext::class])
        ->get('testing/privileged-probe', fn () => response()->json(['ok' => true]))
        ->name('testing.privileged-probe');

    Route::prefix('testing/step-up')
        ->middleware('auth:sanctum')
        ->group(function (): void {
            foreach (StepUpAction::businessActions() as $action) {
                Route::post($action->value, fn () => response()->json(['ok' => true, 'action' => $action->value]))
                    ->middleware(RequireFreshMfa::class.':'.$action->value)
                    ->name('testing.step-up.'.$action->value);
            }
        });

    /*
     | Idempotency harness (Plan §24.4 / Phase R4). These are `financial_mutation`-
     | classified so FinancialRouteIdempotencyCoverageTest verifies they carry the
     | idempotency middleware — proving the reusable control on real routes without
     | shipping any production financial route. The counter side effect (array
     | cache, per test process) lets a test assert "exactly one effect".
     */
    $financialClass = [RouteClassification::KEY => RouteClass::FinancialMutation->value];
    $idempotent = EnsureIdempotentRequest::class.':'.EnsureIdempotentRequest::RETENTION_RETRIABLE;

    Route::prefix('testing/idempotency')
        ->middleware(['auth:sanctum', ResolveTenantContext::class, $idempotent])
        ->group(function () use ($financialClass): void {
            // One-effect counter: returns the post-increment count.
            Route::post('financial', function (): JsonResponse {
                return response()->json(['count' => Cache::increment('idem_test_effect')]);
            })->defaults(RouteClassification::KEY, $financialClass[RouteClassification::KEY])
                ->name('testing.idempotency.financial');

            // Always a stable 422 — deterministic 4xx replay.
            Route::post('stable-failure', fn () => response()->json([
                'error' => ['code' => 'demo_validation', 'message' => 'stable', 'fields' => (object) [], 'meta' => (object) []],
            ], 422))->defaults(RouteClassification::KEY, $financialClass[RouteClassification::KEY])
                ->name('testing.idempotency.stable-failure');

            // Server failure — stored as a redacted, retryable failure.
            Route::post('boom', function (): void {
                throw new RuntimeException('boom secret detail should never be stored');
            })->defaults(RouteClassification::KEY, $financialClass[RouteClassification::KEY])
                ->name('testing.idempotency.boom');

            // Sets unsafe headers that must never be stored/replayed.
            Route::post('unsafe-headers', function (): JsonResponse {
                return response()->json(['count' => Cache::increment('idem_unsafe_effect')])
                    ->withHeaders([
                        'Set-Cookie' => 'session=secretcookievalue; Path=/',
                        'Authorization' => 'Bearer secret-token',
                        'X-XSRF-TOKEN' => 'csrf-secret',
                        'Server' => 'nginx-internal',
                    ]);
            })->defaults(RouteClassification::KEY, $financialClass[RouteClassification::KEY])
                ->name('testing.idempotency.unsafe-headers');
        });

    // A financial route DELIBERATELY left without idempotency middleware is NOT
    // registered (it would make the coverage test fail). The coverage test proves
    // detection against a synthetic in-memory route instead.
}
