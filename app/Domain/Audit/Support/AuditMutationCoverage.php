<?php

declare(strict_types=1);

namespace App\Domain\Audit\Support;

use App\Domain\Audit\Enums\AuditEvent;

/**
 * Enforced audit-coverage registry (Plan §70, §80; Phase 19 Increment 5).
 *
 * Maps every IMPLEMENTED mutating (non-GET) `/api/v1` route to the typed
 * {@see AuditEvent}(s) its handler emits on the committed path — or, for the few
 * mutations that deliberately emit no dedicated typed event, to an explicit
 * exemption reason. The AuditMutationCoverageTest guard fails CI
 * when a mutating route is neither AUDITED nor EXEMPT (an unmapped mutation), when
 * an entry is stale (route removed), when the two sets overlap, or when an AUDITED
 * event string is not a real `AuditEvent` case.
 *
 * The event strings are the actual emission sites in `app/Domain/**` + controllers
 * (see the Phase-19 proof inventory); per-transition runtime emission + redaction
 * are proven by the domain coverage/redaction suites (AuditEventCoverageTest,
 * AuditRedactionTest, and each domain's API tests), not re-driven here.
 *
 * Deferred domains (notifications/reports 21N, SMS 21S) own NO implemented mutating
 * route yet, so they are intentionally absent — this registry never claims a
 * future-domain emission. Phase 20F added the compensation-CONFIGURATION routes below;
 * the compensation EARNING/payout routes (20G/20H) remain absent.
 */
final class AuditMutationCoverage
{
    /**
     * Route name => the typed audit action string(s) its handler emits (happy path;
     * success/failure variants of the same transition are both listed).
     *
     * @var array<string, list<string>>
     */
    public const AUDITED = [
        // --- Appointments (Phase 16A) --------------------------------------
        'appointments.store' => ['appointment.created'],
        'appointments.assign' => ['appointment.assigned'],
        'appointments.cancel' => ['appointment.cancelled'],
        'appointments.check-in' => ['appointment.checked_in'],
        'appointments.no-show' => ['appointment.no_show'],
        'appointments.queue.store' => ['appointment.queued', 'queue_entry.created'],
        'appointments.reschedule' => ['appointment.rescheduled'],
        'appointments.transfer' => ['appointment.transferred'],

        // --- Audit flagged-event review workflow (Phase 19) ----------------
        'audit-flagged-events.store' => ['audit.flagged_event.created'],
        'audit-flagged-events.start-review' => ['audit.flagged_event.review_started'],
        'audit-flagged-events.resolve' => ['audit.flagged_event.resolved'],
        'audit-flagged-events.dismiss' => ['audit.flagged_event.dismissed'],
        'audit-flagged-events.reopen' => ['audit.flagged_event.reopened'],

        // --- Audit exports (Phase 19; ADR-010) -----------------------------
        'audit-exports.store' => ['audit_export.requested'],
        'audit-exports.revoke' => ['audit_export.revoked'],

        // --- Authentication + MFA (Phase 5 / R3) ---------------------------
        'auth.logout' => ['logout'],
        'auth.magic-link.request' => ['login_link_requested', 'login_link_denied'],
        'auth.magic-link.verify' => ['login_success', 'login_link_failed'],
        'auth.mfa.enroll' => ['mfa.enrollment_started'],
        'auth.mfa.confirm' => ['mfa.enrollment_confirmed'],
        'auth.mfa.challenge' => ['mfa.challenge_succeeded', 'mfa.challenge_failed'],
        'auth.mfa.recovery-challenge' => ['mfa.recovery_code_used'],
        'auth.mfa.recovery-codes.regenerate' => ['mfa.recovery_codes_regenerated'],

        // --- Branch lifecycle + day (Phase 7 / 16B / 18B) ------------------
        'branches.store' => ['branch.created'],
        'branches.update' => ['branch.profile_updated'],
        'branches.archive' => ['branch.archived'],
        'branches.operating-hours.update' => ['branch.operating_hours_updated'],
        'branches.day.open' => ['branch.day_opened', 'branch.day_reopened'],
        'branches.day.close' => ['branch.day_closed'],

        // --- Branch cash-up (Phase 18B) ------------------------------------
        'cash-ups.draft' => ['cash_up.draft_updated'],
        'cash-ups.submit' => ['cash_up.submitted'],
        'cash-ups.approve' => ['cash_up.approved'],
        'cash-ups.reject' => ['cash_up.rejected'],
        'cash-ups.request-correction' => ['cash_up.correction_requested'],
        'cash-ups.resubmit' => ['cash_up.resubmitted'],
        'cash-ups.lock' => ['cash_up.locked'],

        // --- Compensation configuration (Phase 20F) ------------------------
        // Every event each route's handler emits on the COMMITTED path, including the events the
        // same transaction emits for the referencing plan's commission rule (a rule has no
        // independent lifecycle) and for an incumbent that gets superseded/ended by an approval.
        // Configuration only — none of these is an earned/paid money fact (20G/20H own those).
        'commission-rules.store' => ['commission_rule.created'],
        'commission-rules.draft.update' => ['commission_rule.updated_draft'],
        'compensation-plans.store' => ['compensation.plan.created'],
        'compensation-plans.draft.update' => ['compensation.plan.updated_draft'],
        'compensation-plans.submit' => ['compensation.plan.submitted', 'commission_rule.submitted'],
        // Approve is the one route that can emit five events in a single transaction: the approval,
        // the CRITICAL backdated variant, the incumbent's supersede, and the rule's approve/end.
        'compensation-plans.approve' => [
            'compensation.plan.approved',
            'compensation.plan.backdated_change_approved',
            'compensation.plan.superseded',
            'commission_rule.approved',
            'commission_rule.ended',
        ],
        'compensation-plans.reject' => ['compensation.plan.rejected', 'commission_rule.rejected'],
        'compensation-plans.cancel' => ['compensation.plan.cancelled', 'commission_rule.cancelled'],

        // --- Clients (Phase 15A) -------------------------------------------
        'clients.store' => ['client.created'],
        'clients.update' => ['client.updated'],
        'clients.sms-consent.update' => ['client_consent.opted_in', 'client_consent.opted_out'],

        // --- Catalogue: services + categories + eligibility (Phase 15A) ----
        'service-categories.store' => ['service_category.created'],
        'service-categories.update' => ['service_category.updated'],
        'services.store' => ['service.created'],
        'services.update' => ['service.updated'],
        'services.archive' => ['service.archived'],
        'services.eligibility.store' => ['personnel_eligibility.assigned'],
        'services.eligibility.destroy' => ['personnel_eligibility.revoked'],

        // --- Personnel availability (Phase 15B) ----------------------------
        'staff.availability.update' => ['personnel_availability.updated'],
        'staff.availability.emergency-unavailable' => ['personnel_availability.emergency_unavailable'],

        // --- Files (Phase 10F) ---------------------------------------------
        'files.store' => ['file.upload_accepted', 'file.upload_rejected'],

        // --- Finance disputes (Phase 18B) ----------------------------------
        'finance-disputes.store' => ['finance_dispute.opened'],
        'finance-disputes.start-review' => ['finance_dispute.review_started'],
        'finance-disputes.resolve' => ['finance_dispute.resolved'],
        'finance-disputes.reject' => ['finance_dispute.rejected'],

        // --- Finance exports (Phase 18B) -----------------------------------
        'finance-exports.store' => ['finance_export.requested'],
        'finance-exports.download-link' => ['finance_export.downloaded'],
        'finance-exports.revoke' => ['finance_export.revoked'],

        // --- Invoicing (Phase 17 / 18B) ------------------------------------
        'invoices.store' => ['invoice.created'],
        'invoices.update' => ['invoice.updated_draft'],
        'invoices.finalize' => ['invoice.finalized'],
        'invoices.adjust' => ['invoice.adjusted'],
        'invoices.void' => ['invoice.void_requested'],
        'invoices.void.execute' => ['invoice.voided'],
        'invoices.void.reject' => ['invoice.void_rejected'],

        // --- Merchant-client payments (Phase 18A / 18B) --------------------
        'payment-recording-groups.store' => ['customer_payment.recorded'],
        'payment-recording-groups.exception' => ['customer_payment.recorded_exception'],
        'payment-recording-groups.validate' => ['customer_payment.validated', 'receipt.issued'],
        'payment-recording-groups.reject' => ['customer_payment.rejected'],
        'payment-recording-groups.request-correction' => ['customer_payment.correction_requested'],
        'payment-recording-groups.resubmit' => ['customer_payment.resubmitted'],
        'payment-records.correct-reference' => ['customer_payment.reference_corrected'],
        'payment-reference-checks.override' => ['customer_payment.duplicate_override_approved'],

        // --- Financial period locks + reopen (Phase 18B) -------------------
        'period-locks.store' => ['financial_period.locked'],
        'period-locks.reopen' => ['financial_period.reopen_requested'],
        'period-locks.reopen.approve' => ['financial_period.reopen_approved'],
        'period-locks.reopen.execute' => ['financial_period.reopened'],

        // --- Queue + walk-ins (Phase 16B / 16C) ----------------------------
        'queue.configuration.update' => ['queue.configuration.updated'],
        'queue.assign' => ['queue_entry.assigned'],
        'queue.call' => ['queue_entry.called'],
        'queue.start' => ['queue_entry.started', 'service_session.started'],
        'queue.complete' => ['queue_entry.completed', 'service_session.completed'],
        'queue.transfer' => ['queue_entry.transferred'],
        'queue.reorder' => ['queue_entry.reordered'],
        'queue.cancel' => ['queue_entry.cancelled'],
        'queue.no-show' => ['queue_entry.no_show'],
        'walk-ins.store' => ['walk_in.created', 'queue_entry.created'],

        // --- Service sessions (Phase 16C) ----------------------------------
        'service-sessions.cancel' => ['service_session.cancelled'],

        // --- Receipts (Phase 18B) ------------------------------------------
        'receipts.reissue' => ['receipt.reissued'],
        'receipts.download-link' => ['receipt.downloaded'],

        // --- Refunds (Phase 18B) -------------------------------------------
        'refunds.store' => ['refund.requested'],
        'refunds.approve' => ['refund.approved'],
        'refunds.finalize' => ['refund.finalized'],
        'refunds.reject' => ['refund.rejected'],

        // --- Staff invitations + lifecycle (Phase 7) -----------------------
        'staff-invitations.store' => ['invitation.created'],
        'staff-invitations.resend' => ['invitation.resent'],
        'staff-invitations.revoke' => ['invitation.revoked'],
        'staff-invitations.accept' => ['invitation.accepted', 'membership.created'],
        'staff.activate' => ['membership.activated'],
        'staff.suspend' => ['membership.suspended'],
        'staff.deactivate' => ['membership.deactivated'],

        // --- Permission overrides (Phase 8) --------------------------------
        'staff.permissions.store' => ['permission.override.created', 'permission.override.updated'],
        'staff.permissions.destroy' => ['permission.override.revoked'],

        // --- Merchant self-registration (Phase 6) --------------------------
        'merchant-registration.self-register' => ['membership.created'],

        // --- Merchant subscription self-service (Phase 20B) ----------------
        'subscription.scheduled-plan-change.store' => ['subscription.plan_change_scheduled'],
        'subscription.scheduled-plan-change.cancel' => ['subscription.plan_change_cancelled'],
        'subscription-invoices.pdf.generate' => ['subscription_invoice.pdf_generated'],

        // --- Platform merchant governance (Phase 20B) ----------------------
        'platform.merchants.suspend' => ['merchant.suspended'],
        'platform.merchants.reactivate' => ['merchant.reactivated'],
        'platform.merchants.deactivate' => ['merchant.deactivated'],

        // --- Platform billing catalogue governance (Phase 20A) -------------
        'platform.settings.update' => ['platform_settings.updated'],
        'platform.billing-settings.update' => ['platform_billing.settings_updated'],
        'platform.plans.store' => ['subscription_plan.created'],
        'platform.plans.update' => ['subscription_plan.metadata_updated'],
        'platform.plans.retire' => ['subscription_plan.retired'],
        'platform.plans.prices.store' => ['subscription_plan_price.created', 'subscription_plan_price.scheduled'],
        'platform.plan-prices.cancel' => ['subscription_plan_price.cancelled'],
        'platform.plans.entitlements.update' => ['plan_entitlement.updated'],
        'platform.preferred-personnel-fee-rules.store' => ['preferred_personnel_fee_rule.created'],
        'platform.preferred-personnel-fee-rules.approve' => ['preferred_personnel_fee_rule.approved'],
        'platform.preferred-personnel-fee-rules.supersede' => ['preferred_personnel_fee_rule.superseded'],
        'platform.preferred-personnel-fee-rules.cancel' => ['preferred_personnel_fee_rule.cancelled'],
        // Phase 20C — promotional discounts (approve of a current window also activates).
        'platform.promotional-discounts.store' => ['promotion.created'],
        'platform.promotional-discounts.update' => ['promotion.draft_updated'],
        'platform.promotional-discounts.approve' => ['promotion.approved', 'promotion.activated'],
        'platform.promotional-discounts.pause' => ['promotion.paused'],
        'platform.promotional-discounts.resume' => ['promotion.resumed'],
        'platform.promotional-discounts.cancel' => ['promotion.cancelled'],
        // Phase 20C — free-period offers (approval always yields scheduled; activation is scheduler-driven).
        'platform.free-period-offers.store' => ['free_period_offer.created'],
        'platform.free-period-offers.update' => ['free_period_offer.draft_updated'],
        'platform.free-period-offers.approve' => ['free_period_offer.approved'],
        'platform.free-period-offers.pause' => ['free_period_offer.paused'],
        'platform.free-period-offers.resume' => ['free_period_offer.resumed'],
        'platform.free-period-offers.cancel' => ['free_period_offer.cancelled'],
        // Phase 20E — percentage platform-fee configuration governance (Super-Admin platform mutations).
        'platform.platform-fee-configurations.store' => ['platform_fee.configuration_created'],
        'platform.platform-fee-configurations.update' => ['platform_fee.configuration_updated'],
        'platform.platform-fee-configurations.approve' => ['platform_fee.configuration_approved'],
        'platform.platform-fee-configurations.supersede' => ['platform_fee.configuration_superseded'],
        'platform.platform-fee-configurations.cancel' => ['platform_fee.configuration_cancelled'],
        // Phase 20E — percentage platform-fee dispute workflow (merchant scope).
        'platform-fee-disputes.store' => ['platform_fee.dispute_created'],
        'platform-fee-disputes.review' => ['platform_fee.dispute_review_started'],
        'platform-fee-disputes.resolve' => ['platform_fee.dispute_resolved'],
        'platform-fee-disputes.reject' => ['platform_fee.dispute_rejected'],
    ];

    /**
     * Mutating routes that deliberately emit NO dedicated typed audit event, each
     * with its reason. Adding a new mutating route forces a conscious choice here or
     * in AUDITED (the coverage guard fails on any unmapped mutation).
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'files.download-link' => 'Signed-link issuance (authorization re-check only); the download itself is audited (file.downloaded) at stream time on the files.download GET route.',
        'merchant-registration.first-time-setup.store' => 'Owner onboarding completion (profile + branch defaults); the founding membership was already audited (membership.created) at self-registration — no dedicated typed event in the current catalogue.',
        'service-sessions.notes' => 'Service-note text edit (sanitised free text); no dedicated typed audit event in the current catalogue.',
        'audit-exports.download-link' => 'Signed-link issuance (authorization re-check only); download accounting + audit_export.downloaded are recorded on the audit-exports.download stream (GET), not here.',
    ];

    /** @return list<string> */
    public static function auditedRoutes(): array
    {
        return array_keys(self::AUDITED);
    }

    /** @return list<string> */
    public static function exemptRoutes(): array
    {
        return array_keys(self::EXEMPT);
    }

    /**
     * Every route name classified by this registry (audited ∪ exempt).
     *
     * @return list<string>
     */
    public static function classifiedRoutes(): array
    {
        return array_merge(self::auditedRoutes(), self::exemptRoutes());
    }

    /**
     * Distinct audit action strings referenced by AUDITED.
     *
     * @return list<string>
     */
    public static function referencedEvents(): array
    {
        $events = [];
        foreach (self::AUDITED as $actions) {
            foreach ($actions as $action) {
                $events[$action] = true;
            }
        }

        return array_keys($events);
    }

    /**
     * Valid AuditEvent action-string values.
     *
     * @return list<string>
     */
    public static function validEventValues(): array
    {
        return array_map(static fn (AuditEvent $e): string => $e->value, AuditEvent::cases());
    }
}
