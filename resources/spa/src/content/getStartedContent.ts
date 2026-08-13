import type { RoleIdentity } from '@/types/roles';

/**
 * Guided get-started checklists (Plan §27.2; Scope §3.2 "Indicative get-started
 * checklists by role"). Item labels come verbatim from the Scope table. A final
 * legal-acknowledgement step is appended for every role (Scope §3, B19; the task
 * legal-acknowledgement obligation) — it is the explicit, non-prefilled
 * acknowledgement gate placed in the legitimate first-access surface.
 *
 * Item ids are stable and versioned by `CHECKLIST_SCHEMA_VERSION`; persistence
 * stores only these ids + completion/dismissal state (see getStartedStore).
 */
export const CHECKLIST_SCHEMA_VERSION = 1;

export type GetStartedItemKind = 'action' | 'acknowledge';

export interface GetStartedItem {
  /** Stable identifier (persisted). Never reuse across meanings. */
  id: string;
  /** Verbatim checklist label. */
  label: string;
  /** Live deep-link route name; omitted when the target is a future phase. */
  routeName?: string;
  /** Owning phase for not-yet-live steps (truthful, no fake routes). */
  phase?: string;
  kind: GetStartedItemKind;
  /** Server-observed items cannot be manually claimed complete by the owner. */
  completion?: 'manual' | 'server';
  /** Role responsible for the underlying work; display-only and never an authority grant. */
  responsibleRole?: string;
}

const ACKNOWLEDGE: GetStartedItem = {
  id: 'legal-acknowledgement',
  label: 'Review and acknowledge your Terms of Service, Privacy Policy, and Data Policy',
  kind: 'acknowledge',
};

const superAdministrator: GetStartedItem[] = [
  { id: 'configure-billing-mode', label: 'Configure billing mode', phase: 'Phase 20A', kind: 'action' },
  { id: 'configure-plans-entitlements', label: 'Configure plans and entitlements', phase: 'Phase 20A', kind: 'action' },
  { id: 'configure-free-period-grace', label: 'Configure free-period and grace settings', phase: 'Phase 20A', kind: 'action' },
  { id: 'configure-preferred-personnel-fee', label: 'Configure preferred-personnel fee rule', phase: 'Phase 20A', kind: 'action' },
  { id: 'configure-mpesa', label: 'Configure M-Pesa integration', phase: 'Phase 20D', kind: 'action' },
  { id: 'review-registration-monitoring', label: 'Review registration monitoring', phase: 'Phase 20A', kind: 'action' },
  ACKNOWLEDGE,
];

const merchantAdministrator: GetStartedItem[] = [
  { id: 'verify-email', label: 'Verify email and complete mandatory setup', routeName: 'merchant.dashboard', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'choose-subscription-plan', label: 'Choose plan and billing interval', routeName: 'merchant.subscription-plan', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'confirm-merchant-profile', label: 'Confirm merchant profile and logo', routeName: 'merchant.merchant-profile', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'create-first-branch', label: 'Create the first entitled branch', routeName: 'merchant.branches', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'invite-branch-manager-hr', label: 'Invite and activate the initial Branch Manager and HR', routeName: 'merchant.staff', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'confirm-billing-mpesa-phone', label: 'Confirm billing/M-Pesa phone', routeName: 'merchant.merchant-profile', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'operational-role-readiness', label: 'Activate Branch Manager, HR, Finance, and Front Office owners', routeName: 'merchant.staff', kind: 'action', completion: 'server', responsibleRole: 'Merchant Administrator' },
  { id: 'review-first-daily-reports', label: 'Review the first day-close, cash-up, validation, receipt, and payout workflows', phase: 'External Gate W', kind: 'action', completion: 'server', responsibleRole: 'Branch Manager and Finance' },
  ACKNOWLEDGE,
];

const branchManager: GetStartedItem[] = [
  { id: 'confirm-branch-profile', label: 'Confirm branch profile', routeName: 'branch.branch-profile', kind: 'action' },
  { id: 'set-operating-hours-calendar', label: 'Set operating hours and calendar', routeName: 'branch.branch-calendar', kind: 'action' },
  { id: 'build-service-catalogue', label: 'Build the service catalogue', routeName: 'branch.services', kind: 'action' },
  { id: 'set-service-pricing-durations', label: 'Set service pricing and durations', routeName: 'branch.services', kind: 'action' },
  { id: 'open-branch-day', label: 'Open the branch day', routeName: 'branch.branch-day', kind: 'action' },
  ACKNOWLEDGE,
];

const humanResource: GetStartedItem[] = [
  { id: 'invite-staff', label: 'Invite staff', routeName: 'hr.invitations', kind: 'action' },
  { id: 'set-service-eligibility', label: 'Set service eligibility', routeName: 'hr.eligibility', kind: 'action' },
  { id: 'set-availability', label: 'Set availability', routeName: 'hr.availability', kind: 'action' },
  { id: 'configure-compensation-models', label: 'Configure personnel compensation models', phase: 'Phase 20F', kind: 'action' },
  { id: 'review-missing-compensation', label: 'Review missing-compensation warnings', phase: 'Phase 20F', kind: 'action' },
  ACKNOWLEDGE,
];

const finance: GetStartedItem[] = [
  { id: 'review-pending-validations', label: 'Review pending validations', phase: 'Phase 18B', kind: 'action' },
  { id: 'learn-validation-workflow', label: 'Learn the validation workflow', phase: 'Phase 18B', kind: 'action' },
  { id: 'review-cash-up-submissions', label: 'Review cash-up submissions', phase: 'Phase 18B', kind: 'action' },
  { id: 'review-payout-runs', label: 'Review payout runs', phase: 'Phase 20H', kind: 'action' },
  { id: 'review-period-lock-controls', label: 'Review period-lock controls', phase: 'Phase 18B', kind: 'action' },
  ACKNOWLEDGE,
];

const frontOffice: GetStartedItem[] = [
  { id: 'register-a-client', label: 'Register a client', routeName: 'front-office.clients.create', kind: 'action' },
  { id: 'book-an-appointment', label: 'Book an appointment', routeName: 'front-office.appointments.create', kind: 'action' },
  { id: 'start-a-walk-in', label: 'Start a walk-in', routeName: 'front-office.walk-in', kind: 'action' },
  { id: 'assign-personnel', label: 'Assign personnel', phase: 'Phase 16B', kind: 'action' },
  { id: 'create-an-invoice', label: 'Create an invoice', routeName: 'front-office.invoices.create', kind: 'action' },
  { id: 'record-a-payment', label: 'Record a payment', routeName: 'front-office.payments', kind: 'action' },
  { id: 'confirm-receipt-issuance', label: 'Confirm receipt issuance', phase: 'Phase 18B', kind: 'action' },
  ACKNOWLEDGE,
];

const personnel: GetStartedItem[] = [
  { id: 'review-my-earnings', label: 'Review My Earnings', phase: 'Phase 20H', kind: 'action' },
  { id: 'review-compensation-terms', label: 'Review compensation terms', phase: 'Phase 20H', kind: 'action' },
  { id: 'acknowledge-terms', label: 'Acknowledge terms', kind: 'acknowledge' },
  { id: 'view-served-clients', label: 'View served clients', phase: 'Phase 15A', kind: 'action' },
  { id: 'send-a-permitted-sms', label: 'Send a permitted SMS', phase: 'Phase 21S', kind: 'action' },
];

const audit: GetStartedItem[] = [
  { id: 'review-flagged-events', label: 'Review flagged events', phase: 'Phase 19', kind: 'action' },
  { id: 'learn-branch-scoped-filtering', label: 'Learn branch-scoped filtering', phase: 'Phase 19', kind: 'action' },
  { id: 'review-masked-client-context', label: 'Review masked client context', phase: 'Phase 19', kind: 'action' },
  { id: 'review-export-permissions', label: 'Review export permissions', phase: 'Phase 19', kind: 'action' },
  ACKNOWLEDGE,
];

export const GET_STARTED_CHECKLISTS: Record<RoleIdentity, GetStartedItem[]> = {
  super_administrator: superAdministrator,
  merchant_administrator: merchantAdministrator,
  merchant_branch: branchManager,
  merchant_human_resource: humanResource,
  merchant_finance: finance,
  merchant_front_office: frontOffice,
  merchant_personnel: personnel,
  merchant_audit: audit,
};

export function getStartedChecklist(identity: RoleIdentity): GetStartedItem[] {
  return GET_STARTED_CHECKLISTS[identity] ?? [];
}
