<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\StepUpAction;
use App\Http\Controllers\Api\V1\Audit\AuditExportController;
use App\Http\Controllers\Api\V1\Audit\AuditFlaggedEventController;
use App\Http\Controllers\Api\V1\Audit\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\MagicLinkController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Billing\PlatformFeeDisputeController;
use App\Http\Controllers\Api\V1\Billing\PlatformFeeLedgerController;
use App\Http\Controllers\Api\V1\Branch\PreferredPersonnelFeeRuleReadController;
use App\Http\Controllers\Api\V1\Branches\BranchController;
use App\Http\Controllers\Api\V1\Branches\BranchDayController;
use App\Http\Controllers\Api\V1\Branches\BranchOperatingHoursController;
use App\Http\Controllers\Api\V1\CashUps\CashUpController;
use App\Http\Controllers\Api\V1\Catalogue\ServiceCategoryController;
use App\Http\Controllers\Api\V1\Catalogue\ServiceController;
use App\Http\Controllers\Api\V1\Catalogue\ServiceEligibilityController;
use App\Http\Controllers\Api\V1\Clients\ClientConsentController;
use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Files\FileController;
use App\Http\Controllers\Api\V1\FinanceDisputes\FinanceDisputeController;
use App\Http\Controllers\Api\V1\FinanceExports\FinanceExportController;
use App\Http\Controllers\Api\V1\Hr\PermissionOverrideController;
use App\Http\Controllers\Api\V1\Hr\PermissionPreviewController;
use App\Http\Controllers\Api\V1\Hr\StaffController;
use App\Http\Controllers\Api\V1\Hr\StaffInvitationAcceptController;
use App\Http\Controllers\Api\V1\Hr\StaffInvitationController;
use App\Http\Controllers\Api\V1\Invoicing\InvoiceAdjustmentController;
use App\Http\Controllers\Api\V1\Invoicing\InvoiceController;
use App\Http\Controllers\Api\V1\Invoicing\InvoiceVoidController;
use App\Http\Controllers\Api\V1\Merchant\MerchantDashboardController;
use App\Http\Controllers\Api\V1\Merchant\MerchantSubscriptionController;
use App\Http\Controllers\Api\V1\Merchant\SubscriptionInvoiceController;
use App\Http\Controllers\Api\V1\Onboarding\FirstTimeSetupController;
use App\Http\Controllers\Api\V1\Onboarding\MerchantRegistrationController;
use App\Http\Controllers\Api\V1\Payments\PaymentRecordController;
use App\Http\Controllers\Api\V1\Payments\PaymentRecordingGroupController;
use App\Http\Controllers\Api\V1\Payments\PaymentReferenceCheckController;
use App\Http\Controllers\Api\V1\PeriodLocks\FinancialPeriodLockController;
use App\Http\Controllers\Api\V1\Platform\FreePeriodOfferController;
use App\Http\Controllers\Api\V1\Platform\PlanEntitlementController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\PlatformBillingSettingsController;
use App\Http\Controllers\Api\V1\Platform\PlatformFeeConfigurationController;
use App\Http\Controllers\Api\V1\Platform\PlatformMerchantGovernanceController;
use App\Http\Controllers\Api\V1\Platform\PlatformSettingsController;
use App\Http\Controllers\Api\V1\Platform\PreferredPersonnelFeeRuleController;
use App\Http\Controllers\Api\V1\Platform\PromotionalDiscountController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionPlanPriceController;
use App\Http\Controllers\Api\V1\Receipts\ReceiptController;
use App\Http\Controllers\Api\V1\Refunds\RefundController;
use App\Http\Controllers\Api\V1\Scheduling\AppointmentController;
use App\Http\Controllers\Api\V1\Scheduling\PersonnelAppointmentController;
use App\Http\Controllers\Api\V1\Scheduling\PersonnelQueueController;
use App\Http\Controllers\Api\V1\Scheduling\PersonnelServiceSessionController;
use App\Http\Controllers\Api\V1\Scheduling\QueueConfigurationController;
use App\Http\Controllers\Api\V1\Scheduling\QueueController;
use App\Http\Controllers\Api\V1\Scheduling\ServiceSessionController;
use App\Http\Controllers\Api\V1\Scheduling\StaffAvailabilityController;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureActivePrincipal;
use App\Http\Middleware\EnsureBillingMutable;
use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\EnsureFirstTimeSetupAccess;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureMerchantActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\RequireFreshMfa;
use App\Http\Middleware\ResolvePlatformContext;
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
        ->defaults(RouteClassification::KEY, RouteClass::PublicMutation->value)
        ->name('auth.magic-link.request');

    Route::post('magic-link/verify', [MagicLinkController::class, 'verify'])
        ->middleware('throttle:magic-link-verify')
        ->defaults(RouteClassification::KEY, RouteClass::PublicMutation->value)
        ->name('auth.magic-link.verify');

    Route::post('logout', [MagicLinkController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
        ->name('auth.logout');

    /*
     | MFA enrollment / challenge (Plan §17, §18; Phase R3). Authenticated but
     | identity-level — no ResolveTenantContext here (MFA is resolved before
     | tenant context). EnsurePrivilegedMfa runs after auth (proving order) and
     | allowlists these bootstrap/recovery routes so an enrolling/challenging
     | mandatory user can reach them. Confirm/challenge are rate-limited.
     */
    Route::prefix('mfa')
        ->middleware(['auth:sanctum', EnforceIdleTimeout::class, EnsureActivePrincipal::class, EnsurePrivilegedMfa::class])
        ->group(function (): void {
            Route::get('/', [MfaController::class, 'status'])->name('auth.mfa.status');

            Route::post('enroll', [MfaController::class, 'enroll'])
                ->middleware('throttle:mfa-confirm')
                ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
                ->name('auth.mfa.enroll');

            Route::post('confirm', [MfaController::class, 'confirm'])
                ->middleware('throttle:mfa-confirm')
                ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
                ->name('auth.mfa.confirm');

            Route::post('challenge', [MfaController::class, 'challenge'])
                ->middleware('throttle:mfa-challenge')
                ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
                ->name('auth.mfa.challenge');

            Route::post('recovery-challenge', [MfaController::class, 'recoveryChallenge'])
                ->middleware('throttle:mfa-challenge')
                ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
                ->name('auth.mfa.recovery-challenge');

            // Recovery-code regeneration is a sensitive MFA self-management
            // action: it requires a confirmed credential (not allowlisted, so a
            // session assertion is enforced) AND a *fresh* step-up.
            Route::post('recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])
                ->middleware([
                    'throttle:mfa-confirm',
                    RequireFreshMfa::class.':'.StepUpAction::RecoveryCodeRegeneration->value,
                ])
                ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
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
        ->defaults(RouteClassification::KEY, RouteClass::PublicMutation->value)
        ->name('merchant-registration.self-register');
});

/*
 | Staff invitation acceptance (Scope §3.4). PUBLIC — the invitee has no session
 | yet; the raw token from the email link is the credential. Rate-limited by the
 | named `invitation-accept` limiter.
 */
Route::post('staff-invitations/accept', [StaffInvitationAcceptController::class, 'store'])
    ->middleware('throttle:invitation-accept')
    ->defaults(RouteClassification::KEY, RouteClass::PublicMutation->value)
    ->name('staff-invitations.accept');

/*
 | Authenticated surface. ResolveTenantContext binds the per-request tenant
 | context after auth:sanctum so /me, setup, and the dashboard read a consistent
 | view. Per-route gates (EnsureFirstTimeSetupAccess / EnsureMerchantActive) are
 | the security boundary for setup vs. operational access (Plan §8.1).
 */
Route::middleware(['auth:sanctum', EnforceIdleTimeout::class, EnsureActivePrincipal::class, 'throttle:api', EnsurePrivilegedMfa::class, ResolveTenantContext::class])
    ->group(function (): void {
        Route::get('me', [MeController::class, 'show'])->name('me');

        // First-time setup — pending_setup + merchant_admin only.
        Route::middleware(EnsureFirstTimeSetupAccess::class)
            ->prefix('merchant-registration')
            ->group(function (): void {
                Route::get('first-time-setup', [FirstTimeSetupController::class, 'show'])
                    ->name('merchant-registration.first-time-setup.show');
                Route::post('first-time-setup', [FirstTimeSetupController::class, 'store'])
                    ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                    ->name('merchant-registration.first-time-setup.store');
            });

        // Operational surface — active merchant only (Plan §8.1).
        Route::middleware(EnsureMerchantActive::class)->group(function (): void {
            Route::get('merchant/dashboard', [MerchantDashboardController::class, 'show'])
                ->name('merchant.dashboard');

            // Merchant subscription self-service (Plan §22, §48, §49; Phase 20B). Merchant
            // Administrator, merchant scope. Reads (subscription/dashboard, plan options with
            // effective prices, pending scheduled change, invoices) carry `merchant.subscription
            // .view` / `.invoice.view`. No-proration plan-change mutations carry `merchant
            // .subscription.plan_change` + EnsureBillingMutable (blocked in read_only_grace /
            // suspended_billing); effective_at is server-computed (the period end). NEW PDF
            // generation is a mutation blocked in billing read-only (EnsureBillingMutable + the
            // action's file-generation policy); the EXISTING-PDF download link is a read allowed
            // in billing read-only (never consults the billing gate). All bindings resolve inside
            // the merchant (BelongsToMerchant scope → foreign tenant 404). NO trial / activation /
            // issue / void / payment / Wallet route (Plan §48/§49; those are 20D-W / system-driven).
            Route::get('subscription', [MerchantSubscriptionController::class, 'show'])
                ->middleware(EnsurePermission::class.':merchant.subscription.view')
                ->name('subscription.show');
            Route::get('subscription/plans', [MerchantSubscriptionController::class, 'plans'])
                ->middleware(EnsurePermission::class.':merchant.subscription.view')
                ->name('subscription.plans.index');
            Route::get('subscription/scheduled-plan-change', [MerchantSubscriptionController::class, 'scheduledChange'])
                ->middleware(EnsurePermission::class.':merchant.subscription.view')
                ->name('subscription.scheduled-plan-change.show');
            Route::post('subscription/scheduled-plan-change', [MerchantSubscriptionController::class, 'scheduleChange'])
                ->middleware([EnsureBillingMutable::class, EnsurePermission::class.':merchant.subscription.plan_change'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('subscription.scheduled-plan-change.store');
            Route::post('subscription/scheduled-plan-change/cancel', [MerchantSubscriptionController::class, 'cancelScheduledChange'])
                ->middleware([EnsureBillingMutable::class, EnsurePermission::class.':merchant.subscription.plan_change'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('subscription.scheduled-plan-change.cancel');

            Route::get('subscription-invoices', [SubscriptionInvoiceController::class, 'index'])
                ->middleware(EnsurePermission::class.':merchant.subscription.invoice.view')
                ->name('subscription-invoices.index');
            Route::get('subscription-invoices/{subscriptionInvoice}', [SubscriptionInvoiceController::class, 'show'])
                ->middleware(EnsurePermission::class.':merchant.subscription.invoice.view')
                ->name('subscription-invoices.show');
            Route::post('subscription-invoices/{subscriptionInvoice}/pdf', [SubscriptionInvoiceController::class, 'generatePdf'])
                ->middleware([EnsureBillingMutable::class, EnsurePermission::class.':merchant.subscription.invoice.download'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('subscription-invoices.pdf.generate');
            Route::get('subscription-invoices/{subscriptionInvoice}/pdf/download-link', [SubscriptionInvoiceController::class, 'downloadLink'])
                ->middleware(EnsurePermission::class.':merchant.subscription.invoice.download')
                ->name('subscription-invoices.pdf.download-link');

            // Phase 20E — fresh step-up for a dispute resolution/rejection (Finance).
            $financeStepUp = RequireFreshMfa::class.':'.StepUpAction::PlatformFeeDisputeResolution->value;

            // Phase 20E — percentage platform-fee merchant surface (Plan §51; Correction 3). Masked
            // scoped reads (`platform_fee.view`; server-side merchant-wide vs branch-attributable);
            // the dispute workflow (create = `platform_fee.dispute`; review/resolve/reject =
            // `platform_fee.dispute.review`, fresh step-up on resolve/reject; resolve is a
            // financial_mutation with idempotency + period lock; a money change creates an additive
            // adjustment, never a ledger edit). NO reversal/adjustment/aggregation route (owned by
            // void/refund/dispute-resolution/issuance); NO generic status route; NO DELETE.
            Route::get('platform-fees', [PlatformFeeLedgerController::class, 'index'])
                ->middleware(EnsurePermission::class.':platform_fee.view')
                ->name('platform-fees.index');
            Route::get('platform-fees/summary', [PlatformFeeLedgerController::class, 'summary'])
                ->middleware(EnsurePermission::class.':platform_fee.view')
                ->name('platform-fees.summary');
            Route::get('platform-fees/{platformFeeLedgerEntry}', [PlatformFeeLedgerController::class, 'show'])
                ->middleware(EnsurePermission::class.':platform_fee.view')
                ->name('platform-fees.show');

            Route::get('platform-fee-disputes', [PlatformFeeDisputeController::class, 'index'])
                ->middleware(EnsurePermission::class.':platform_fee.view')
                ->name('platform-fee-disputes.index');
            Route::post('platform-fee-disputes', [PlatformFeeDisputeController::class, 'store'])
                ->middleware([EnsurePermission::class.':platform_fee.dispute', EnsureIdempotentRequest::class])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('platform-fee-disputes.store');
            Route::get('platform-fee-disputes/{platformFeeDispute}', [PlatformFeeDisputeController::class, 'show'])
                ->middleware(EnsurePermission::class.':platform_fee.view')
                ->name('platform-fee-disputes.show');
            Route::post('platform-fee-disputes/{platformFeeDispute}/review', [PlatformFeeDisputeController::class, 'review'])
                ->middleware(EnsurePermission::class.':platform_fee.dispute.review')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('platform-fee-disputes.review');
            Route::post('platform-fee-disputes/{platformFeeDispute}/resolve', [PlatformFeeDisputeController::class, 'resolve'])
                ->middleware([EnsurePermission::class.':platform_fee.dispute.review', $financeStepUp, EnsureIdempotentRequest::class])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('platform-fee-disputes.resolve');
            Route::post('platform-fee-disputes/{platformFeeDispute}/reject', [PlatformFeeDisputeController::class, 'reject'])
                ->middleware([EnsurePermission::class.':platform_fee.dispute.review', $financeStepUp])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('platform-fee-disputes.reject');

            // Branches (Scope §3.3, Plan §10.3). Index/show are scoped reads.
            // Mutating routes carry EnsurePermission (the backend authorization
            // boundary): branches.create for create/archive, branch.profile.manage
            // for profile/hours, day.open_close for day open/close. Per-branch
            // routes also carry EnsureBranchScope (foreign branch ULID → 404).
            Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
            Route::post('branches', [BranchController::class, 'store'])
                ->middleware(EnsurePermission::class.':branches.create')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('branches.store');

            Route::middleware(EnsureBranchScope::class)->group(function (): void {
                Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');

                // Phase 20A — Branch Manager read-only view of the EFFECTIVE preferred-personnel
                // fee rule (branch scope; no platform MFA/step-up). Management is Super-Admin only.
                Route::get('branch/preferred-personnel-fee-rule', [PreferredPersonnelFeeRuleReadController::class, 'show'])
                    ->middleware(EnsurePermission::class.':preferred_personnel_fee.view_branch_rule')
                    ->name('branch.preferred-personnel-fee-rule.show');
                Route::patch('branches/{branch}', [BranchController::class, 'update'])
                    ->middleware(EnsurePermission::class.':branch.profile.manage')
                    ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                    ->name('branches.update');
                Route::post('branches/{branch}/archive', [BranchController::class, 'archive'])
                    ->middleware(EnsurePermission::class.':branches.create')
                    ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                    ->name('branches.archive');

                Route::get('branches/{branch}/operating-hours', [BranchOperatingHoursController::class, 'show'])
                    ->name('branches.operating-hours.show');
                Route::put('branches/{branch}/operating-hours', [BranchOperatingHoursController::class, 'update'])
                    ->middleware(EnsurePermission::class.':branch.profile.manage')
                    ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                    ->name('branches.operating-hours.update');

                Route::post('branches/{branch}/day/open', [BranchDayController::class, 'open'])
                    ->middleware(EnsurePermission::class.':day.open_close')
                    ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                    ->name('branches.day.open');
                Route::post('branches/{branch}/day/close', [BranchDayController::class, 'close'])
                    ->middleware(EnsurePermission::class.':day.open_close')
                    ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                    ->name('branches.day.close');
            });

            // Staff invitations (Scope §3.2/§3.4). Authority is StaffInvitationPolicy
            // (capability) + §3.2/§3.4 target-role boundary in the controller.
            Route::get('staff-invitations', [StaffInvitationController::class, 'index'])->name('staff-invitations.index');
            Route::post('staff-invitations', [StaffInvitationController::class, 'store'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff-invitations.store');
            Route::post('staff-invitations/{invitation}/resend', [StaffInvitationController::class, 'resend'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff-invitations.resend');
            Route::post('staff-invitations/{invitation}/revoke', [StaffInvitationController::class, 'revoke'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff-invitations.revoke');

            // Staff roster + lifecycle (Scope §3.4). Authority is StaffProfilePolicy.
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
            Route::post('staff/{staff}/suspend', [StaffController::class, 'suspend'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff.suspend');
            Route::post('staff/{staff}/activate', [StaffController::class, 'activate'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff.activate');
            Route::post('staff/{staff}/deactivate', [StaffController::class, 'deactivate'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff.deactivate');

            // Staff permission overrides + HR permission preview (Plan §10.3).
            // Managed by Merchant Admin (merchant-wide) or HR (own-branch
            // operational staff); changes are audited; self-escalation is denied.
            Route::get('staff/{staff}/permissions', [PermissionPreviewController::class, 'show'])
                ->name('staff.permissions.show');
            Route::post('staff/{staff}/permissions', [PermissionOverrideController::class, 'store'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff.permissions.store');
            Route::delete('staff/{staff}/permissions/{permission}', [PermissionOverrideController::class, 'destroy'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('staff.permissions.destroy');

            // HR permission preview (Plan §10.3): what a target role/user would
            // hold. Branch- and merchant-scoped; never enables self-escalation.
            Route::get('hr/permission-preview', [PermissionPreviewController::class, 'preview'])
                ->name('hr.permission-preview');

            // Service catalogue (Scope §catalogue, Plan §39; Phase 15A). Branch
            // Manager owns it (`service.*`). Reads authorize `service.view` in the
            // controller (ServicePolicy); mutations carry EnsurePermission + are
            // classified branch_mutation (EnsureBranchScope no-ops without a
            // {branch} param but the class contract requires it). The legacy
            // preferred-personnel fee is never exposed or editable.
            Route::get('service-categories', [ServiceCategoryController::class, 'index'])->name('service-categories.index');
            Route::post('service-categories', [ServiceCategoryController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('service-categories.store');
            Route::patch('service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service.update'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('service-categories.update');

            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
            Route::post('services', [ServiceController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('services.store');
            Route::patch('services/{service}', [ServiceController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service.update'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('services.update');
            Route::post('services/{service}/archive', [ServiceController::class, 'archive'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service.archive'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('services.archive');

            // Personnel-service eligibility (Plan §19.3, §39; Phase 15A). HR owns
            // mutation (`personnel.eligibility.manage`); reads also serve the Branch
            // Manager catalogue's read-only summary (authorized in the controller).
            Route::get('services/{service}/eligibility', [ServiceEligibilityController::class, 'index'])
                ->name('services.eligibility.index');
            Route::post('services/{service}/eligibility', [ServiceEligibilityController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':personnel.eligibility.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('services.eligibility.store');
            Route::delete('services/{service}/eligibility/{staff}', [ServiceEligibilityController::class, 'destroy'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':personnel.eligibility.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('services.eligibility.destroy');

            // Personnel availability (Plan §13.7, §80 Phase 15B). HR owns mutation
            // (`personnel.availability.manage`, route-gated + policy); the read also
            // serves the Branch Manager's read-only schedule visibility (authorized in
            // the controller via PersonnelAvailabilityPolicy + `branch.dashboard.view`).
            // The `{staff}` binding resolves StaffProfile inside tenant + branch scope
            // (foreign tenant 404; same-tenant out-of-branch 404 via BranchScope). The
            // schedule is atomically replaced. EnsureBranchScope no-ops without a
            // {branch} param but the branch_mutation class contract requires it.
            Route::get('staff/{staff}/availability', [StaffAvailabilityController::class, 'show'])
                ->name('staff.availability.show');
            Route::put('staff/{staff}/availability', [StaffAvailabilityController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':personnel.availability.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('staff.availability.update');
            Route::post('staff/{staff}/availability/emergency-unavailable', [StaffAvailabilityController::class, 'emergencyUnavailable'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':personnel.availability.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('staff.availability.emergency-unavailable');

            // Client records (Scope §clients, Plan §35; Phase 15A). Front Office owns
            // them (`client.*`); search is a distinct capability (`front_office.search`,
            // enforced in the controller). Contact is ALWAYS masked; the blind index
            // is never returned. Reads authorize `client.view` (ClientPolicy).
            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::post('clients', [ClientController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':client.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('clients.store');
            Route::patch('clients/{client}', [ClientController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':client.update'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('clients.update');
            Route::put('clients/{client}/sms-consent', [ClientConsentController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':client.update'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('clients.sms-consent.update');

            // Appointments (Scope §, Plan §36; Phase 16A). Front Office owns all
            // mutations (`appointment.*`, branch_mutation); Branch Manager has
            // branch-scoped read-only visibility via `branch.dashboard.view`
            // (authorized in the controller via AppointmentPolicy). The
            // `{appointment}` binding resolves the ULID inside tenant + branch scope
            // (foreign tenant 404; same-tenant out-of-branch follows BranchScope).
            // No-show is authorized through `appointment.cancel` (no separate key).
            // Walk-ins/queue/sessions are later phases (16B/16C) — not here.
            Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
            Route::post('appointments', [AppointmentController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.store');
            Route::post('appointments/{appointment}/assign', [AppointmentController::class, 'assign'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.assign'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.assign');
            Route::post('appointments/{appointment}/transfer', [AppointmentController::class, 'transfer'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.transfer'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.transfer');
            Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.reschedule'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.reschedule');
            Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.cancel'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.cancel');
            Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.check_in'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.check-in');
            Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':appointment.cancel'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.no-show');

            // Personnel own-scope appointments (Plan §36, §19.3; Phase 16A). Read-only;
            // the own-scope restriction (own staff profile) + permission
            // (`personnel.my_appointments.view`) are enforced in the controller.
            Route::get('personnel/me/appointments', [PersonnelAppointmentController::class, 'index'])
                ->name('personnel.appointments.index');

            // Walk-ins & queues (Scope §, Plan §37; Phase 16B). Front Office owns all
            // operational queue work (`queue.view/create/assign/transfer/reorder`);
            // the call/start/complete/cancel/no-show lifecycle is authorised through
            // `queue.assign` (no separate keys). Branch Manager has branch-scoped
            // read-only visibility via `branch.dashboard.view` and configures the
            // queue (open/close, capacity, default mode) on the Branch Day via
            // `branch.profile.manage` + `day.open_close` — never operating entries.
            // Personnel see ONLY their own assigned queue (`personnel.my_queue.view`).
            // The static reorder route is declared BEFORE the parameterized routes.
            Route::get('queue/configuration', [QueueConfigurationController::class, 'show'])
                ->name('queue.configuration.show');
            Route::put('queue/configuration', [QueueConfigurationController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':branch.profile.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.configuration.update');

            Route::put('queue-entries/reorder', [QueueController::class, 'reorder'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.reorder'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.reorder');

            Route::get('queue-entries', [QueueController::class, 'index'])->name('queue.index');
            Route::get('queue-entries/{queueEntry}', [QueueController::class, 'show'])->name('queue.show');

            Route::post('walk-ins', [QueueController::class, 'storeWalkIn'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('walk-ins.store');
            Route::post('appointments/{appointment}/queue', [QueueController::class, 'convertAppointment'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('appointments.queue.store');

            Route::post('queue-entries/{queueEntry}/assign', [QueueController::class, 'assign'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.assign');
            Route::post('queue-entries/{queueEntry}/call', [QueueController::class, 'call'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.call');
            // Phase 16C: queue start/complete are the service-session orchestration
            // routes — they additionally require the canonical session permission.
            Route::post('queue-entries/{queueEntry}/start', [QueueController::class, 'start'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign', EnsurePermission::class.':service_session.start'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.start');
            Route::post('queue-entries/{queueEntry}/complete', [QueueController::class, 'complete'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign', EnsurePermission::class.':service_session.complete'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.complete');
            Route::post('queue-entries/{queueEntry}/transfer', [QueueController::class, 'transfer'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.transfer'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.transfer');
            Route::post('queue-entries/{queueEntry}/cancel', [QueueController::class, 'cancel'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.cancel');
            Route::post('queue-entries/{queueEntry}/no-show', [QueueController::class, 'noShow'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':queue.assign'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('queue.no-show');

            // Personnel own-scope queue (Plan §37, §19; Phase 16B). Read-only;
            // own-scope (own staff profile) + `personnel.my_queue.view` enforced in
            // the controller.
            Route::get('personnel/me/queue', [PersonnelQueueController::class, 'index'])
                ->name('personnel.queue.index');

            // Service sessions (Plan §25.2, §37; Phase 16C). Front Office owns the
            // operational session lifecycle. Start + complete are driven by the queue
            // orchestration routes above; these own list/detail, cancellation, and
            // service-notes editing. Reads are branch-scoped; client contact is masked.
            Route::get('service-sessions', [ServiceSessionController::class, 'index'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service_session.view'])
                ->name('service-sessions.index');
            Route::get('service-sessions/{serviceSession}', [ServiceSessionController::class, 'show'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service_session.view'])
                ->name('service-sessions.show');
            Route::post('service-sessions/{serviceSession}/cancel', [ServiceSessionController::class, 'cancel'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service_session.cancel'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('service-sessions.cancel');
            Route::patch('service-sessions/{serviceSession}/notes', [ServiceSessionController::class, 'updateNotes'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':service_session.complete'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('service-sessions.notes');

            // Personnel own-scope sessions (Plan §25.2, §19; Phase 16C). Read-only;
            // own-scope (own staff profile) + `personnel.my_sessions.view` enforced in
            // the controller.
            Route::get('personnel/me/sessions', [PersonnelServiceSessionController::class, 'index'])
                ->name('personnel.sessions.index');

            // Invoices (Plan §40, §25.3; Phase 17). Front Office owns invoice.view +
            // invoice.create (list/detail/draft/finalize); Finance owns the void/adjust
            // workflow (invoice.void.request_or_execute_as_policy + invoice.adjustment
            // .manage). Reads are branch-scoped; client contact is masked. Finalization
            // is a financial_mutation (idempotency-keyed). Void request/execute require a
            // fresh step-up (StepUpAction::InvoiceVoid); Finance MFA is enforced on the
            // group. Branch Manager/Merchant Admin/HR/Personnel/Audit/Super Admin hold no
            // invoice key. No DELETE / PATCH-status / mark-paid / payment / receipt route.
            $invoiceIdempotent = EnsureIdempotentRequest::class.':'.EnsureIdempotentRequest::RETENTION_RETRIABLE;
            $invoiceVoidStepUp = RequireFreshMfa::class.':'.StepUpAction::InvoiceVoid->value;

            Route::get('invoices', [InvoiceController::class, 'index'])
                ->middleware(EnsurePermission::class.':invoice.view')
                ->name('invoices.index');
            Route::post('invoices', [InvoiceController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.store');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
                ->middleware(EnsurePermission::class.':invoice.view')
                ->name('invoices.show');
            Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.update');
            Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.create', $invoiceIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('invoices.finalize');

            Route::post('invoices/{invoice}/void', [InvoiceVoidController::class, 'request'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.void.request_or_execute_as_policy', $invoiceVoidStepUp])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.void');
            Route::post('invoices/{invoice}/void/execute', [InvoiceVoidController::class, 'execute'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.void.request_or_execute_as_policy', $invoiceVoidStepUp])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.void.execute');
            Route::post('invoices/{invoice}/void/reject', [InvoiceVoidController::class, 'reject'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.void.request_or_execute_as_policy'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.void.reject');

            Route::post('invoices/{invoice}/adjust', [InvoiceAdjustmentController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':invoice.adjustment.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('invoices.adjust');

            // Merchant-client payments (Plan §41; Phase 18A). Front Office is the default
            // MAKER: it records a payment group (single/split) against an issued or
            // partially-paid invoice (customer_payment.record). Finance reads the pending
            // groups (customer_payment.view), overrides a suspected duplicate
            // (customer_payment.duplicate_override — MFA + fresh step-up), and may record
            // as a distinct maker exception (customer_payment.record_exception). Recording
            // + override are financial_mutation (idempotency-keyed). NO validate/reject/
            // reference-correct/receipt/refund/cash-up/status/delete route exists (Phase
            // 18B+). A suspected duplicate returns 409 payment_reference_duplicate_suspected.
            $paymentIdempotent = EnsureIdempotentRequest::class.':'.EnsureIdempotentRequest::RETENTION_RETRIABLE;
            $paymentOverrideStepUp = RequireFreshMfa::class.':'.StepUpAction::PaymentDuplicateOverride->value;

            Route::post('invoices/{invoice}/payment-recording-groups', [PaymentRecordingGroupController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.record', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.store');
            Route::post('invoices/{invoice}/payment-recording-groups/exception', [PaymentRecordingGroupController::class, 'storeException'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.record_exception', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.exception');
            Route::get('payment-recording-groups', [PaymentRecordingGroupController::class, 'index'])
                ->middleware(EnsurePermission::class.':customer_payment.view')
                ->name('payment-recording-groups.index');
            Route::get('payment-recording-groups/{paymentRecordingGroup}', [PaymentRecordingGroupController::class, 'show'])
                ->middleware(EnsurePermission::class.':customer_payment.view')
                ->name('payment-recording-groups.show');
            // Phase 18B — Finance checker validates a WHOLE pending group (maker != checker
            // enforced in the action). financial_mutation → R4 idempotency; period-lock
            // enforced in the action. One validated group → one immutable event + one
            // gap-free original receipt (PDF generated by an outbox job after commit).
            Route::post('payment-recording-groups/{paymentRecordingGroup}/validate', [PaymentRecordingGroupController::class, 'validateGroup'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.validate', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.validate');
            // Phase 18B — whole-group rejection / correction request (Finance checker;
            // mandatory reason; no receipt; invoice untouched). financial_mutation.
            Route::post('payment-recording-groups/{paymentRecordingGroup}/reject', [PaymentRecordingGroupController::class, 'reject'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.reject', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.reject');
            Route::post('payment-recording-groups/{paymentRecordingGroup}/request-correction', [PaymentRecordingGroupController::class, 'requestCorrection'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.reject', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.request-correction');
            Route::post('payment-recording-groups/{paymentRecordingGroup}/resubmit', [PaymentRecordingGroupController::class, 'resubmit'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.reference_correct', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-recording-groups.resubmit');
            // Phase 18B — component reference correction on a correctable group.
            Route::post('payment-records/{paymentRecord}/correct-reference', [PaymentRecordController::class, 'correctReference'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.reference_correct', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-records.correct-reference');

            // Receipts (Plan §43; Gate J; Phase 18B). Issued AUTOMATICALLY on validation
            // (no manual issue route). receipt.view reads + issues authorized download
            // links through the Phase 10F file boundary (authorization re-checked at link
            // issuance AND at the byte stream); receipt.reissue (Finance) creates a new
            // immutable row + new gap-free number referencing the immutable original.
            Route::get('receipts', [ReceiptController::class, 'index'])
                ->middleware(EnsurePermission::class.':receipt.view')
                ->name('receipts.index');
            Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])
                ->middleware(EnsurePermission::class.':receipt.view')
                ->name('receipts.show');
            Route::post('receipts/{receipt}/reissue', [ReceiptController::class, 'reissue'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':receipt.reissue', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('receipts.reissue');
            Route::post('receipts/{receipt}/download-link', [ReceiptController::class, 'downloadLink'])
                ->middleware(EnsurePermission::class.':receipt.view')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('receipts.download-link');

            // Refunds (Plan §44; Gate D/E; Phase 18B). EXTERNAL refunds (Servana never
            // moves funds). Maker (refund.create) requests; a DISTINCT Finance membership
            // approves (refund.approve, fresh step-up) + finalizes (refund.finalize, fresh
            // step-up). Every mutation is financial_mutation (idempotency) + period-gated
            // in the action; the actor guard enforces requester != approver != finalizer.
            $refundApprovalStepUp = RequireFreshMfa::class.':'.StepUpAction::RefundApproval->value;
            $refundFinalizeStepUp = RequireFreshMfa::class.':'.StepUpAction::RefundFinalization->value;
            Route::get('refunds', [RefundController::class, 'index'])
                ->middleware(EnsurePermission::class.':refund.create')
                ->name('refunds.index');
            Route::post('refunds', [RefundController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':refund.create', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('refunds.store');
            Route::get('refunds/{refund}', [RefundController::class, 'show'])
                ->middleware(EnsurePermission::class.':refund.create')
                ->name('refunds.show');
            Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':refund.approve', $refundApprovalStepUp, $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('refunds.approve');
            Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':refund.approve', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('refunds.reject');
            Route::post('refunds/{refund}/finalize', [RefundController::class, 'finalize'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':refund.finalize', $refundFinalizeStepUp, $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('refunds.finalize');

            // Finance disputes (Plan §44; Phase 18B). Finance-only investigation over an
            // invoice and/or payment record; the disputed source record is never mutated.
            // finance_dispute.manage is PL n/a (no period lock); disputes touch no money,
            // so they are branch mutations, not financial mutations.
            Route::get('finance-disputes', [FinanceDisputeController::class, 'index'])
                ->middleware(EnsurePermission::class.':finance_dispute.manage')
                ->name('finance-disputes.index');
            Route::post('finance-disputes', [FinanceDisputeController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':finance_dispute.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('finance-disputes.store');
            Route::get('finance-disputes/{financeDispute}', [FinanceDisputeController::class, 'show'])
                ->middleware(EnsurePermission::class.':finance_dispute.manage')
                ->name('finance-disputes.show');
            Route::post('finance-disputes/{financeDispute}/start-review', [FinanceDisputeController::class, 'startReview'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':finance_dispute.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('finance-disputes.start-review');
            Route::post('finance-disputes/{financeDispute}/resolve', [FinanceDisputeController::class, 'resolve'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':finance_dispute.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('finance-disputes.resolve');
            Route::post('finance-disputes/{financeDispute}/reject', [FinanceDisputeController::class, 'reject'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':finance_dispute.manage'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('finance-disputes.reject');
            // Branch cash-up + day-close reconciliation (Plan §45; ADR-0007; Phase 18B).
            // Maker = Branch Manager (branch.cash_up.submit: draft/update/submit/resubmit);
            // checker = Finance (cash_up.approve/reject/request_correction/lock; cash_up.view
            // reads). Expected totals are server-derived (Gate H); the submitted/approved
            // snapshot is never destructively overwritten. Every state change is period-lock
            // -gated in the action (→ 423) and financial_mutation (idempotency-keyed). Reads
            // are authorized by CashUpPolicy (Finance OR own-branch Branch Manager). No PDF /
            // email (Phase 21N); no generic status endpoint.
            Route::get('cash-ups', [CashUpController::class, 'index'])
                ->middleware(EnsurePermission::class.':cash_up.view')
                ->name('cash-ups.index');
            Route::get('branches/{branch}/cash-ups/{date}', [CashUpController::class, 'branchDay'])
                ->where('date', '\d{4}-\d{2}-\d{2}')
                ->middleware(EnsureBranchScope::class)
                ->name('cash-ups.branch-day');
            Route::put('branches/{branch}/cash-ups/{date}', [CashUpController::class, 'upsertDraft'])
                ->where('date', '\d{4}-\d{2}-\d{2}')
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':branch.cash_up.submit'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('cash-ups.draft');
            Route::get('cash-ups/{cashUp}', [CashUpController::class, 'show'])
                ->name('cash-ups.show');
            Route::post('cash-ups/{cashUp}/submit', [CashUpController::class, 'submit'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':branch.cash_up.submit', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.submit');
            Route::post('cash-ups/{cashUp}/resubmit', [CashUpController::class, 'resubmit'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':branch.cash_up.submit', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.resubmit');
            Route::post('cash-ups/{cashUp}/approve', [CashUpController::class, 'approve'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':cash_up.approve', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.approve');
            Route::post('cash-ups/{cashUp}/lock', [CashUpController::class, 'lock'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':cash_up.approve', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.lock');
            Route::post('cash-ups/{cashUp}/reject', [CashUpController::class, 'reject'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':cash_up.reject', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.reject');
            Route::post('cash-ups/{cashUp}/request-correction', [CashUpController::class, 'requestCorrection'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':cash_up.request_correction', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('cash-ups.request-correction');

            // Financial period locks + controlled reopen (Plan §46; ADR-0007; Phase 18B).
            // Finance owns lock creation (period_lock.create) + reopen execution
            // (period_lock.reopen, fresh MFA); a Merchant Administrator approves an
            // exceptional reopen only (merchant.period_reopen.approve_exception,
            // period_lock.reopen ⟂ approve_exception). Merchant-wide (branch null) or
            // branch scope. Every mutation is financial_mutation (idempotency-keyed).
            // Reads are authorized by FinancialPeriodLockPolicy and are never period-locked.
            $periodReopenStepUp = RequireFreshMfa::class.':'.StepUpAction::PeriodReopen->value;
            Route::get('period-locks', [FinancialPeriodLockController::class, 'index'])
                ->name('period-locks.index');
            Route::post('period-locks', [FinancialPeriodLockController::class, 'store'])
                ->middleware([EnsurePermission::class.':period_lock.create', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('period-locks.store');
            Route::get('period-locks/{periodLock}', [FinancialPeriodLockController::class, 'show'])
                ->name('period-locks.show');
            Route::post('period-locks/{periodLock}/reopen', [FinancialPeriodLockController::class, 'requestReopen'])
                ->middleware([EnsurePermission::class.':period_lock.reopen', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('period-locks.reopen');
            Route::post('period-locks/{periodLock}/reopen/approve', [FinancialPeriodLockController::class, 'approveException'])
                ->middleware([EnsurePermission::class.':merchant.period_reopen.approve_exception', $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('period-locks.reopen.approve');
            Route::post('period-locks/{periodLock}/reopen/execute', [FinancialPeriodLockController::class, 'execute'])
                ->middleware([EnsurePermission::class.':period_lock.reopen', $periodReopenStepUp, $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('period-locks.reopen.execute');

            // Finance exports (Plan §65, §67; Gate I; Phase 18B). Finance requests a scoped,
            // masked export (finance_export.create + fresh step-up) generated async on
            // reports-exports, then downloads it via an authorized signed Phase 10F link
            // (finance_export.download). Reads are authorized by FinanceExportPolicy.
            // finance_export.* is PL n/a (never period-locked). No export contents/path/
            // signature is ever returned or logged.
            $financeExportStepUp = RequireFreshMfa::class.':'.StepUpAction::FinanceExportCreate->value;
            Route::get('finance-exports', [FinanceExportController::class, 'index'])
                ->name('finance-exports.index');
            Route::post('finance-exports', [FinanceExportController::class, 'store'])
                ->middleware([EnsurePermission::class.':finance_export.create', $financeExportStepUp])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('finance-exports.store');
            Route::get('finance-exports/{financeExport}', [FinanceExportController::class, 'show'])
                ->name('finance-exports.show');
            Route::post('finance-exports/{financeExport}/download-link', [FinanceExportController::class, 'downloadLink'])
                ->middleware(EnsurePermission::class.':finance_export.download')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('finance-exports.download-link');
            Route::post('finance-exports/{financeExport}/revoke', [FinanceExportController::class, 'revoke'])
                ->middleware(EnsurePermission::class.':finance_export.create')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('finance-exports.revoke');

            Route::post('payment-reference-checks/{paymentReferenceCheck}/override', [PaymentReferenceCheckController::class, 'override'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':customer_payment.duplicate_override', $paymentOverrideStepUp, $paymentIdempotent])
                ->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value)
                ->name('payment-reference-checks.override');

            // Files & media (Plan §65; Phase 10F). Upload streams to private
            // quarantine then scans; downloads require auth + a valid temporary
            // signature, with authorization re-checked by FileAccessService.
            // Authorization is per-purpose (FilePurposeRegistry), so it is enforced
            // in the pipeline/access service rather than a single route permission.
            Route::post('files', [FileController::class, 'store'])
                ->middleware('throttle:file-upload')
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('files.store');
            Route::get('files/{uploadedFile}', [FileController::class, 'show'])
                ->name('files.show');
            Route::post('files/{uploadedFile}/download-link', [FileController::class, 'downloadLink'])
                ->defaults(RouteClassification::KEY, RouteClass::TenantMutation->value)
                ->name('files.download-link');
            Route::get('files/{uploadedFile}/download', [FileController::class, 'download'])
                ->middleware('signed')
                ->name('files.download');

            // Merchant audit-log reads (Scope §4.8, Plan §19.2/§19.3, §70; Phase 19).
            // READ-ONLY, field-masked, branch-scoped and domain-SEGMENTED. The legacy
            // catch-all `audit.view_full` is RETIRED — each segment carries its own
            // canonical key. Merchant-level (branch_id null) rows are never exposed
            // here (Phase 19 Q2). Literal segment routes precede the ULID show route.
            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->middleware(EnsurePermission::class.':audit.branch_events.view')
                ->name('audit-logs.index');
            Route::get('audit-logs/finance', [AuditLogController::class, 'finance'])
                ->middleware(EnsurePermission::class.':finance.audit.view,audit.finance.view')
                ->name('audit-logs.finance');
            Route::get('audit-logs/compensation', [AuditLogController::class, 'compensation'])
                ->middleware(EnsurePermission::class.':audit.compensation.view')
                ->name('audit-logs.compensation');
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])
                ->middleware(EnsurePermission::class.':audit.branch_events.view')
                ->name('audit-logs.show');

            // Audit flagged-event review workflow (Plan §13.2, §25, §80; Phase 19). The
            // Audit role flags a branch-scoped audit row and works it through the review
            // lifecycle. Review metadata ONLY — the source audit_logs row is immutable.
            // Reads via audit.branch_events.view; writes via the canonical flagged_event
            // keys. Transitions are branch mutations (no money, no period lock).
            Route::get('audit-flagged-events', [AuditFlaggedEventController::class, 'index'])
                ->middleware(EnsurePermission::class.':audit.branch_events.view')
                ->name('audit-flagged-events.index');
            Route::get('audit-flagged-events/{auditFlaggedEvent}', [AuditFlaggedEventController::class, 'show'])
                ->middleware(EnsurePermission::class.':audit.branch_events.view')
                ->name('audit-flagged-events.show');
            Route::post('audit-flagged-events', [AuditFlaggedEventController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.flagged_event.create'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-flagged-events.store');
            Route::post('audit-flagged-events/{auditFlaggedEvent}/start-review', [AuditFlaggedEventController::class, 'startReview'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.flagged_event.update_status'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-flagged-events.start-review');
            Route::post('audit-flagged-events/{auditFlaggedEvent}/resolve', [AuditFlaggedEventController::class, 'resolve'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.flagged_event.resolve_metadata'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-flagged-events.resolve');
            Route::post('audit-flagged-events/{auditFlaggedEvent}/dismiss', [AuditFlaggedEventController::class, 'dismiss'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.flagged_event.resolve_metadata'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-flagged-events.dismiss');
            Route::post('audit-flagged-events/{auditFlaggedEvent}/reopen', [AuditFlaggedEventController::class, 'reopen'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.flagged_event.update_status'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-flagged-events.reopen');

            // Audit exports (Plan §13.5, §19.2/§19.3, §80; Phase 19; ADR-010). The Audit
            // role requests a reason-gated, branch-scoped, masked export (audit.export +
            // fresh step-up) generated async on reports-exports, then downloads it via an
            // authorized signed Phase 10F link. Download accounting is recorded on the
            // STREAM (not link issuance). Reads/writes are branch-scoped (branch model
            // scope); merchant-level (branch_id null) rows are never exported.
            $auditExportStepUp = RequireFreshMfa::class.':'.StepUpAction::AuditExportCreate->value;
            Route::get('audit-exports', [AuditExportController::class, 'index'])
                ->middleware(EnsurePermission::class.':audit.export')
                ->name('audit-exports.index');
            Route::post('audit-exports', [AuditExportController::class, 'store'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.export', $auditExportStepUp])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-exports.store');
            Route::get('audit-exports/{auditExport}', [AuditExportController::class, 'show'])
                ->middleware(EnsurePermission::class.':audit.export')
                ->name('audit-exports.show');
            Route::post('audit-exports/{auditExport}/download-link', [AuditExportController::class, 'downloadLink'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.export'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-exports.download-link');
            Route::get('audit-exports/{auditExport}/download', [AuditExportController::class, 'download'])
                ->middleware('signed')
                ->name('audit-exports.download');
            Route::post('audit-exports/{auditExport}/revoke', [AuditExportController::class, 'revoke'])
                ->middleware([EnsureBranchScope::class, EnsurePermission::class.':audit.export'])
                ->defaults(RouteClassification::KEY, RouteClass::BranchMutation->value)
                ->name('audit-exports.revoke');
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
 | Phase 20A — Platform billing catalogue governance (Plan §13.9, §13.10, §47; Super-Admin only).
 | OUTSIDE the merchant tenant-context group: platform_mutation forbids ResolveTenantContext +
 | EnsureMerchantActive (Plan §24.1), so this group uses ResolvePlatformContext (platform-staff
 | grants only — never a merchant/branch). Mandatory MFA via EnsurePrivilegedMfa; fresh step-up
 | (StepUpAction::BillingConfiguration) on sensitive mutations; idempotency on effective-dated
 | version/price/fee creates. Reads carry EnsurePermission only. No DELETE, no generic status route.
 */
Route::prefix('platform')
    ->middleware([
        'auth:sanctum',
        EnforceIdleTimeout::class,
        EnsureActivePrincipal::class,
        'throttle:api',
        EnsurePrivilegedMfa::class,
        ResolvePlatformContext::class,
    ])
    ->group(function (): void {
        $stepUp = RequireFreshMfa::class.':'.StepUpAction::BillingConfiguration->value;
        $platform = RouteClass::PlatformMutation->value;

        // General platform settings.
        Route::get('settings', [PlatformSettingsController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.settings.view')
            ->name('platform.settings.show');
        Route::put('settings', [PlatformSettingsController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.settings.update', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.settings.update');

        // Platform billing settings (effective-dated versions).
        Route::get('billing-settings', [PlatformBillingSettingsController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.billing_settings.view')
            ->name('platform.billing-settings.show');
        Route::put('billing-settings', [PlatformBillingSettingsController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.billing_settings.update', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.billing-settings.update');

        // Subscription plans (non-price catalogue metadata).
        Route::get('plans', [SubscriptionPlanController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.plan.view')
            ->name('platform.plans.index');
        Route::post('plans', [SubscriptionPlanController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.plan.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plans.store');
        Route::get('plans/{plan}', [SubscriptionPlanController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.plan.view')
            ->name('platform.plans.show');
        Route::patch('plans/{plan}', [SubscriptionPlanController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.plan.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plans.update');
        Route::post('plans/{plan}/retire', [SubscriptionPlanController::class, 'retire'])
            ->middleware([EnsurePermission::class.':platform.plan.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plans.retire');

        // Plan prices (sole price source; future effective_from schedules).
        Route::get('plans/{plan}/prices', [SubscriptionPlanPriceController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.plan.view')
            ->name('platform.plans.prices.index');
        Route::post('plans/{plan}/prices', [SubscriptionPlanPriceController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.plan_price.manage', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plans.prices.store');
        Route::post('plan-prices/{planPrice}/cancel', [SubscriptionPlanPriceController::class, 'cancel'])
            ->middleware([EnsurePermission::class.':platform.plan_price.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plan-prices.cancel');

        // Plan entitlements (managed under platform.plan.manage).
        Route::get('plans/{plan}/entitlements', [PlanEntitlementController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.plan.view')
            ->name('platform.plans.entitlements.index');
        Route::put('plans/{plan}/entitlements', [PlanEntitlementController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.plan.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.plans.entitlements.update');

        // Preferred-personnel fee rules.
        Route::get('preferred-personnel-fee-rules', [PreferredPersonnelFeeRuleController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.preferred_personnel_fee.manage')
            ->name('platform.preferred-personnel-fee-rules.index');
        Route::post('preferred-personnel-fee-rules', [PreferredPersonnelFeeRuleController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.preferred_personnel_fee.manage', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.preferred-personnel-fee-rules.store');
        Route::get('preferred-personnel-fee-rules/{preferredPersonnelFeeRule}', [PreferredPersonnelFeeRuleController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.preferred_personnel_fee.manage')
            ->name('platform.preferred-personnel-fee-rules.show');
        Route::post('preferred-personnel-fee-rules/{preferredPersonnelFeeRule}/approve', [PreferredPersonnelFeeRuleController::class, 'approve'])
            ->middleware([EnsurePermission::class.':platform.preferred_personnel_fee.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.preferred-personnel-fee-rules.approve');
        Route::post('preferred-personnel-fee-rules/{preferredPersonnelFeeRule}/supersede', [PreferredPersonnelFeeRuleController::class, 'supersede'])
            ->middleware([EnsurePermission::class.':platform.preferred_personnel_fee.manage', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.preferred-personnel-fee-rules.supersede');
        Route::post('preferred-personnel-fee-rules/{preferredPersonnelFeeRule}/cancel', [PreferredPersonnelFeeRuleController::class, 'cancel'])
            ->middleware([EnsurePermission::class.':platform.preferred_personnel_fee.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.preferred-personnel-fee-rules.cancel');

        // Phase 20C — promotional discounts (platform-governed; Plan §53). Super-Admin only, MFA (group)
        // + fresh step-up (BillingConfiguration) on every mutation + idempotency on create. Named
        // actions per transition; no generic status route; request bodies never accept status/approver.
        Route::get('promotional-discounts', [PromotionalDiscountController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.promotion.manage')
            ->name('platform.promotional-discounts.index');
        Route::post('promotional-discounts', [PromotionalDiscountController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.store');
        Route::get('promotional-discounts/{promotionalDiscount}', [PromotionalDiscountController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.promotion.manage')
            ->name('platform.promotional-discounts.show');
        Route::patch('promotional-discounts/{promotionalDiscount}', [PromotionalDiscountController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.update');
        Route::post('promotional-discounts/{promotionalDiscount}/approve', [PromotionalDiscountController::class, 'approve'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.approve');
        Route::post('promotional-discounts/{promotionalDiscount}/pause', [PromotionalDiscountController::class, 'pause'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.pause');
        Route::post('promotional-discounts/{promotionalDiscount}/resume', [PromotionalDiscountController::class, 'resume'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.resume');
        Route::post('promotional-discounts/{promotionalDiscount}/cancel', [PromotionalDiscountController::class, 'cancel'])
            ->middleware([EnsurePermission::class.':platform.promotion.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.promotional-discounts.cancel');

        // Phase 20E — percentage platform-fee configurations (platform-governed; Plan §51/§52). Super-Admin
        // only, MFA (group) + fresh BillingConfiguration step-up on mutations + idempotency on
        // create/approve/supersede/cancel. Named actions per transition — NO generic status route.
        Route::get('billing/platform-fee-configurations', [PlatformFeeConfigurationController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.platform_fee.configure')
            ->name('platform.platform-fee-configurations.index');
        Route::post('billing/platform-fee-configurations', [PlatformFeeConfigurationController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.platform_fee.configure', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.platform-fee-configurations.store');
        Route::get('billing/platform-fee-configurations/{platformFeeConfiguration}', [PlatformFeeConfigurationController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.platform_fee.configure')
            ->name('platform.platform-fee-configurations.show');
        Route::patch('billing/platform-fee-configurations/{platformFeeConfiguration}', [PlatformFeeConfigurationController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.platform_fee.configure', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.platform-fee-configurations.update');
        Route::post('billing/platform-fee-configurations/{platformFeeConfiguration}/approve', [PlatformFeeConfigurationController::class, 'approve'])
            ->middleware([EnsurePermission::class.':platform.platform_fee.configure', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.platform-fee-configurations.approve');
        Route::post('billing/platform-fee-configurations/{platformFeeConfiguration}/supersede', [PlatformFeeConfigurationController::class, 'supersede'])
            ->middleware([EnsurePermission::class.':platform.platform_fee.configure', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.platform-fee-configurations.supersede');
        Route::post('billing/platform-fee-configurations/{platformFeeConfiguration}/cancel', [PlatformFeeConfigurationController::class, 'cancel'])
            ->middleware([EnsurePermission::class.':platform.platform_fee.configure', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.platform-fee-configurations.cancel');

        // Phase 20C — free-period (trial-length) offers (platform-governed; Plan §53). Same controls.
        Route::get('free-period-offers', [FreePeriodOfferController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.free_period_offer.manage')
            ->name('platform.free-period-offers.index');
        Route::post('free-period-offers', [FreePeriodOfferController::class, 'store'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp, EnsureIdempotentRequest::class])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.store');
        Route::get('free-period-offers/{freePeriodOffer}', [FreePeriodOfferController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.free_period_offer.manage')
            ->name('platform.free-period-offers.show');
        Route::patch('free-period-offers/{freePeriodOffer}', [FreePeriodOfferController::class, 'update'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.update');
        Route::post('free-period-offers/{freePeriodOffer}/approve', [FreePeriodOfferController::class, 'approve'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.approve');
        Route::post('free-period-offers/{freePeriodOffer}/pause', [FreePeriodOfferController::class, 'pause'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.pause');
        Route::post('free-period-offers/{freePeriodOffer}/resume', [FreePeriodOfferController::class, 'resume'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.resume');
        Route::post('free-period-offers/{freePeriodOffer}/cancel', [FreePeriodOfferController::class, 'cancel'])
            ->middleware([EnsurePermission::class.':platform.free_period_offer.manage', $stepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.free-period-offers.cancel');

        // Platform merchant governance (Plan §22, §24.1; Phase 20B). Super-Admin platform scope
        // (no merchant tenant context). Registration monitoring + merchant list/detail are reads;
        // suspend/reactivate/deactivate are platform_mutation with a MANDATORY reason and a fresh
        // step-up (StepUpAction::MerchantGovernance). Each mutation mutates `merchants.status` ONLY
        // — never `merchants.billing_status`, and never creates a subscription/payment row. There is
        // NO merchant-creation, first-admin, impersonation, manual-payment, or billing-recovery route.
        $governanceStepUp = RequireFreshMfa::class.':'.StepUpAction::MerchantGovernance->value;

        Route::get('registration-monitor', [PlatformMerchantGovernanceController::class, 'registrationMonitor'])
            ->middleware(EnsurePermission::class.':platform.registration_monitor.view')
            ->name('platform.registration-monitor.index');
        Route::get('merchants', [PlatformMerchantGovernanceController::class, 'index'])
            ->middleware(EnsurePermission::class.':platform.merchant.view')
            ->name('platform.merchants.index');
        Route::get('merchants/{merchant}', [PlatformMerchantGovernanceController::class, 'show'])
            ->middleware(EnsurePermission::class.':platform.merchant.view')
            ->name('platform.merchants.show');
        Route::post('merchants/{merchant}/suspend', [PlatformMerchantGovernanceController::class, 'suspend'])
            ->middleware([EnsurePermission::class.':platform.merchant.suspend', $governanceStepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.merchants.suspend');
        Route::post('merchants/{merchant}/reactivate', [PlatformMerchantGovernanceController::class, 'reactivate'])
            ->middleware([EnsurePermission::class.':platform.merchant.reactivate', $governanceStepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.merchants.reactivate');
        Route::post('merchants/{merchant}/deactivate', [PlatformMerchantGovernanceController::class, 'deactivate'])
            ->middleware([EnsurePermission::class.':platform.merchant.deactivate', $governanceStepUp])
            ->defaults(RouteClassification::KEY, $platform)
            ->name('platform.merchants.deactivate');
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
    Route::middleware(['auth:sanctum', EnforceIdleTimeout::class, EnsureActivePrincipal::class, EnsurePrivilegedMfa::class, ResolveTenantContext::class])
        ->get('testing/privileged-probe', fn () => response()->json(['ok' => true]))
        ->name('testing.privileged-probe');

    Route::prefix('testing/step-up')
        ->middleware('auth:sanctum')
        ->group(function (): void {
            foreach (StepUpAction::businessActions() as $action) {
                Route::post($action->value, fn () => response()->json(['ok' => true, 'action' => $action->value]))
                    ->middleware(RequireFreshMfa::class.':'.$action->value)
                    ->defaults(RouteClassification::KEY, RouteClass::AuthenticatedGlobalMutation->value)
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
