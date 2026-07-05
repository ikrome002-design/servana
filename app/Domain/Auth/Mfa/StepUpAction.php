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

    /** The phase that owns the real route this classification protects. */
    public function owningPhase(): string
    {
        return match ($this) {
            self::BillingConfiguration => 'Phase 20A',
            self::RefundFinalization => 'Phase 18B',
            self::PeriodReopen => 'Phase 18B',
            self::PayoutApproval => 'Phase 20H',
            self::PayoutMarkPaid => 'Phase 20H',
            self::ReconciliationResolution => 'Phase 20D',
            self::CompensationBackdatedChange => 'Phase 20F/20G',
            self::RecoveryCodeRegeneration => 'Phase R3 (implemented)',
            self::InvoiceVoid => 'Phase 17 (implemented)',
            self::PaymentDuplicateOverride => 'Phase 18A (implemented)',
            self::RefundApproval => 'Phase 18B (implemented)',
            self::FinanceExportCreate => 'Phase 18B (implemented)',
            self::AuditExportCreate => 'Phase 19 (implemented)',
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
            self::BillingConfiguration,
            self::RefundFinalization,
            self::PeriodReopen,
            self::PayoutApproval,
            self::PayoutMarkPaid,
            self::ReconciliationResolution,
            self::CompensationBackdatedChange,
        ];
    }
}
