<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * Audit-account review views over the GENERAL branch audit segment (Phase UI-15; UI/UX plan
 * Appendix A §12.4.6, §12.4.9, §12.4.10).
 *
 * The Audit account's Operational, Access and Security, and Preferred Personnel and Contact
 * Protection pages are presentations of the same immutable, branch-scoped, masked
 * `audit.branch_events.view` stream ({@see AuditDomain::General}). Which events belong to which
 * view is decided HERE, server-side, so the browser never filters a page of results and calls it
 * a view (UI/UX plan §12.4.3 "server-side branch scope; no UI-only filtering").
 *
 * A view is a lens, not a permission: it can only narrow what `audit.branch_events.view` already
 * returns, and finance/compensation events stay in their own permission segments. An event may
 * sit in more than one view (a blocked contact-export attempt is both an access event and a
 * contact-protection event). Membership is restricted to General-domain events, and
 * `tests/Feature/Audit/Ui15AuditExperienceApiTest.php` keeps that true as the catalogue grows.
 */
enum AuditReviewView: string
{
    case Operational = 'operational';
    case Access = 'access';
    case PrivacyPreferred = 'privacy_preferred';

    /**
     * The action strings this view selects.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return array_values(array_map(
            static fn (AuditEvent $event): string => $event->value,
            array_filter(AuditEvent::cases(), fn (AuditEvent $event): bool => $this->includes($event)),
        ));
    }

    public function includes(AuditEvent $event): bool
    {
        if ($event->domain() !== AuditDomain::General) {
            return false;
        }

        return match ($this) {
            self::Operational => in_array($event, self::OPERATIONAL, true),
            self::Access => in_array($event, self::ACCESS, true),
            self::PrivacyPreferred => in_array($event, self::PRIVACY_PREFERRED, true),
        };
    }

    /** Clients, appointments, walk-ins, queues, sessions, services and branch-day operations (§12.4.6). */
    private const OPERATIONAL = [
        AuditEvent::BranchCreated,
        AuditEvent::BranchProfileUpdated,
        AuditEvent::BranchArchived,
        AuditEvent::BranchOperatingHoursUpdated,
        AuditEvent::BranchCalendarExceptionSet,
        AuditEvent::BranchCalendarExceptionRemoved,
        AuditEvent::BranchDayOpened,
        AuditEvent::BranchDayClosed,
        AuditEvent::BranchDayReopened,
        AuditEvent::ServiceCategoryCreated,
        AuditEvent::ServiceCategoryUpdated,
        AuditEvent::ServiceCategoryArchived,
        AuditEvent::ServiceCreated,
        AuditEvent::ServiceUpdated,
        AuditEvent::ServiceArchived,
        AuditEvent::PersonnelEligibilityAssigned,
        AuditEvent::PersonnelEligibilityRevoked,
        AuditEvent::PersonnelAvailabilityUpdated,
        AuditEvent::PersonnelAvailabilityEmergencyUnavailable,
        AuditEvent::ClientCreated,
        AuditEvent::ClientUpdated,
        AuditEvent::AppointmentCreated,
        AuditEvent::AppointmentAssigned,
        AuditEvent::AppointmentTransferred,
        AuditEvent::AppointmentRescheduled,
        AuditEvent::AppointmentCheckedIn,
        AuditEvent::AppointmentCancelled,
        AuditEvent::AppointmentNoShow,
        AuditEvent::AppointmentQueued,
        AuditEvent::QueueConfigurationUpdated,
        AuditEvent::WalkInCreated,
        AuditEvent::QueueEntryCreated,
        AuditEvent::QueueEntryAssigned,
        AuditEvent::QueueEntryCalled,
        AuditEvent::QueueEntryStarted,
        AuditEvent::QueueEntryCompleted,
        AuditEvent::QueueEntryTransferred,
        AuditEvent::QueueEntryReordered,
        AuditEvent::QueueEntryCancelled,
        AuditEvent::QueueEntryNoShow,
        AuditEvent::QueueEntryWaitEstimateOverridden,
        AuditEvent::ServiceSessionStarted,
        AuditEvent::ServiceSessionCompleted,
        AuditEvent::ServiceSessionCancelled,
    ];

    /**
     * Authentication, invitations, membership/role/permission and branch-access changes,
     * unauthorized attempts, and file/export access (§12.4.9). Identity-level authentication rows
     * carry no merchant or branch, so the branch-scoped read never returns them; they are listed so
     * the view stays correct if a branch-scoped variant is ever recorded.
     */
    private const ACCESS = [
        AuditEvent::LoginLinkRequested,
        AuditEvent::LoginLinkDenied,
        AuditEvent::LoginLinkFailed,
        AuditEvent::LoginSuccess,
        AuditEvent::Logout,
        AuditEvent::MagicLinkHostBindingRejected,
        AuditEvent::MagicLinkEnvironmentBindingRejected,
        AuditEvent::SessionFamilyCreated,
        AuditEvent::SessionFamilyRevoked,
        AuditEvent::HostSessionCreated,
        AuditEvent::HostSessionRevoked,
        AuditEvent::GlobalLogout,
        AuditEvent::ContextHandoffIssued,
        AuditEvent::ContextHandoffConsumed,
        AuditEvent::ContextHandoffRejected,
        AuditEvent::ContextHandoffReplayRejected,
        AuditEvent::WrongAccountDeepLinkRejected,
        AuditEvent::InvitationCreated,
        AuditEvent::InvitationResent,
        AuditEvent::InvitationRevoked,
        AuditEvent::InvitationAccepted,
        AuditEvent::MembershipCreated,
        AuditEvent::MembershipActivated,
        AuditEvent::MembershipSuspended,
        AuditEvent::MembershipDeactivated,
        AuditEvent::BranchAssignmentGranted,
        AuditEvent::BranchAssignmentRevoked,
        AuditEvent::PermissionOverrideCreated,
        AuditEvent::PermissionOverrideUpdated,
        AuditEvent::PermissionOverrideRevoked,
        AuditEvent::PermissionOverrideDeniedSelfEscalation,
        AuditEvent::PermissionWriteDenied,
        AuditEvent::MfaEnrollmentStarted,
        AuditEvent::MfaEnrollmentConfirmed,
        AuditEvent::MfaChallengeSucceeded,
        AuditEvent::MfaChallengeFailed,
        AuditEvent::MfaRecoveryCodeUsed,
        AuditEvent::MfaRecoveryCodesRegenerated,
        AuditEvent::MfaStepUpSucceeded,
        AuditEvent::MfaStepUpDenied,
        AuditEvent::UnauthorizedAccess,
        AuditEvent::FileDownloaded,
        AuditEvent::FileAccessDenied,
        AuditEvent::AuditExportRequested,
        AuditEvent::AuditExportDownloaded,
        AuditEvent::AuditExportRevoked,
        AuditEvent::PersonnelSmsExportAttemptBlocked,
    ];

    /**
     * Client contact and consent changes, Personnel served-client access, Personnel SMS recipient
     * selection/confirmation/outcome/charges, and contact-export-shaped denials (§12.4.10).
     */
    private const PRIVACY_PREFERRED = [
        AuditEvent::ClientCreated,
        AuditEvent::ClientUpdated,
        AuditEvent::ClientConsentOptedIn,
        AuditEvent::ClientConsentOptedOut,
        AuditEvent::PersonnelServedClientsViewed,
        AuditEvent::AppointmentTransferred,
        AuditEvent::QueueEntryTransferred,
        AuditEvent::PersonnelSmsPreviewed,
        AuditEvent::PersonnelSmsCampaignCreated,
        AuditEvent::PersonnelSmsCampaignConfirmed,
        AuditEvent::PersonnelSmsCampaignCancelled,
        AuditEvent::PersonnelSmsRecipientSuppressed,
        AuditEvent::PersonnelSmsDeliverySucceeded,
        AuditEvent::PersonnelSmsDeliveryFailed,
        AuditEvent::PersonnelSmsDeliveryDeadLettered,
        AuditEvent::PersonnelSmsBillingEntryCreated,
        AuditEvent::PersonnelSmsExportAttemptBlocked,
    ];
}
