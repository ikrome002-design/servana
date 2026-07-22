<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Http\Middleware\RequireFreshMfa;

/**
 * Central registry of actions that require a *fresh* MFA step-up (Plan §18, §9.4
 * step 13; Phase R3).
 *
 * This enum is the single source of truth for the designated sensitive actions.
 * The reusable {@see RequireFreshMfa} middleware is
 * parameterised by a case value: `RequireFreshMfa::class.':'.StepUpAction::RefundFinalization->value`.
 *
 * Most owning business routes do NOT exist yet — Phase R3 ships the reusable
 * control and proves it with a test-only harness; each future phase listed in
 * {@see self::owningPhase()} MUST attach the matching classification when it
 * creates the real route. No fake business routes are created here.
 */
enum StepUpAction: string
{
    case BillingConfiguration = 'billing_configuration';
    case RefundFinalization = 'refund_finalization';
    case PeriodReopen = 'period_reopen';
    case PayoutApproval = 'payout_approval';
    case PayoutMarkPaid = 'payout_mark_paid';
    // Phase 20H — Finance payout verification + Merchant-Admin high-value approval. Real, implemented
    // routes (finance.payout-runs.verify / merchant.payout-runs.approve-high-value); excluded from the
    // test-harness businessActions() like the other live-route actions. Distinct from PayoutApproval
    // (Finance ordinary approval) and PayoutMarkPaid (Finance mark-paid).
    case PayoutVerify = 'payout_verify';
    case PayoutHighValueApprove = 'payout_high_value_approve';
    case ReconciliationResolution = 'reconciliation_resolution';
    case CompensationBackdatedChange = 'compensation_backdated_change';

    // R3-owned MFA self-management action (a real, already-implemented route).
    case RecoveryCodeRegeneration = 'recovery_code_regeneration';

    // Phase 17 — Finance invoice void (request/execute). A real, implemented route;
    // excluded from the test-harness businessActions() like RecoveryCodeRegeneration.
    case InvoiceVoid = 'invoice_void';

    // Phase 18A — Finance override of a suspected duplicate payment reference. A real,
    // implemented route; excluded from the test-harness businessActions() (like
    // InvoiceVoid) because it already has a live route.
    case PaymentDuplicateOverride = 'payment_duplicate_override';

    // Phase 18B — Finance refund approval. A real, implemented route; excluded from the
    // test-harness businessActions() (like PaymentDuplicateOverride) because it has a
    // live route. RefundFinalization keeps its existing harness coverage AND its live
    // route (both enforce fresh step-up); this new action does not weaken it.
    case RefundApproval = 'refund_approval';

    // Phase 18B — Finance export request. A real, implemented route (finance_export.create
    // requires fresh step-up, §19.3); excluded from the test-harness businessActions()
    // (like RefundApproval) because it has a live route.
    case FinanceExportCreate = 'finance_export_create';

    // Phase 19 — Audit export request. A real, implemented route (audit.export requires
    // fresh step-up, §19.3; ADR-010); excluded from the test-harness businessActions()
    // (like FinanceExportCreate) because it has a live route.
    case AuditExportCreate = 'audit_export_create';

    // Phase 20B — Platform merchant governance (suspend/reactivate/deactivate). ONE canonical
    // action shared by the three platform-mutation routes (SU Y in the §19.3 matrix); excluded
    // from the test-harness businessActions() (like AuditExportCreate) because it has live routes.
    case MerchantGovernance = 'merchant_governance';

    // Phase 20E — Platform-fee dispute resolution/rejection (Finance; fresh step-up on resolve/reject).
    // A real, implemented route; excluded from businessActions() like the other live-route actions.
    case PlatformFeeDisputeResolution = 'platform_fee_dispute_resolution';

    // Phase 20G — Finance manual compensation-adjustment creation (fresh step-up + high-severity audit,
    // §19.3). A real, implemented route; excluded from the test-harness businessActions() like the other
    // live-route actions. Distinct from CompensationBackdatedChange (plan-approval), per §11.7.
    case CompensationAdjustmentCreate = 'compensation_adjustment_create';

    /** The phase that owns the real route this classification protects. */
    public function owningPhase(): string
    {
        return match ($this) {
            self::BillingConfiguration => 'Phase 20A (implemented)',
            self::RefundFinalization => 'Phase 18B',
            self::PeriodReopen => 'Phase 18B',
            self::PayoutApproval => 'Phase 20H (implemented)',
            self::PayoutMarkPaid => 'Phase 20H (implemented)',
            self::PayoutVerify => 'Phase 20H (implemented)',
            self::PayoutHighValueApprove => 'Phase 20H (implemented)',
            self::ReconciliationResolution => 'Phase 20D',
            // Phase 20F ships the real route: compensation-plans.approve carries
            // RequireFreshMfa:compensation_backdated_change (every approval of effective terms, and
            // a BACKDATED approval additionally emits the CRITICAL audit event). Phase 20G reuses
            // this same canonical compensation action for backdated adjustments.
            self::CompensationBackdatedChange => 'Phase 20F (implemented; 20G extends)',
            self::RecoveryCodeRegeneration => 'Phase R3 (implemented)',
            self::InvoiceVoid => 'Phase 17 (implemented)',
            self::PaymentDuplicateOverride => 'Phase 18A (implemented)',
            self::RefundApproval => 'Phase 18B (implemented)',
            self::FinanceExportCreate => 'Phase 18B (implemented)',
            self::AuditExportCreate => 'Phase 19 (implemented)',
            self::MerchantGovernance => 'Phase 20B (implemented)',
            self::PlatformFeeDisputeResolution => 'Phase 20E (implemented)',
            self::CompensationAdjustmentCreate => 'Phase 20G (implemented)',
        };
    }

    /**
     * The seven designated *business* step-up actions (Plan §18). Their owning
     * feature phases attach the classification when they create the real route;
     * RecoveryCodeRegeneration is deliberately excluded — it is the R3 MFA
     * self-management action and already has a live route.
     *
     * @return list<self>
     */
    public static function businessActions(): array
    {
        return [
            // BillingConfiguration is EXCLUDED here (like InvoiceVoid/AuditExportCreate): Phase 20A
            // ships its real platform billing/configuration routes (settings/plans/prices/fee-rule
            // mutations all carry RequireFreshMfa:billing_configuration), so it is a live platform
            // configuration step-up, not a harness-only business action.
            self::RefundFinalization,
            self::PeriodReopen,
            self::ReconciliationResolution,
            // PayoutApproval / PayoutMarkPaid / PayoutVerify / PayoutHighValueApprove are EXCLUDED here
            // (like the other implemented actions): Phase 20H Increment 5 ships their real routes
            // (finance.payout-runs.approve / .verify / .mark-paid, merchant.payout-runs.approve-high-value),
            // proven on those routes by PayoutRunApiTest, so they are live step-up actions, not
            // harness-only business actions.
            // CompensationBackdatedChange is EXCLUDED here (like BillingConfiguration/InvoiceVoid/
            // PlatformFeeDisputeResolution): Phase 20F ships its real route
            // (compensation-plans.approve), so it is a live compensation step-up proven on that
            // route by CompensationPlanApiTest, not a harness-only business action.
        ];
    }
}
