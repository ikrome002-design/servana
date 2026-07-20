<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

use App\Domain\Audit\Contracts\AuditRecorder;

/**
 * Canonical typed audit-event catalogue (Plan §70, ADR-008; R2).
 *
 * One stable, snake-cased action name per audited transition, with its severity
 * defined centrally here. Transition actions/services record `AuditEvent` cases
 * (never free-form strings) through {@see AuditRecorder}.
 *
 * R2 covers the domains already implemented: authentication, staff invitations,
 * membership + staff lifecycle, branch lifecycle, branch assignment, permission
 * overrides, and unauthorized access. Financial/billing/M-Pesa/compensation/SMS/
 * file/export events are deliberately NOT named here — they arrive with their
 * owning phases (18/19/20/21S/10F). The existing action strings emitted since
 * Phase 8 (`permission.override.*`, `permission.write_denied`, `unauthorized_access`)
 * and the Phase 5 auth names are preserved verbatim so history stays consistent.
 */
enum AuditEvent: string
{
    // --- Authentication (Plan §9.1) — public/pre-auth events have a null actor.
    case LoginLinkRequested = 'login_link_requested';
    case LoginLinkDenied = 'login_link_denied';
    case LoginLinkFailed = 'login_link_failed';
    case LoginSuccess = 'login_success';
    case Logout = 'logout';

    // --- Staff invitations (Scope §3.4).
    case InvitationCreated = 'invitation.created';
    case InvitationResent = 'invitation.resent';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';

    // --- Membership + staff lifecycle (Scope §3.4). A membership status change
    // IS the staff lifecycle transition (one event per transition, no doubles).
    case MembershipCreated = 'membership.created';
    case MembershipActivated = 'membership.activated';
    case MembershipSuspended = 'membership.suspended';
    case MembershipDeactivated = 'membership.deactivated';

    // --- Branch assignment (Plan §8.2).
    case BranchAssignmentGranted = 'branch_assignment.granted';
    case BranchAssignmentRevoked = 'branch_assignment.revoked';

    // --- Branch lifecycle (Scope §3.3).
    case BranchCreated = 'branch.created';
    case BranchProfileUpdated = 'branch.profile_updated';
    case BranchArchived = 'branch.archived';
    case BranchOperatingHoursUpdated = 'branch.operating_hours_updated';
    case BranchDayOpened = 'branch.day_opened';
    case BranchDayClosed = 'branch.day_closed';
    case BranchDayReopened = 'branch.day_reopened';

    // --- Permission overrides (Plan §10.3) — names preserved from Phase 8.
    case PermissionOverrideCreated = 'permission.override.created';
    case PermissionOverrideUpdated = 'permission.override.updated';
    case PermissionOverrideRevoked = 'permission.override.revoked';
    case PermissionOverrideDeniedSelfEscalation = 'permission.override.denied_self_escalation';
    case PermissionWriteDenied = 'permission.write_denied';

    // --- MFA + step-up (Plan §18; R3). Identity-level (null merchant chain);
    // secrets/codes/session ids are NEVER placed in audit context.
    case MfaEnrollmentStarted = 'mfa.enrollment_started';
    case MfaEnrollmentConfirmed = 'mfa.enrollment_confirmed';
    case MfaChallengeSucceeded = 'mfa.challenge_succeeded';
    case MfaChallengeFailed = 'mfa.challenge_failed';
    case MfaRecoveryCodeUsed = 'mfa.recovery_code_used';
    case MfaRecoveryCodesRegenerated = 'mfa.recovery_codes_regenerated';
    case MfaStepUpSucceeded = 'mfa.step_up_succeeded';
    case MfaStepUpDenied = 'mfa.step_up_denied';

    // --- Catalogue & clients (Plan §35, §39; Phase 15A). Branch Manager owns the
    // service catalogue; HR owns personnel-service eligibility; Front Office owns
    // client records + SMS consent. Client events carry ONLY safe ids + masked
    // values — never full phone/email and never the blind index.
    case ServiceCategoryCreated = 'service_category.created';
    case ServiceCategoryUpdated = 'service_category.updated';
    case ServiceCategoryArchived = 'service_category.archived';
    case ServiceCreated = 'service.created';
    case ServiceUpdated = 'service.updated';
    case ServiceArchived = 'service.archived';
    case PersonnelEligibilityAssigned = 'personnel_eligibility.assigned';
    case PersonnelEligibilityRevoked = 'personnel_eligibility.revoked';
    // Personnel availability (Phase 15B). HR owns availability mutation. One
    // coherent event per atomic action (not per row); context carries only safe
    // counts/interval + a SANITISED change reason — never tokens/contacts/ids.
    case PersonnelAvailabilityUpdated = 'personnel_availability.updated';
    case PersonnelAvailabilityEmergencyUnavailable = 'personnel_availability.emergency_unavailable';
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientConsentOptedIn = 'client_consent.opted_in';
    case ClientConsentOptedOut = 'client_consent.opted_out';

    // --- Appointments (Plan §36, §25.2; Phase 16A). Front Office owns appointment
    // operations; one coherent typed event per action. Context carries only safe
    // ids (appointment/client/service/personnel ULIDs), state, interval, and a
    // SANITISED reason — never full client phone/email and never the blind index.
    case AppointmentCreated = 'appointment.created';
    case AppointmentAssigned = 'appointment.assigned';
    case AppointmentTransferred = 'appointment.transferred';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCheckedIn = 'appointment.checked_in';
    case AppointmentCancelled = 'appointment.cancelled';
    case AppointmentNoShow = 'appointment.no_show';
    case AppointmentQueued = 'appointment.queued';

    // --- Walk-ins & queues (Plan §37, §25.2; Phase 16B). Front Office operates the
    // queue; one coherent typed event per action (walk-in creation and the queue
    // entry it spawns are two first-class aggregates → two creation events). Branch
    // Manager configures the queue. Context carries only safe ids (queue-entry/
    // walk-in/appointment/client/service/personnel ULIDs), prev/new state, prev/new
    // position, assignment mode, and a SANITISED reason — never full contact, blind
    // index, tokens, headers, full bodies, or sequential ids.
    case QueueConfigurationUpdated = 'queue.configuration.updated';
    case WalkInCreated = 'walk_in.created';
    case QueueEntryCreated = 'queue_entry.created';
    case QueueEntryAssigned = 'queue_entry.assigned';
    case QueueEntryCalled = 'queue_entry.called';
    case QueueEntryStarted = 'queue_entry.started';
    case QueueEntryCompleted = 'queue_entry.completed';
    case QueueEntryTransferred = 'queue_entry.transferred';
    case QueueEntryReordered = 'queue_entry.reordered';
    case QueueEntryCancelled = 'queue_entry.cancelled';
    case QueueEntryNoShow = 'queue_entry.no_show';
    case QueueEntryWaitEstimateOverridden = 'queue_entry.wait_estimate_overridden';

    // --- Service sessions (Plan §25.2, §13.7; Phase 16C). Front Office operates;
    // one coherent typed event per action. Context carries only safe ids
    // (service-session/queue-entry/client/service/personnel ULIDs), prev/new state,
    // the preferred-personnel honoured/overridden flag, and a SANITISED reason —
    // never full contact, blind index, tokens, headers, full bodies, raw unsanitised
    // notes, or sequential ids. Completion's NON-PAYABLE commission preview never
    // writes a ledger.
    case ServiceSessionStarted = 'service_session.started';
    case ServiceSessionCompleted = 'service_session.completed';
    case ServiceSessionCancelled = 'service_session.cancelled';

    // --- Invoicing (Plan §40, §25.3; Phase 17). Front Office drafts/finalizes;
    // Finance voids/adjusts. One coherent typed event per committed mutation;
    // failed/rolled-back actions write NO success event. Context carries only safe
    // ids (invoice/invoice-item/client/service-session ULIDs), the invoice number,
    // prev/new state, integer minor-unit totals + currency, the preferred-fee
    // snapshot amount + source classification, the actor, and a SANITISED reason —
    // never full contact, blind index, tokens, raw idempotency keys, headers, full
    // bodies, or sequential ids.
    case InvoiceCreated = 'invoice.created';
    case InvoiceUpdatedDraft = 'invoice.updated_draft';
    case InvoiceFinalized = 'invoice.finalized';
    case InvoiceVoidRequested = 'invoice.void_requested';
    case InvoiceVoided = 'invoice.voided';
    case InvoiceVoidRejected = 'invoice.void_rejected';
    case InvoiceAdjusted = 'invoice.adjusted';

    // --- Merchant-client payments (Plan §41, §25; Phase 18A). Front Office is the
    // default maker; Finance overrides a suspected duplicate. One coherent typed
    // event per committed action; rolled-back recordings write NO success event.
    // Context carries only safe ids (group/invoice/client ULIDs + invoice number),
    // component methods, integer minor-unit amounts + currency, a MASKED reference
    // suffix, balance-before / pending-before / available-after, the actor, and a
    // SANITISED override reason — never a full/normalized reference, the encrypted
    // display value, full client contact, raw idempotency key, tokens, headers, full
    // bodies, or sequential ids.
    case CustomerPaymentRecorded = 'customer_payment.recorded';
    case CustomerPaymentDuplicateSuspected = 'customer_payment.duplicate_suspected';
    case CustomerPaymentDuplicateOverrideApproved = 'customer_payment.duplicate_override_approved';
    case CustomerPaymentRecordedException = 'customer_payment.recorded_exception';

    // --- Financial validation controls (Plan §42–§46, §43, §44, §45, §46; Phase 18B).
    // Group validation/rejection/correction; automatic receipts + reissue + download;
    // external refunds; finance disputes; cash-up reconciliation; database period
    // locks + exceptional reopen; scoped finance exports. Same redaction discipline:
    // ULIDs, integer minor amounts, currency, safe statuses, masked reference suffix,
    // and sanitised reasons only — never a full/normalized reference, external refund
    // reference plaintext, full client contact, private file path, signed URL, export
    // content, SQLSTATE, stack trace, internal id, MFA code, or authorization header.
    case CustomerPaymentValidated = 'customer_payment.validated';
    case CustomerPaymentRejected = 'customer_payment.rejected';
    case CustomerPaymentCorrectionRequested = 'customer_payment.correction_requested';
    case CustomerPaymentReferenceCorrected = 'customer_payment.reference_corrected';
    case CustomerPaymentResubmitted = 'customer_payment.resubmitted';
    case ReceiptIssued = 'receipt.issued';
    case ReceiptReissued = 'receipt.reissued';
    case ReceiptDownloaded = 'receipt.downloaded';
    case RefundRequested = 'refund.requested';
    case RefundApproved = 'refund.approved';
    case RefundRejected = 'refund.rejected';
    case RefundFinalized = 'refund.finalized';
    case FinanceDisputeOpened = 'finance_dispute.opened';
    case FinanceDisputeReviewStarted = 'finance_dispute.review_started';
    case FinanceDisputeResolved = 'finance_dispute.resolved';
    case FinanceDisputeRejected = 'finance_dispute.rejected';
    case CashUpDraftUpdated = 'cash_up.draft_updated';
    case CashUpSubmitted = 'cash_up.submitted';
    case CashUpApproved = 'cash_up.approved';
    case CashUpRejected = 'cash_up.rejected';
    case CashUpCorrectionRequested = 'cash_up.correction_requested';
    case CashUpResubmitted = 'cash_up.resubmitted';
    case CashUpLocked = 'cash_up.locked';
    case FinancialPeriodLocked = 'financial_period.locked';
    case FinancialPeriodReopenRequested = 'financial_period.reopen_requested';
    case FinancialPeriodReopenApproved = 'financial_period.reopen_approved';
    case FinancialPeriodReopened = 'financial_period.reopened';
    case FinanceExportRequested = 'finance_export.requested';
    case FinanceExportGenerated = 'finance_export.generated';
    case FinanceExportFailed = 'finance_export.failed';
    case FinanceExportDownloaded = 'finance_export.downloaded';
    case FinanceExportExpired = 'finance_export.expired';
    case FinanceExportRevoked = 'finance_export.revoked';

    // --- Tenant/branch isolation (Plan §8.4) — name preserved from Phase 9.
    case UnauthorizedAccess = 'unauthorized_access';

    // --- File domain (Plan §65, §73; Phase 10F). Full file/export audit workflow
    // + flagged events remain Phase 19; these are the core pipeline events.
    case FileUploadAccepted = 'file.upload_accepted';
    case FileUploadRejected = 'file.upload_rejected';
    case FileScanClean = 'file.scan_clean';
    case FileScanInfected = 'file.scan_infected';
    case FileScanFailed = 'file.scan_failed';
    case FileAvailable = 'file.available';
    case FileDownloaded = 'file.downloaded';
    case FileAccessDenied = 'file.access_denied';
    case FileExpiredOrDeleted = 'file.expired_or_deleted';

    // --- Audit flagged-event review workflow (Plan §13.2, §80; Phase 19). Review
    // metadata only — the source audit_logs row is never mutated.
    case AuditEventFlagged = 'audit.flagged_event.created';
    case AuditFlaggedReviewStarted = 'audit.flagged_event.review_started';
    case AuditFlaggedResolved = 'audit.flagged_event.resolved';
    case AuditFlaggedDismissed = 'audit.flagged_event.dismissed';
    case AuditFlaggedReopened = 'audit.flagged_event.reopened';
    // --- Permissioned, masked, signed/expiring, download-counted audit export
    // (Plan §13.5, §80; Phase 19; ADR-010). Naming mirrors the Finance-export
    // lifecycle; download accounting is on the authorized file stream. (Replaces the
    // earlier unused `audit.exported` catalogue entry — no duplicate-meaning event.)
    case AuditExportRequested = 'audit_export.requested';
    case AuditExportGenerated = 'audit_export.generated';
    case AuditExportFailed = 'audit_export.failed';
    case AuditExportDownloaded = 'audit_export.downloaded';
    case AuditExportExpired = 'audit_export.expired';
    case AuditExportRevoked = 'audit_export.revoked';

    // --- Platform billing catalogue governance (Plan §13.9, §13.10, §47; Phase 20A).
    // Super-Admin platform_mutation events; platform-scoped (null merchant/branch);
    // redacted public-ULID context. No events for reads.
    case PlatformSettingsUpdated = 'platform_settings.updated';
    case PlatformBillingSettingsUpdated = 'platform_billing.settings_updated';
    case SubscriptionPlanCreated = 'subscription_plan.created';
    case SubscriptionPlanMetadataUpdated = 'subscription_plan.metadata_updated';
    case SubscriptionPlanRetired = 'subscription_plan.retired';
    case SubscriptionPlanPriceCreated = 'subscription_plan_price.created';
    case SubscriptionPlanPriceScheduled = 'subscription_plan_price.scheduled';
    case SubscriptionPlanPriceCancelled = 'subscription_plan_price.cancelled';
    case PlanEntitlementsUpdated = 'plan_entitlement.updated';
    case PreferredPersonnelFeeRuleCreated = 'preferred_personnel_fee_rule.created';
    case PreferredPersonnelFeeRuleApproved = 'preferred_personnel_fee_rule.approved';
    case PreferredPersonnelFeeRuleSuperseded = 'preferred_personnel_fee_rule.superseded';
    case PreferredPersonnelFeeRuleCancelled = 'preferred_personnel_fee_rule.cancelled';

    // --- Merchant subscription lifecycle + billing-status projection (Plan §22, §25.4, §48; Phase 20B).
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionTrialStarted = 'subscription.trial_started';
    case SubscriptionActivated = 'subscription.activated';
    case SubscriptionReadOnlyGraceEntered = 'subscription.read_only_grace_entered';
    case SubscriptionOverdue = 'subscription.overdue';
    case SubscriptionSuspendedBilling = 'subscription.suspended_billing';
    case SubscriptionCancelled = 'subscription.cancelled';
    case SubscriptionExpired = 'subscription.expired';
    case SubscriptionRecovered = 'subscription.recovered';
    case MerchantBillingStatusChanged = 'merchant.billing_status_changed';
    case SubscriptionPlanChangeScheduled = 'subscription.plan_change_scheduled';
    case SubscriptionPlanChangeApplied = 'subscription.plan_change_applied';
    case SubscriptionPlanChangeCancelled = 'subscription.plan_change_cancelled';

    // --- Platform merchant governance (Plan §22, §24.1; Phase 20B). Super-Admin platform_mutation
    // events on the platform/governance chain (null merchant/branch). Each mutates `merchants.status`
    // only (never billing) and carries a redacted context (merchant ULID + prev/new status + reason);
    // never a raw reason beyond the sanitised governance note, internal id, or session detail.
    case MerchantSuspended = 'merchant.suspended';
    case MerchantReactivated = 'merchant.reactivated';
    case MerchantDeactivated = 'merchant.deactivated';

    // --- Subscription invoices + billing escalation (Plan §49, §54; Phase 20B).
    case SubscriptionInvoiceIssued = 'subscription_invoice.issued';
    case SubscriptionInvoiceOverdue = 'subscription_invoice.overdue';
    case SubscriptionInvoiceVoided = 'subscription_invoice.voided';
    case SubscriptionInvoicePdfGenerated = 'subscription_invoice.pdf_generated';
    case BillingEscalationReminder = 'billing_escalation.reminder';
    case BillingEscalationGraceEntered = 'billing_escalation.grace_entered';
    case BillingEscalationOverdue = 'billing_escalation.overdue';
    case BillingEscalationSuspended = 'billing_escalation.suspended';
    case BillingEscalationRecovered = 'billing_escalation.recovered';

    // Phase 20C — platform-governed promotional discounts (Plan §53). High severity.
    case PromotionCreated = 'promotion.created';
    case PromotionDraftUpdated = 'promotion.draft_updated';
    case PromotionApproved = 'promotion.approved';
    case PromotionActivated = 'promotion.activated';
    case PromotionPaused = 'promotion.paused';
    case PromotionResumed = 'promotion.resumed';
    case PromotionExpired = 'promotion.expired';
    case PromotionCancelled = 'promotion.cancelled';

    // Phase 20C — platform-governed free-period (trial-length) offers (Plan §53). High severity.
    case FreePeriodOfferCreated = 'free_period_offer.created';
    case FreePeriodOfferDraftUpdated = 'free_period_offer.draft_updated';
    case FreePeriodOfferApproved = 'free_period_offer.approved';
    case FreePeriodOfferActivated = 'free_period_offer.activated';
    case FreePeriodOfferPaused = 'free_period_offer.paused';
    case FreePeriodOfferResumed = 'free_period_offer.resumed';
    case FreePeriodOfferExpired = 'free_period_offer.expired';
    case FreePeriodOfferCancelled = 'free_period_offer.cancelled';

    // Phase 20E — percentage platform-fee engine (Plan §51). Finance domain.
    case PlatformFeeOriginalRecorded = 'platform_fee.original_recorded';
    // Phase 20E Increment 5 — aggregation into subscription invoices; additive reversal/adjustment
    // corrections; the canonical platform-fee dispute workflow. Finance domain; safe public ULIDs +
    // integer minor amounts only (never internal ids, raw references, private evidence, or headers).
    case PlatformFeeAggregated = 'platform_fee.aggregated';
    case PlatformFeeInvoiced = 'platform_fee.invoiced';
    case PlatformFeeReversed = 'platform_fee.reversed';
    case PlatformFeeAdjusted = 'platform_fee.adjusted';
    case PlatformFeeDisputeCreated = 'platform_fee.dispute_created';
    case PlatformFeeDisputeReviewStarted = 'platform_fee.dispute_review_started';
    case PlatformFeeDisputeResolved = 'platform_fee.dispute_resolved';
    case PlatformFeeDisputeRejected = 'platform_fee.dispute_rejected';
    // Phase 20E Increment 6 — percentage platform-fee CONFIGURATION governance (Super-Admin,
    // platform scope, high severity). Redacted public-ULID context; never rates as PII.
    case PlatformFeeConfigurationCreated = 'platform_fee.configuration_created';
    case PlatformFeeConfigurationUpdated = 'platform_fee.configuration_updated';
    case PlatformFeeConfigurationApproved = 'platform_fee.configuration_approved';
    case PlatformFeeConfigurationSuperseded = 'platform_fee.configuration_superseded';
    case PlatformFeeConfigurationCancelled = 'platform_fee.configuration_cancelled';
    // Phase 20F — HR compensation-plan CONFIGURATION governance (branch-scoped; AuditDomain::
    // Compensation, read via audit.compensation.view). Approval is high; a BACKDATED approval is
    // CRITICAL (Plan §59). Context carries public ULIDs + configured terms only — never personnel
    // contact details, never internal ids. These events record CONFIGURATION changes; no earned
    // salary/commission fact exists in Phase 20F (20G/20H own that).
    case CompensationPlanCreated = 'compensation.plan.created';
    case CompensationPlanUpdatedDraft = 'compensation.plan.updated_draft';
    case CompensationPlanSubmitted = 'compensation.plan.submitted';
    case CompensationPlanApproved = 'compensation.plan.approved';
    case CompensationPlanBackdatedChangeApproved = 'compensation.plan.backdated_change_approved';
    case CompensationPlanActivated = 'compensation.plan.activated';
    case CompensationPlanRejected = 'compensation.plan.rejected';
    case CompensationPlanCancelled = 'compensation.plan.cancelled';
    case CompensationPlanSuperseded = 'compensation.plan.superseded';
    case CompensationPlanExpired = 'compensation.plan.expired';
    // Phase 20F — commission-rule configuration. A rule never transitions independently; each of
    // these is emitted inside the referencing plan's transaction.
    case CommissionRuleCreated = 'commission_rule.created';
    case CommissionRuleUpdatedDraft = 'commission_rule.updated_draft';
    case CommissionRuleSubmitted = 'commission_rule.submitted';
    case CommissionRuleApproved = 'commission_rule.approved';
    case CommissionRuleActivated = 'commission_rule.activated';
    case CommissionRuleRejected = 'commission_rule.rejected';
    case CommissionRuleCancelled = 'commission_rule.cancelled';
    case CommissionRuleEnded = 'commission_rule.ended';
    case CommissionRuleExpired = 'commission_rule.expired';
    // Phase 20G — salary/commission LEDGER facts (AuditDomain::Compensation; branch-scoped).
    // These record earned/accrued MONEY (unlike Phase 20F configuration). Context carries public
    // ULIDs, integer minor amounts, currency, entry type, and pay-period/segment boundaries only —
    // never personnel contact details, never internal ids. A manual Finance adjustment is HIGH
    // (MFA + fresh step-up, §19.3); a handoff-consumption failure is observable but non-fatal.
    case CompensationSalaryAccrued = 'compensation.salary.accrued';
    case CompensationSalaryReversed = 'compensation.salary.reversed';
    case CompensationSalaryAdjusted = 'compensation.salary.adjusted';
    case CompensationCommissionEarned = 'compensation.commission.earned';
    case CompensationCommissionReversed = 'compensation.commission.reversed';
    case CompensationAdjustmentCreated = 'compensation.adjustment.created';
    case CompensationHandoffFailed = 'compensation.handoff.failed';

    /**
     * Read-segment domain for each event (Plan §19.2 Audit read split; Phase 19).
     *
     * Drives the masked, domain-filtered audit read surfaces: the finance domain
     * backs `audit.finance.view` / `finance.audit.view`; the (currently empty)
     * compensation domain backs `audit.compensation.view` (populated by Phases
     * 20F–20H); everything else is `General` and backs `audit.branch_events.view`.
     */
    public function domain(): AuditDomain
    {
        return match ($this) {
            self::InvoiceCreated,
            self::InvoiceUpdatedDraft,
            self::InvoiceFinalized,
            self::InvoiceVoidRequested,
            self::InvoiceVoided,
            self::InvoiceVoidRejected,
            self::InvoiceAdjusted,
            self::CustomerPaymentRecorded,
            self::CustomerPaymentDuplicateSuspected,
            self::CustomerPaymentDuplicateOverrideApproved,
            self::CustomerPaymentRecordedException,
            self::CustomerPaymentValidated,
            self::CustomerPaymentRejected,
            self::CustomerPaymentCorrectionRequested,
            self::CustomerPaymentReferenceCorrected,
            self::CustomerPaymentResubmitted,
            self::ReceiptIssued,
            self::ReceiptReissued,
            self::ReceiptDownloaded,
            self::RefundRequested,
            self::RefundApproved,
            self::RefundRejected,
            self::RefundFinalized,
            self::FinanceDisputeOpened,
            self::FinanceDisputeReviewStarted,
            self::FinanceDisputeResolved,
            self::FinanceDisputeRejected,
            self::CashUpDraftUpdated,
            self::CashUpSubmitted,
            self::CashUpApproved,
            self::CashUpRejected,
            self::CashUpCorrectionRequested,
            self::CashUpResubmitted,
            self::CashUpLocked,
            self::FinancialPeriodLocked,
            self::FinancialPeriodReopenRequested,
            self::FinancialPeriodReopenApproved,
            self::FinancialPeriodReopened,
            self::FinanceExportRequested,
            self::FinanceExportGenerated,
            self::FinanceExportFailed,
            self::FinanceExportDownloaded,
            self::FinanceExportExpired,
            self::FinanceExportRevoked,
            self::PlatformFeeOriginalRecorded,
            self::PlatformFeeAggregated,
            self::PlatformFeeInvoiced,
            self::PlatformFeeReversed,
            self::PlatformFeeAdjusted,
            self::PlatformFeeDisputeCreated,
            self::PlatformFeeDisputeReviewStarted,
            self::PlatformFeeDisputeResolved,
            self::PlatformFeeDisputeRejected => AuditDomain::Finance,

            // Phase 20F — compensation configuration (HR). This populates the previously empty
            // `audit.compensation.view` read segment; 20G/20H extend it with earned/payout events.
            self::CompensationPlanCreated,
            self::CompensationPlanUpdatedDraft,
            self::CompensationPlanSubmitted,
            self::CompensationPlanApproved,
            self::CompensationPlanBackdatedChangeApproved,
            self::CompensationPlanActivated,
            self::CompensationPlanRejected,
            self::CompensationPlanCancelled,
            self::CompensationPlanSuperseded,
            self::CompensationPlanExpired,
            self::CommissionRuleCreated,
            self::CommissionRuleUpdatedDraft,
            self::CommissionRuleSubmitted,
            self::CommissionRuleApproved,
            self::CommissionRuleActivated,
            self::CommissionRuleRejected,
            self::CommissionRuleCancelled,
            self::CommissionRuleEnded,
            self::CommissionRuleExpired,
            self::CompensationSalaryAccrued,
            self::CompensationSalaryReversed,
            self::CompensationSalaryAdjusted,
            self::CompensationCommissionEarned,
            self::CompensationCommissionReversed,
            self::CompensationAdjustmentCreated,
            self::CompensationHandoffFailed => AuditDomain::Compensation,

            default => AuditDomain::General,
        };
    }

    /**
     * The action strings of every event in a given read segment (Plan §19.2).
     *
     * Used server-side by the masked audit read endpoints to include/exclude a
     * domain — never a client-supplied filter.
     *
     * @return list<string>
     */
    public static function actionsIn(AuditDomain $domain): array
    {
        return array_values(array_map(
            static fn (self $event): string => $event->value,
            array_filter(self::cases(), static fn (self $event): bool => $event->domain() === $domain),
        ));
    }

    /** Central severity for each event (mirrors audit_logs.severity CHECK). */
    public function severity(): AuditSeverity
    {
        return match ($this) {
            self::LoginSuccess,
            self::Logout,
            self::InvitationCreated,
            self::InvitationResent,
            self::InvitationAccepted,
            self::MembershipCreated,
            self::MembershipActivated,
            self::BranchAssignmentGranted,
            self::BranchCreated,
            self::BranchProfileUpdated,
            self::BranchOperatingHoursUpdated,
            self::MfaChallengeSucceeded,
            self::MfaStepUpSucceeded,
            self::FileUploadAccepted,
            self::FileScanClean,
            self::FileAvailable,
            self::FileDownloaded,
            self::ClientCreated,
            self::ClientUpdated,
            self::ClientConsentOptedIn,
            self::ClientConsentOptedOut,
            self::AppointmentCreated,
            self::AppointmentAssigned,
            self::AppointmentCheckedIn,
            self::AppointmentQueued,
            self::WalkInCreated,
            self::QueueEntryCreated,
            self::QueueEntryAssigned,
            self::QueueEntryCalled,
            self::QueueEntryStarted,
            self::QueueEntryCompleted,
            self::QueueEntryWaitEstimateOverridden,
            self::ServiceSessionStarted,
            self::ServiceSessionCompleted,
            self::InvoiceCreated,
            self::InvoiceUpdatedDraft,
            self::CustomerPaymentRecorded,
            self::CustomerPaymentResubmitted,
            self::ReceiptIssued,
            self::ReceiptDownloaded,
            self::FinanceDisputeReviewStarted,
            self::CashUpDraftUpdated,
            self::FinanceExportGenerated,
            self::FinanceExportDownloaded,
            self::FinanceExportExpired,
            self::AuditExportGenerated,
            self::AuditExportDownloaded,
            self::AuditExportExpired,
            self::LoginLinkRequested => AuditSeverity::Info,

            self::BranchDayOpened,
            self::BranchDayClosed,
            self::MfaEnrollmentStarted,
            self::FileExpiredOrDeleted,
            self::ServiceCategoryCreated,
            self::ServiceCategoryUpdated,
            self::ServiceCategoryArchived,
            self::ServiceCreated,
            self::ServiceUpdated,
            self::ServiceArchived,
            self::PersonnelEligibilityAssigned,
            self::PersonnelEligibilityRevoked,
            self::PersonnelAvailabilityUpdated,
            self::AppointmentTransferred,
            self::AppointmentRescheduled,
            self::QueueConfigurationUpdated,
            self::QueueEntryReordered,
            self::InvoiceFinalized,
            self::CustomerPaymentValidated,
            self::ReceiptReissued,
            self::RefundRequested,
            self::FinanceDisputeOpened,
            self::FinanceDisputeResolved,
            self::CashUpSubmitted,
            self::CashUpApproved,
            self::CashUpResubmitted,
            self::CashUpLocked,
            self::FinanceExportRequested,
            self::AuditExportRequested,
            self::AuditFlaggedReviewStarted,
            self::AuditFlaggedResolved,
            self::AuditFlaggedDismissed,
            self::SubscriptionPlanCreated,
            self::SubscriptionPlanMetadataUpdated,
            self::PlanEntitlementsUpdated,
            self::PreferredPersonnelFeeRuleCreated,
            self::SubscriptionCreated,
            self::SubscriptionTrialStarted,
            self::SubscriptionActivated,
            self::SubscriptionRecovered,
            self::MerchantBillingStatusChanged,
            self::SubscriptionPlanChangeScheduled,
            self::SubscriptionPlanChangeApplied,
            self::SubscriptionPlanChangeCancelled,
            self::SubscriptionInvoiceIssued,
            self::BillingEscalationRecovered,
            self::MfaEnrollmentConfirmed => AuditSeverity::Notice,

            self::SubscriptionInvoicePdfGenerated,
            self::BillingEscalationReminder,
            self::PlatformFeeOriginalRecorded,
            // Increment 5 — aggregation and issuance of the rollup are routine billing steps; the
            // dispute review handoff mirrors finance_dispute.review_started.
            self::PlatformFeeAggregated,
            self::PlatformFeeInvoiced,
            self::PlatformFeeDisputeReviewStarted,
            // Phase 20F — routine in-place draft edits and the effective-date boundaries. No
            // approval decision and no money is involved in these moments.
            self::CompensationPlanUpdatedDraft,
            self::CompensationPlanActivated,
            self::CompensationPlanExpired,
            self::CommissionRuleUpdatedDraft,
            self::CommissionRuleActivated,
            self::CommissionRuleExpired,
            // Phase 20G — the routine ledger facts a scheduler/consumer records: a salary accrual
            // and an earned commission recognize configured money at a boundary; no decision is made.
            self::CompensationSalaryAccrued,
            self::CompensationCommissionEarned => AuditSeverity::Info,

            // Phase 20F — creation/submission and the withdraw/reject outcomes of a compensation
            // change. Each records a governance decision but not an approval of effective terms.
            self::CompensationPlanCreated,
            self::CompensationPlanSubmitted,
            self::CompensationPlanRejected,
            self::CompensationPlanCancelled,
            self::CommissionRuleCreated,
            self::CommissionRuleSubmitted,
            self::CommissionRuleRejected,
            self::CommissionRuleCancelled,
            self::LoginLinkDenied,
            self::LoginLinkFailed,
            self::InvitationRevoked,
            self::BranchAssignmentRevoked,
            self::BranchDayReopened,
            self::PermissionOverrideDeniedSelfEscalation,
            self::PermissionWriteDenied,
            self::MfaChallengeFailed,
            self::MfaRecoveryCodeUsed,
            self::MfaRecoveryCodesRegenerated,
            self::FileUploadRejected,
            self::FileScanFailed,
            self::PersonnelAvailabilityEmergencyUnavailable,
            self::AppointmentCancelled,
            self::AppointmentNoShow,
            self::QueueEntryTransferred,
            self::QueueEntryCancelled,
            self::QueueEntryNoShow,
            self::ServiceSessionCancelled,
            self::InvoiceVoidRejected,
            self::CustomerPaymentDuplicateSuspected,
            self::CustomerPaymentRejected,
            self::CustomerPaymentCorrectionRequested,
            self::RefundRejected,
            self::FinanceDisputeRejected,
            self::CashUpRejected,
            self::CashUpCorrectionRequested,
            self::FinanceExportFailed,
            self::FinanceExportRevoked,
            self::AuditExportFailed,
            self::AuditExportRevoked,
            self::AuditEventFlagged,
            self::AuditFlaggedReopened,
            self::SubscriptionReadOnlyGraceEntered,
            self::SubscriptionOverdue,
            self::SubscriptionInvoiceOverdue,
            self::BillingEscalationGraceEntered,
            self::BillingEscalationOverdue,
            // Increment 5 — additive money corrections and dispute lifecycle. A money-changing dispute
            // resolution records the linked platform_fee_adjustment ULID in context; the base severity
            // stays warning (the original ledger fact is never rewritten).
            self::PlatformFeeReversed,
            self::PlatformFeeAdjusted,
            self::PlatformFeeDisputeCreated,
            self::PlatformFeeDisputeResolved,
            self::PlatformFeeDisputeRejected,
            self::MfaStepUpDenied,
            // Phase 20G — a ledger reversal/adjustment offsets money already recognized, and a
            // handoff-consumption failure is an observable (non-fatal, retryable) condition.
            self::CompensationSalaryReversed,
            self::CompensationSalaryAdjusted,
            self::CompensationCommissionReversed,
            self::CompensationHandoffFailed => AuditSeverity::Warning,

            self::FileScanInfected,
            self::FileAccessDenied,
            self::MembershipSuspended,
            self::MembershipDeactivated,
            self::BranchArchived,
            self::PermissionOverrideCreated,
            self::PermissionOverrideUpdated,
            self::PermissionOverrideRevoked,
            self::InvoiceVoidRequested,
            self::InvoiceVoided,
            self::InvoiceAdjusted,
            self::CustomerPaymentDuplicateOverrideApproved,
            self::CustomerPaymentRecordedException,
            self::CustomerPaymentReferenceCorrected,
            self::RefundApproved,
            self::RefundFinalized,
            self::FinancialPeriodLocked,
            self::FinancialPeriodReopenRequested,
            self::FinancialPeriodReopenApproved,
            self::FinancialPeriodReopened,
            self::PlatformSettingsUpdated,
            self::PlatformBillingSettingsUpdated,
            self::SubscriptionPlanRetired,
            self::SubscriptionPlanPriceCreated,
            self::SubscriptionPlanPriceScheduled,
            self::SubscriptionPlanPriceCancelled,
            self::PreferredPersonnelFeeRuleApproved,
            self::PreferredPersonnelFeeRuleSuperseded,
            self::PreferredPersonnelFeeRuleCancelled,
            self::SubscriptionSuspendedBilling,
            self::SubscriptionCancelled,
            self::SubscriptionExpired,
            self::SubscriptionInvoiceVoided,
            self::BillingEscalationSuspended,
            // Platform merchant governance (Phase 20B): operational suspension/reactivation are
            // high-severity governance actions; deactivation is the terminal state (Critical below).
            self::MerchantSuspended,
            self::MerchantReactivated,
            // Phase 20C — platform-governed promotion + free-period offer management (Plan §53).
            self::PromotionCreated,
            self::PromotionDraftUpdated,
            self::PromotionApproved,
            self::PromotionActivated,
            self::PromotionPaused,
            self::PromotionResumed,
            self::PromotionExpired,
            self::PromotionCancelled,
            self::FreePeriodOfferCreated,
            self::FreePeriodOfferDraftUpdated,
            self::FreePeriodOfferApproved,
            self::FreePeriodOfferActivated,
            self::FreePeriodOfferPaused,
            self::FreePeriodOfferResumed,
            self::FreePeriodOfferExpired,
            self::FreePeriodOfferCancelled,
            // Phase 20E — platform-fee configuration governance (Super-Admin platform mutations).
            self::PlatformFeeConfigurationCreated,
            self::PlatformFeeConfigurationUpdated,
            self::PlatformFeeConfigurationApproved,
            self::PlatformFeeConfigurationSuperseded,
            self::PlatformFeeConfigurationCancelled,
            // Phase 20F — approving effective compensation terms, and ending/superseding terms that
            // were already effective. These decide how personnel will earn (Plan §59).
            self::CompensationPlanApproved,
            self::CompensationPlanSuperseded,
            self::CommissionRuleApproved,
            self::CommissionRuleEnded,
            // Phase 20G — a Finance manual compensation adjustment (MFA + fresh step-up, §19.3).
            self::CompensationAdjustmentCreated,
            self::UnauthorizedAccess => AuditSeverity::High,

            // Plan §59 requires CRITICAL severity for an approved BACKDATED compensation change:
            // it rewrites how personnel earned over a window that has already passed.
            self::CompensationPlanBackdatedChangeApproved,
            self::MerchantDeactivated => AuditSeverity::Critical,
        };
    }
}
