import type { RoleIdentity } from '@/types/roles';

/**
 * Canonical, typed role-navigation registry (Phase 11, Plan §27.2; Scope §3.3,
 * §4.x, §12.6, finance/branch nav lists). One stable source of truth for every
 * role's primary navigation. The version-controlled fixture
 * `docs/frontend/navigation/role-navigation.yaml` mirrors this exactly and the
 * parity test (`roleNavigation.spec.ts`) fails on any drift.
 *
 * Rules (binding):
 *  - `live` items point only to a real, parameter-free route (`routeName` set).
 *  - `planned` items have NO route (no fake/dead links); they carry the exact
 *    owning phase and may be rendered as clearly-disabled only because the
 *    authoritative navigation requires their presence.
 *  - `permission` (when set) drives PermissionGate UX visibility only — the
 *    backend is the security boundary (Plan §2.1 rule 10).
 *  - Forbidden capabilities are NEVER listed for any role: Super-Admin merchant
 *    creation/operations, Merchant-Admin service/pricing/commission config,
 *    Branch-Manager payment validation/refunds/locks, HR cross-branch/finance,
 *    Front-Office payment validation/receipt issuance, Personnel contact export,
 *    Audit operational mutation (Plan §2.1 rules 5–6, §19.4; Scope §4.x).
 */
export type NavAvailability = 'live' | 'planned';

export interface NavItem {
  /** Stable kebab-case identifier (used by tests and the YAML fixture). */
  key: string;
  /** Verbatim navigation label (sentence case; from the authoritative Scope). */
  label: string;
  /** Resolved route name — present only for `live` items. */
  routeName?: string;
  /** Permission key gating UX visibility (Plan §19.2) — optional. */
  permission?: string;
  /** Owning phase that delivers (or delivered) this surface. */
  phase: string;
  availability: NavAvailability;
}

const platform: NavItem[] = [
  { key: 'platform.overview', label: 'Overview', routeName: 'platform.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'platform.get-started', label: 'Get started', routeName: 'platform.get-started', phase: 'Phase 11', availability: 'live' },
  // Registration monitoring / merchant governance is Phase 20B (Plan §47 excludes it from
  // 20A; perms platform.registration_monitor.view + platform.merchant.* are owning_phase 20B).
  { key: 'platform.merchants', label: 'Merchant directory', routeName: 'platform.registration-monitoring', permission: 'platform.merchant.view', phase: 'Phase 20B', availability: 'live' },
  { key: 'platform.registration-monitoring', label: 'Registration monitoring', routeName: 'platform.registration-monitoring', permission: 'platform.registration_monitor.view', phase: 'Phase 20B', availability: 'live' },
  // Phase 20A delivers billing settings, plans/prices/entitlements, general settings and the
  // preferred-personnel fee rule as one coherent screen (accessible tabs). Each label routes to
  // that consolidated surface — no dead links, no duplicate top-level screens.
  { key: 'platform.billing-settings', label: 'Billing settings', routeName: 'platform.billing-settings', permission: 'platform.billing_settings.view', phase: 'Phase 20A', availability: 'live' },
  { key: 'platform.plans', label: 'Plans and entitlements', routeName: 'platform.billing-settings', permission: 'platform.plan.view', phase: 'Phase 20A', availability: 'live' },
  { key: 'platform.promotions', label: 'Promotions and free periods', routeName: 'platform.promotions', permission: 'platform.promotion.manage', phase: 'Phase 20C', availability: 'live' },
  { key: 'platform.preferred-personnel-fee', label: 'Preferred-personnel fee rule', routeName: 'platform.billing-settings', permission: 'platform.preferred_personnel_fee.manage', phase: 'Phase 20A', availability: 'live' },
  { key: 'platform.platform-fees', label: 'Platform fees', routeName: 'platform.billing-settings', permission: 'platform.platform_fee.configure', phase: 'Phase 20E', availability: 'live' },
  { key: 'platform.wallet-config', label: 'Wallet configuration', permission: 'platform.wallet_configuration.manage', phase: 'Phase 20D-W', availability: 'planned' },
  { key: 'platform.billing-reconciliation', label: 'Billing reconciliation', permission: 'platform.billing_reconciliation.view', phase: 'Phase 20D-W', availability: 'planned' },
  { key: 'platform.audit', label: 'Platform audit', permission: 'platform.audit.view', phase: 'Phase 19', availability: 'planned' },
  { key: 'platform.reports', label: 'Platform reports', permission: 'platform.audit.view', phase: 'Phase 21N', availability: 'planned' },
  { key: 'platform.settings', label: 'Platform settings', routeName: 'platform.billing-settings', permission: 'platform.settings.view', phase: 'Phase 20A', availability: 'live' },
];

const merchantAdministrator: NavItem[] = [
  { key: 'merchant.overview', label: 'Overview', routeName: 'merchant.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'merchant.get-started', label: 'Get started', routeName: 'merchant.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'merchant.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'merchant.dashboard', label: 'Dashboard', routeName: 'merchant.dashboard', phase: 'Phase 6', availability: 'live' },
  // REM-SCR-002A — the Plan §27.3 Merchant Administrator "merchant profile" launch screen. Gated on
  // the canonical read key; the write is separately gated server-side by merchant.profile.update.
  { key: 'merchant.profile', label: 'Business profile', routeName: 'merchant.profile', permission: 'merchant.profile.view', phase: 'Phase 23', availability: 'live' },
  { key: 'merchant.branches', label: 'Branches', routeName: 'branch.list', permission: 'merchant.branch.view_all', phase: 'Phase 7', availability: 'live' },
  { key: 'merchant.period-reopen-approvals', label: 'Period-reopen approvals', routeName: 'merchant.period-reopen-approvals', permission: 'merchant.period_reopen.approve_exception', phase: 'Phase 18B', availability: 'live' },
  { key: 'merchant.subscription', label: 'Subscription and billing', routeName: 'merchant.subscription', permission: 'merchant.subscription.view', phase: 'Phase 20B', availability: 'live' },
  { key: 'merchant.plan', label: 'Plan management', routeName: 'merchant.plan', permission: 'merchant.subscription.plan_change', phase: 'Phase 20B', availability: 'live' },
  { key: 'merchant.invoices', label: 'Subscription invoices', routeName: 'merchant.invoices', permission: 'merchant.subscription.invoice.view', phase: 'Phase 20B', availability: 'live' },
  { key: 'merchant.platform-fees', label: 'Platform fees', routeName: 'merchant.platform-fees', permission: 'platform_fee.view', phase: 'Phase 20E', availability: 'live' },
  { key: 'merchant.reports', label: 'Reports', permission: 'merchant.report.view_all_branches', phase: 'Phase 21N', availability: 'planned' },
  // Phase retag (Phase 20F Increment 1): was 'Phase 20F', but the permission
  // merchant.compensation_summary.view is owning_phase: Phase 20H in the plan-parity-tested
  // docs/auth/permission-matrix.yaml, and Plan §80/§63 place the Merchant-Administrator
  // compensation summary in Phase 20H (earnings surface). Matrix + Plan win over the nav tag.
  // Not built in Phase 20F. See docs/proof/phase-20f.md §F10.
  { key: 'merchant.compensation-summary', label: 'Compensation summary', routeName: 'merchant.compensation-summary', permission: 'merchant.compensation_summary.view', phase: 'Phase 20H', availability: 'live' },
];

// Verbatim final-launch Merchant Branch navigation (Scope §, "Final
// production-launch Merchant Branch navigation"). Branch management drill-down
// (profile, operating hours, calendar) is reached today via the live Branches
// directory; remaining operational items are owned by later phases.
const branchManager: NavItem[] = [
  { key: 'branch.overview', label: 'Branch overview', routeName: 'branch.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'branch.get-started', label: 'Get started', routeName: 'branch.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'branch.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'branch.directory', label: 'Branches', routeName: 'branch.list', permission: 'branch.profile.view', phase: 'Phase 7', availability: 'live' },
  { key: 'branch.services', label: 'Services', routeName: 'branch.services', permission: 'service.view', phase: 'Phase 15A', availability: 'live' },
  { key: 'branch.personnel-schedule', label: 'Personnel schedule', routeName: 'branch.personnel-schedule', permission: 'branch.dashboard.view', phase: 'Phase 15B', availability: 'live' },
  { key: 'branch.queue', label: 'Queue', routeName: 'branch.queue', permission: 'branch.dashboard.view', phase: 'Phase 16B', availability: 'live' },
  { key: 'branch.appointments', label: 'Appointments', routeName: 'branch.appointments', permission: 'branch.dashboard.view', phase: 'Phase 16A', availability: 'live' },
  { key: 'branch.service-sessions', label: 'Service sessions', phase: 'Phase 16C', availability: 'planned' },
  { key: 'branch.invoices', label: 'Invoices', phase: 'Phase 17', availability: 'planned' },
  { key: 'branch.payments', label: 'Payments', phase: 'Phase 18A', availability: 'planned' },
  { key: 'branch.receipts', label: 'Receipts', phase: 'Phase 18B', availability: 'planned' },
  { key: 'branch.day', label: 'Day opening and closing', permission: 'branch.day.open', phase: 'Phase 16B', availability: 'planned' },
  { key: 'branch.cash-up', label: 'Cash-up and reconciliation', routeName: 'branch.cash-up', permission: 'branch.cash_up.submit', phase: 'Phase 18B', availability: 'live' },
  { key: 'branch.platform-fees', label: 'Platform fees', routeName: 'branch.platform-fees', permission: 'platform_fee.view', phase: 'Phase 20E', availability: 'live' },
  { key: 'branch.reports', label: 'Reports', permission: 'branch.report.view', phase: 'Phase 21N', availability: 'planned' },
  { key: 'branch.audit-logs', label: 'Audit logs', phase: 'Phase 19', availability: 'planned' },
];

const humanResource: NavItem[] = [
  { key: 'hr.overview', label: 'Overview', routeName: 'hr.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'hr.get-started', label: 'Get started', routeName: 'hr.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'hr.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'hr.staff', label: 'Staff', routeName: 'hr.staff', permission: 'staff.view', phase: 'Phase 7', availability: 'live' },
  { key: 'hr.invitations', label: 'Invitations', routeName: 'hr.invitations', permission: 'staff.invite', phase: 'Phase 7', availability: 'live' },
  { key: 'hr.permission-preview', label: 'Permission preview', routeName: 'hr.permission-preview', permission: 'staff.role.assign', phase: 'Phase 7', availability: 'live' },
  { key: 'hr.eligibility', label: 'Service eligibility', routeName: 'hr.eligibility', permission: 'personnel.eligibility.manage', phase: 'Phase 15A', availability: 'live' },
  { key: 'hr.availability', label: 'Availability', routeName: 'hr.availability', permission: 'personnel.availability.manage', phase: 'Phase 15B', availability: 'live' },
  { key: 'hr.compensation', label: 'Compensation', routeName: 'hr.compensation', permission: 'compensation.plan.view', phase: 'Phase 20F', availability: 'live' },
  { key: 'hr.payout-runs', label: 'Payout runs', routeName: 'hr.payout-runs', permission: 'payout_run.create', phase: 'Phase 20H', availability: 'live' },
];

// Verbatim Final Production-Launch Finance Navigation (Scope).
const finance: NavItem[] = [
  { key: 'finance.overview', label: 'Finance overview', routeName: 'finance.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'finance.get-started', label: 'Get started', routeName: 'finance.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'finance.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'finance.pending-validations', label: 'Pending validations', routeName: 'finance.pending-validations', permission: 'customer_payment.validate', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.invoices', label: 'Invoices', routeName: 'finance.invoices', permission: 'invoice.view', phase: 'Phase 17', availability: 'live' },
  { key: 'finance.payment-records', label: 'Payment records', routeName: 'finance.payment-records', permission: 'customer_payment.view', phase: 'Phase 18A', availability: 'live' },
  { key: 'finance.receipts', label: 'Receipts', routeName: 'finance.receipts', permission: 'receipt.view', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.disputes', label: 'Disputes', routeName: 'finance.disputes', permission: 'finance_dispute.manage', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.refunds', label: 'External refunds', routeName: 'finance.refunds', permission: 'refund.create', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.cash-up', label: 'Cash-up and reconciliation', routeName: 'finance.cash-up', permission: 'cash_up.view', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.periods', label: 'Financial periods', routeName: 'finance.periods', permission: 'period_lock.create', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.payout-runs', label: 'Payout runs', routeName: 'finance.payout-runs', permission: 'payout_run.verify', phase: 'Phase 20H', availability: 'live' },
  { key: 'finance.earnings-queries', label: 'Earnings queries', routeName: 'finance.earnings-queries', permission: 'earnings_query.respond', phase: 'Phase 20H', availability: 'live' },
  { key: 'finance.liabilities', label: 'Commission and salary liabilities', routeName: 'finance.liabilities', permission: 'compensation.liability.view', phase: 'Phase 20G', availability: 'live' },
  { key: 'finance.subscription-billing', label: 'Subscription billing', permission: 'subscription.payment_attempts.view', phase: 'Phase 20B', availability: 'planned' },
  { key: 'finance.reports', label: 'Finance reports', phase: 'Phase 21N', availability: 'planned' },
  { key: 'finance.exports', label: 'Exports', routeName: 'finance.exports', permission: 'finance_export.create', phase: 'Phase 18B', availability: 'live' },
  { key: 'finance.audit', label: 'Audit activity', routeName: 'finance.audit', permission: 'finance.audit.view', phase: 'Phase 19', availability: 'live' },
  { key: 'finance.platform-fees', label: 'Platform fees', routeName: 'finance.platform-fees', permission: 'platform_fee.view', phase: 'Phase 20E', availability: 'live' },
];

const frontOffice: NavItem[] = [
  { key: 'front-office.overview', label: 'Overview', routeName: 'front-office.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'front-office.get-started', label: 'Get started', routeName: 'front-office.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'front-office.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'front-office.clients', label: 'Clients', routeName: 'front-office.clients', permission: 'client.view', phase: 'Phase 15A', availability: 'live' },
  { key: 'front-office.appointments', label: 'Appointments', routeName: 'front-office.appointments', permission: 'appointment.view', phase: 'Phase 16A', availability: 'live' },
  { key: 'front-office.walk-ins', label: 'Walk-ins', routeName: 'front-office.walk-in', permission: 'queue.create', phase: 'Phase 16B', availability: 'live' },
  { key: 'front-office.queue', label: 'Queue', routeName: 'front-office.queue', permission: 'queue.view', phase: 'Phase 16B', availability: 'live' },
  { key: 'front-office.service-sessions', label: 'Service sessions', routeName: 'front-office.sessions', permission: 'service_session.view', phase: 'Phase 16C', availability: 'live' },
  { key: 'front-office.invoices', label: 'Invoices', routeName: 'front-office.invoices', permission: 'invoice.view', phase: 'Phase 17', availability: 'live' },
  { key: 'front-office.payments', label: 'Payments', routeName: 'front-office.payments', permission: 'customer_payment.record', phase: 'Phase 18A', availability: 'live' },
  { key: 'front-office.receipts', label: 'Receipts', routeName: 'front-office.receipts', permission: 'receipt.view', phase: 'Phase 18B', availability: 'live' },
];

// Personnel own-scope navigation (Scope §4.7, §12.13 "My Earnings").
const personnel: NavItem[] = [
  { key: 'personnel.overview', label: 'Overview', routeName: 'personnel.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'personnel.get-started', label: 'Get started', routeName: 'personnel.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'personnel.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'personnel.my-queue', label: 'My queue', routeName: 'personnel.queue', permission: 'personnel.my_queue.view', phase: 'Phase 16B', availability: 'live' },
  { key: 'personnel.my-appointments', label: 'My appointments', routeName: 'personnel.appointments', permission: 'personnel.my_appointments.view', phase: 'Phase 16A', availability: 'live' },
  { key: 'personnel.my-sessions', label: 'My sessions', routeName: 'personnel.sessions', permission: 'personnel.my_sessions.view', phase: 'Phase 16C', availability: 'live' },
  // Phase 21S — both point at the single Client SMS screen: the served-client list is the entry
  // point of the send flow, so it is not a separate route (Plan §64). Neither offers any export.
  { key: 'personnel.my-served-clients', label: 'My served clients', routeName: 'personnel.sms', permission: 'personnel.my_served_clients.view', phase: 'Phase 21S', availability: 'live' },
  { key: 'personnel.my-earnings', label: 'My earnings', routeName: 'personnel.earnings', permission: 'personnel.my_earnings.view', phase: 'Phase 20H', availability: 'live' },
  { key: 'personnel.my-sms', label: 'Client SMS', routeName: 'personnel.sms', permission: 'personnel.my_sms.send', phase: 'Phase 21S', availability: 'live' },
];

const audit: NavItem[] = [
  { key: 'audit.overview', label: 'Overview', routeName: 'audit.landing', phase: 'Phase 11', availability: 'live' },
  { key: 'audit.get-started', label: 'Get started', routeName: 'audit.get-started', phase: 'Phase 11', availability: 'live' },
  // Phase 22 global search. NO permission key: GET /api/v1/search grants access to nothing and
  // returns only what the caller's existing per-type authority already allows (D-22-01), so there is
  // no key to gate visibility on. Shown to every merchant-side role; a role with no searchable
  // authority sees the server-authoritative empty state.
  { key: 'audit.search', label: 'Search', routeName: 'search', phase: 'Phase 22', availability: 'live' },
  { key: 'audit.branch-events', label: 'Branch audit log', routeName: 'audit.branch-events', permission: 'audit.branch_events.view', phase: 'Phase 19', availability: 'live' },
  { key: 'audit.flagged-events', label: 'Flagged events', routeName: 'audit.flagged-events', permission: 'audit.branch_events.view', phase: 'Phase 19', availability: 'live' },
  { key: 'audit.compensation', label: 'Compensation audit', routeName: 'audit.compensation', permission: 'audit.compensation.view', phase: 'Phase 19', availability: 'live' },
  { key: 'audit.finance', label: 'Finance audit', routeName: 'audit.finance', permission: 'audit.finance.view', phase: 'Phase 19', availability: 'live' },
  { key: 'audit.exports', label: 'Exports', routeName: 'audit.exports', permission: 'audit.export', phase: 'Phase 19', availability: 'live' },
  { key: 'audit.platform-fees', label: 'Platform fees', routeName: 'audit.platform-fees', permission: 'platform_fee.view', phase: 'Phase 20E', availability: 'live' },
];

/** The canonical per-role navigation registry. */
export const ROLE_NAVIGATION: Record<RoleIdentity, NavItem[]> = {
  super_administrator: platform,
  merchant_administrator: merchantAdministrator,
  merchant_branch: branchManager,
  merchant_human_resource: humanResource,
  merchant_finance: finance,
  merchant_front_office: frontOffice,
  merchant_personnel: personnel,
  merchant_audit: audit,
};

/** Navigation items for a role identity (empty array for an unknown identity). */
export function navigationFor(identity: RoleIdentity): NavItem[] {
  return ROLE_NAVIGATION[identity] ?? [];
}
