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
    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientConsentOptedIn = 'client_consent.opted_in';
    case ClientConsentOptedOut = 'client_consent.opted_out';

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
            self::MfaStepUpDenied => AuditSeverity::Warning,

            self::FileScanInfected,
            self::FileAccessDenied,
            self::MembershipSuspended,
            self::MembershipDeactivated,
            self::BranchArchived,
            self::PermissionOverrideCreated,
            self::PermissionOverrideUpdated,
            self::PermissionOverrideRevoked,
            self::UnauthorizedAccess => AuditSeverity::High,
        };
    }
}
