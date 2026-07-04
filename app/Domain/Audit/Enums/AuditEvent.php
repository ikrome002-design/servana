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
            self::MfaEnrollmentConfirmed => AuditSeverity::Notice,

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
            self::MfaStepUpDenied => AuditSeverity::Warning,

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
            self::UnauthorizedAccess => AuditSeverity::High,
        };
    }
}
