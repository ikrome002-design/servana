import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/**
 * Phase 23 whole-product release-audit harness (Plan §28 responsive, §29 theming, §30
 * accessibility; Phase 23 Increments 6–9).
 *
 * ONE data-driven matrix drives the responsive, dark-mode, accessibility and determinism
 * sweeps over the ENTIRE implemented launch surface, rather than one duplicate spec per
 * screen. The SPA preview has no backend, so `/me` and `/api/v1/**` are stubbed to drive the
 * REAL frontend: routing, layout, theming and accessibility are frontend concerns and this is
 * the only place they can be proven end to end. Authorization is proven by the backend suite —
 * every permission list below is UX input, never a security claim.
 *
 * DETERMINISM (Increment 9): the clock is pinned to a fixed Africa/Nairobi instant, every
 * identifier is a constant, and every stubbed response is a pure function of the request. No
 * wall-clock date, random value, or ambient database state reaches a screen.
 */

// --- Deterministic environment ----------------------------------------------

/** Fixed audit instant: 2026-07-15 12:00:00 Africa/Nairobi (UTC+3) == 09:00:00Z, a Wednesday. */
export const AUDIT_INSTANT_UTC = '2026-07-15T09:00:00.000Z';
/** The Nairobi business date of {@link AUDIT_INSTANT_UTC}. Never derived from the wall clock. */
export const AUDIT_BUSINESS_DATE = '2026-07-15';
/** A fixed FUTURE Nairobi business date, used wherever a screen requires a forward-dated entry. */
export const AUDIT_FUTURE_DATE = '2026-08-12';

/** Pad a readable label into a fixed 26-character identifier. */
function id(label: string): string {
  return `01HZZ${label}`.padEnd(26, '0').slice(0, 26);
}

export const IDS = {
  branch: id('BRANCH'),
  staff: id('STAFF'),
  client: id('CLIENT'),
  appointment: id('APPT'),
  queueEntry: id('QUEUE'),
  session: id('SESSION'),
  invoice: id('INVOICE'),
  payment: id('PAYMENT'),
  paymentGroup: id('PAYGROUP'),
  receipt: id('RECEIPT'),
  refund: id('REFUND'),
  dispute: id('DISPUTE'),
  cashUp: id('CASHUP'),
  auditEvent: id('AUDITEV'),
  flagged: id('FLAGGED'),
  auditExport: id('AUDITEXP'),
  file: id('FILE'),
} as const;

// --- Viewport / theme matrix -------------------------------------------------

export interface Viewport {
  name: 'mobile' | 'tablet' | 'desktop';
  width: number;
  height: number;
}

/** Plan §28 breakpoints: mobile ≤767, tablet 768–1024, desktop ≥1025. */
export const VIEWPORTS: Viewport[] = [
  { name: 'mobile', width: 360, height: 780 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 900 },
];

export type Theme = 'light' | 'dark';
export const THEMES: Theme[] = ['light', 'dark'];

// --- Role identities ---------------------------------------------------------

export type RoleIdentity =
  | 'public'
  | 'super_administrator'
  | 'merchant_administrator'
  | 'merchant_branch'
  | 'merchant_human_resource'
  | 'merchant_finance'
  | 'merchant_front_office'
  | 'merchant_personnel'
  | 'merchant_audit';

/** Role identity → the membership role string the API bootstrap returns. */
const MEMBERSHIP_ROLE: Record<Exclude<RoleIdentity, 'public' | 'super_administrator'>, string> = {
  merchant_administrator: 'merchant_admin',
  merchant_branch: 'branch_manager',
  merchant_human_resource: 'hr',
  merchant_finance: 'finance',
  merchant_front_office: 'front_office',
  merchant_personnel: 'personnel',
  merchant_audit: 'audit',
};

// --- The audited screen matrix ----------------------------------------------

export interface AuditScreen {
  /** Inventory key (docs/frontend/screens/inventory.json). */
  key: string;
  /** Router route name, or null for a rendered access state. */
  route: string | null;
  /** Concrete URL the audit navigates to. */
  path: string;
  /** Role identity the audit bootstraps for this screen. */
  role: RoleIdentity;
  /** Bootstrap overrides needed to reach this screen's state. */
  bootstrap?: BootstrapOverrides;
  /**
   * Locator that proves the screen's own content rendered (not just the shell). Defaults to
   * the page heading.
   */
  ready?: string;
  /** Data state the audit exercises, recorded in the audit document. */
  state: 'populated' | 'empty' | 'static' | 'access-state';
}

export interface BootstrapOverrides {
  authenticated?: boolean;
  role?: string | null;
  isPlatformStaff?: boolean;
  permissions?: string[];
  branchIds?: string[];
  setupRequired?: boolean;
  mfa?: Partial<MfaState>;
}

interface MfaState {
  required: boolean;
  enrolled: boolean;
  confirmed: boolean;
  verified: boolean;
  enrollment_required: boolean;
  challenge_required: boolean;
  step_up_fresh: boolean;
  step_up_fresh_until: string | null;
  recovery_codes_remaining: number;
}

/**
 * The inventory is the source of truth for WHICH screens exist; this file supplies the URL and
 * the bootstrap each one needs. `phase-23-release-audit.spec.ts` fails when the two drift, so a
 * newly delivered screen cannot silently escape the release audit.
 */
export const SCREENS: AuditScreen[] = [
  // --- public / unauthenticated ---------------------------------------------
  { key: 'home', route: 'home', path: '/', role: 'public', state: 'static' },
  { key: 'not-found', route: 'not-found', path: '/no-such-page-01hzz', role: 'public', state: 'static' },
  { key: 'design-system', route: 'dev.design-system', path: '/dev/design-system', role: 'public', state: 'static' },
  {
    // The pre-UI-06 role-parameterised shape. With the Merchant Administrator context bootstrapped
    // above, this is the account's OWN document, so the compatibility route redirects to the
    // canonical path and the audit still grades a rendered legal document.
    key: 'legal-document',
    route: 'legal.document',
    path: '/legal/merchant_administrator/terms-of-service',
    role: 'public',
    state: 'static',
  },
  // Phase UI-06 public surfaces. The account is host-derived, so the path carries no role.
  {
    key: 'public-faq',
    route: 'public.faq',
    path: '/faq',
    role: 'public',
    ready: '[data-testid="public-faq"]',
    state: 'populated',
  },
  {
    key: 'public-legal',
    route: 'public.legal',
    path: '/legal/privacy-policy',
    role: 'public',
    ready: '[data-testid="sv-legal-document"]',
    state: 'static',
  },
  { key: 'auth-login', route: 'auth.login', path: '/auth/login', role: 'public', state: 'static' },
  { key: 'auth-register', route: 'auth.register', path: '/auth/register', role: 'public', state: 'static' },
  { key: 'auth-check-email', route: 'auth.check-email', path: '/auth/check-email', role: 'public', state: 'static' },
  { key: 'auth-verify', route: 'auth.verify', path: '/auth/verify?token=audit-token', role: 'public', state: 'static' },
  { key: 'staff-invitation-accept', route: 'staff.accept', path: '/staff/accept?token=audit-token', role: 'public', state: 'static' },

  // --- MFA (authenticated, pre-privilege) -----------------------------------
  {
    key: 'mfa-setup',
    route: 'auth.mfa.setup',
    path: '/auth/mfa/setup',
    role: 'merchant_administrator',
    bootstrap: { mfa: { required: true, enrolled: false, confirmed: false, verified: false, enrollment_required: true } },
    state: 'static',
  },
  {
    key: 'mfa-challenge',
    route: 'auth.mfa.challenge',
    path: '/auth/mfa/challenge',
    role: 'merchant_administrator',
    bootstrap: { mfa: { required: true, enrolled: true, confirmed: true, verified: false, challenge_required: true } },
    state: 'static',
  },

  // --- onboarding ------------------------------------------------------------
  {
    key: 'first-time-setup',
    route: 'merchant.setup',
    path: '/setup',
    role: 'merchant_administrator',
    bootstrap: { setupRequired: true },
    state: 'static',
  },

  // --- access states (rendered, route-less) ---------------------------------
  {
    // Phase UI-03 registered this live screen in the inventory but never added it here, so the
    // coverage guard correctly reported it missing the first time the suite was run after UI-03.
    // It is a real route (`/access-denied`), reached by the account-entry guard rather than by a
    // redirect to another account.
    key: 'auth-access-denied',
    route: 'access-denied',
    path: '/access-denied',
    role: 'merchant_administrator',
    ready: 'text=/do not have access to this page/i',
    state: 'access-state',
  },
  {
    key: 'unsupported-role',
    route: null,
    path: '/merchant',
    role: 'merchant_administrator',
    // No resolvable membership → RoleShell's fail-safe boundary.
    bootstrap: { role: null },
    ready: 'text=/role isn\'t supported here/i',
    state: 'access-state',
  },
  {
    key: 'no-branch-assignment',
    route: null,
    path: '/branch',
    role: 'merchant_branch',
    bootstrap: { branchIds: [] },
    ready: 'text=Waiting on a branch assignment',
    state: 'access-state',
  },
// Phase UI-07 removed the four placeholder account dashboards (UI07-ROUTE-001): each rendered
// `DashboardStub.vue`, so the audit was grading a Phase-4 stub as though it were a screen.
// --- Super Administrator ---------------------------------------------------
  // Phase UI-08 Increment 7B activated the Super Administrator's canonical host-relative routes and
  // retired `platform.registration-monitoring` and `platform.promotions`, whose consolidated screens
  // delivered three and two contract pages respectively. The audit now grades the real pages.
  { key: 'platform-landing', route: 'platform.landing', path: '/platform', role: 'super_administrator', state: 'static' },
  { key: 'platform-dashboard', route: 'platform.dashboard', path: '/dashboard', role: 'super_administrator', state: 'populated' },
  { key: 'platform-get-started', route: 'platform.get-started', path: '/get-started', role: 'super_administrator', state: 'static' },
  { key: 'platform-billing-settings', route: 'platform.billing-settings', path: '/billing/settings', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-plans', route: 'platform.billing-plans', path: '/billing/plans', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-prices', route: 'platform.billing-prices', path: '/billing/prices', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-promotions', route: 'platform.billing-promotions', path: '/billing/promotions', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-free-periods', route: 'platform.billing-free-periods', path: '/billing/free-periods', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-preferred-personnel-fees', route: 'platform.billing-preferred-personnel-fees', path: '/billing/preferred-personnel-fees', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-sms', route: 'platform.billing-sms', path: '/billing/sms', role: 'super_administrator', state: 'populated' },
  { key: 'platform-billing-subscriptions', route: 'platform.billing-subscriptions', path: '/billing/subscriptions', role: 'super_administrator', state: 'populated' },
  { key: 'platform-merchant-registrations', route: 'platform.merchant-registrations', path: '/merchants/registrations', role: 'super_administrator', state: 'populated' },
  { key: 'platform-merchants', route: 'platform.merchants', path: '/merchants', role: 'super_administrator', state: 'populated' },
  // A parameterised contract route still needs release-audit coverage; the identifier is synthetic.
  { key: 'platform-merchant-detail', route: 'platform.merchant-detail', path: '/merchants/01JQ0000000000000000000001', role: 'super_administrator', state: 'populated' },
  { key: 'platform-audit', route: 'platform.audit', path: '/audit', role: 'super_administrator', state: 'populated' },
  { key: 'platform-platform-access', route: 'platform.platform-access', path: '/platform-access', role: 'super_administrator', state: 'populated' },
  { key: 'platform-feature-flags', route: 'platform.feature-flags', path: '/platform/feature-flags', role: 'super_administrator', state: 'populated' },
  { key: 'platform-account', route: 'platform.account', path: '/account', role: 'super_administrator', state: 'static' },

  // --- Merchant Administrator ------------------------------------------------
  { key: 'merchant-landing', route: 'merchant.landing', path: '/merchant', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-get-started', route: 'merchant.get-started', path: '/get-started', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-dashboard', route: 'merchant.dashboard', path: '/dashboard', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-profile', route: 'merchant.merchant-profile', path: '/merchant/profile', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-branches', route: 'merchant.branches', path: '/branches', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-branch-detail', route: 'merchant.branch-detail', path: `/branches/${IDS.branch}`, role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-staff-overview', route: 'merchant.staff', path: '/staff', role: 'merchant_administrator', state: 'populated' },
  { key: 'subscription-dashboard', route: 'merchant.subscription', path: '/subscription', role: 'merchant_administrator', state: 'populated' },
  { key: 'plan-management', route: 'merchant.subscription-plan', path: '/subscription/plan', role: 'merchant_administrator', state: 'populated' },
  { key: 'subscription-invoices', route: 'merchant.subscription-invoices', path: '/subscription/invoices', role: 'merchant_administrator', state: 'populated' },
  { key: 'subscription-invoice-detail', route: 'merchant.subscription-invoice-detail', path: `/subscription/invoices/${IDS.invoice}`, role: 'merchant_administrator', state: 'populated' },
  { key: 'compensation-summary', route: 'merchant.compensation', path: '/compensation', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-payout-approvals', route: 'merchant.compensation-payout-approvals', path: '/compensation/payout-approvals', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-period-reopen-approvals', route: 'merchant.finance-period-reopen-approvals', path: '/finance/period-reopen-approvals', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-account-security', route: 'merchant.account', path: '/account', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-platform-fees', route: 'merchant.platform-fees', path: '/merchant/platform-fees', role: 'merchant_administrator', state: 'populated' },
  { key: 'branch-create', route: 'merchant.branch-create', path: '/branches/create', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-hr-staff-invite-support', route: 'merchant.hr-invitations', path: '/hr/invitations', role: 'merchant_administrator', state: 'populated' },
  // --- Branch Manager --------------------------------------------------------
  { key: 'branch-landing', route: 'branch.landing', path: '/branch', role: 'merchant_branch', state: 'static' },
  { key: 'branch-dashboard', route: 'branch.dashboard', path: '/dashboard', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-get-started', route: 'branch.get-started', path: '/get-started', role: 'merchant_branch', state: 'static' },
  { key: 'branch-profile', route: 'branch.branch-profile', path: '/branch/profile', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-operating-hours', route: 'branch.operating-hours', path: `/branch/${IDS.branch}/operating-hours`, role: 'merchant_branch', state: 'populated' },
  { key: 'branch-operating-calendar', route: 'branch.branch-calendar', path: '/branch/calendar', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-day-operations', route: 'branch.branch-day', path: '/branch/day', role: 'merchant_branch', state: 'populated' },
  { key: 'service-catalogue', route: 'branch.services', path: '/services', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-staff-overview', route: 'branch.staff', path: '/staff', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-queue-read-view', route: 'branch.operations-queue', path: '/operations/queue', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-appointments-read-view', route: 'branch.operations-appointments', path: '/operations/appointments', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-invoices-read-view', route: 'branch.finance-invoices', path: '/finance/invoices', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-payments-read-view', route: 'branch.finance-payments', path: '/finance/payments', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-receipts-read-view', route: 'branch.finance-receipts', path: '/finance/receipts', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-queue-configuration', route: 'branch.queue-configuration', path: '/branch/queue-configuration', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-cash-up', route: 'branch.cash-up', path: '/cash-up', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-audit-log', route: 'branch.audit', path: '/audit', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-account-security', route: 'branch.account', path: '/account', role: 'merchant_branch', state: 'static' },
  { key: 'branch-platform-fees', route: 'branch.platform-fees', path: '/branch/platform-fees', role: 'merchant_branch', state: 'populated' },

  // --- HR --------------------------------------------------------------------
  { key: 'hr-landing', route: 'hr.landing', path: '/hr', role: 'merchant_human_resource', state: 'static' },
  { key: 'hr-dashboard', route: 'hr.dashboard', path: '/dashboard', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-get-started', route: 'hr.get-started', path: '/get-started', role: 'merchant_human_resource', state: 'static' },
  { key: 'hr-staff', route: 'hr.staff', path: '/staff', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-staff-invite', route: 'hr.staff-invite', path: '/staff/invite', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-staff-detail', route: 'hr.staff-detail', path: `/staff/${IDS.staff}`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-staff-lifecycle', route: 'hr.staff-detail-lifecycle', path: `/staff/${IDS.staff}/lifecycle`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'service-eligibility', route: 'hr.eligibility', path: '/eligibility', role: 'merchant_human_resource', state: 'populated' },
  { key: 'personnel-availability', route: 'hr.availability', path: '/availability', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-compensation', route: 'hr.compensation', path: '/compensation', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-compensation-detail', route: 'hr.compensation-detail', path: `/compensation/${IDS.staff}`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-compensation-setup', route: 'hr.compensation-setup', path: `/compensation/${IDS.staff}/setup`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-compensation-history', route: 'hr.compensation-history', path: `/compensation/${IDS.staff}/history`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-payouts', route: 'hr.payouts', path: '/payouts', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-audit', route: 'hr.audit', path: '/audit', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-account', route: 'hr.account', path: '/account', role: 'merchant_human_resource', state: 'static' },

  // --- Finance ---------------------------------------------------------------
  { key: 'finance-landing', route: 'finance.landing', path: '/finance', role: 'merchant_finance', state: 'static' },
  { key: 'finance-get-started', route: 'finance.get-started', path: '/get-started', role: 'merchant_finance', state: 'static' },
  { key: 'finance-dashboard', route: 'finance.dashboard', path: '/dashboard', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-task-inbox', route: 'finance.tasks', path: '/tasks', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-pending-validations', route: 'finance.payments-validations', path: '/payments/validations', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payment-records-detail', route: 'finance.payments-validation-detail', path: `/payments/validations/${IDS.paymentGroup}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-duplicate-references', route: 'finance.payments-duplicates', path: '/payments/duplicates', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-receipts', route: 'finance.receipts', path: '/receipts', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-receipt-detail', route: 'finance.receipts.detail', path: `/finance/receipts/${IDS.receipt}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-refunds', route: 'finance.refunds', path: '/refunds', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-refund-detail', route: 'finance.refunds.detail', path: `/finance/refunds/${IDS.refund}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-disputes', route: 'finance.disputes', path: '/disputes', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-dispute-detail', route: 'finance.disputes.detail', path: `/finance/disputes/${IDS.dispute}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-cash-up', route: 'finance.cash-up', path: '/cash-up', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-cash-up-detail', route: 'finance.cash-up.detail', path: `/finance/cash-up/${IDS.cashUp}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-periods', route: 'finance.periods', path: '/periods', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-exports', route: 'finance.exports', path: '/exports', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-invoices', route: 'finance.invoices', path: '/invoices', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-invoice-detail', route: 'finance.invoices.detail', path: `/finance/invoices/${IDS.invoice}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payment-records', route: 'finance.payments', path: '/payments', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-partial-split-payments', route: 'finance.payments-partial-split', path: '/payments/partial-split', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-audit', route: 'finance.audit', path: '/audit', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-compensation-liabilities', route: 'finance.compensation-liabilities', path: '/compensation/liabilities', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payouts', route: 'finance.payouts', path: '/payouts', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-earnings-queries', route: 'finance.compensation-queries', path: '/compensation/queries', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-settings', route: 'finance.settings', path: '/settings', role: 'merchant_finance', state: 'static' },

  // --- Front Office ----------------------------------------------------------
  { key: 'front-office-landing', route: 'front-office.landing', path: '/front-office', role: 'merchant_front_office', state: 'static' },
  { key: 'front-office-get-started', route: 'front-office.get-started', path: '/front-office/get-started', role: 'merchant_front_office', state: 'static' },
  { key: 'front-office-clients', route: 'front-office.clients', path: '/front-office/clients', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-client-create', route: 'front-office.clients.create', path: '/front-office/clients/create', role: 'merchant_front_office', state: 'static' },
  { key: 'front-office-client-detail', route: 'front-office.clients.detail', path: `/front-office/clients/${IDS.client}`, role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-appointments', route: 'front-office.appointments', path: '/front-office/appointments', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-appointment-create', route: 'front-office.appointments.create', path: '/front-office/appointments/create', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-appointment-detail', route: 'front-office.appointments.detail', path: `/front-office/appointments/${IDS.appointment}`, role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-queue', route: 'front-office.queue', path: '/front-office/queue', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-walk-in', route: 'front-office.walk-in', path: '/front-office/walk-in', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-queue-detail', route: 'front-office.queue.detail', path: `/front-office/queue/${IDS.queueEntry}`, role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-service-sessions', route: 'front-office.sessions', path: '/front-office/sessions', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-invoices', route: 'front-office.invoices', path: '/front-office/invoices', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-invoice-create', route: 'front-office.invoices.create', path: '/front-office/invoices/create', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-invoice-detail', route: 'front-office.invoices.detail', path: `/front-office/invoices/${IDS.invoice}`, role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-payment-recording', route: 'front-office.payments', path: '/front-office/payments', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-payment-record', route: 'front-office.payments.record', path: `/front-office/payments/record/${IDS.invoice}`, role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-receipts', route: 'front-office.receipts', path: '/front-office/receipts', role: 'merchant_front_office', state: 'populated' },
  { key: 'front-office-receipt-detail', route: 'front-office.receipts.detail', path: `/front-office/receipts/${IDS.receipt}`, role: 'merchant_front_office', state: 'populated' },

  // --- Personnel -------------------------------------------------------------
  { key: 'personnel-landing', route: 'personnel.landing', path: '/personnel', role: 'merchant_personnel', state: 'static' },
  { key: 'personnel-get-started', route: 'personnel.get-started', path: '/personnel/get-started', role: 'merchant_personnel', state: 'static' },
  { key: 'personnel-appointments', route: 'personnel.appointments', path: '/personnel/appointments', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-queue', route: 'personnel.queue', path: '/personnel/queue', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-sessions', route: 'personnel.sessions', path: '/personnel/sessions', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-my-earnings', route: 'personnel.earnings', path: '/personnel/earnings', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-sms', route: 'personnel.sms', path: '/personnel/sms', role: 'merchant_personnel', state: 'populated' },

  // --- Audit -----------------------------------------------------------------
  { key: 'audit-landing', route: 'audit.landing', path: '/audit', role: 'merchant_audit', state: 'static' },
  { key: 'audit-get-started', route: 'audit.get-started', path: '/audit/get-started', role: 'merchant_audit', state: 'static' },
  { key: 'audit-event-list', route: 'audit.branch-events', path: '/audit/events', role: 'merchant_audit', state: 'populated' },
  { key: 'audit-event-detail', route: 'audit.event-detail', path: `/audit/events/${IDS.auditEvent}`, role: 'merchant_audit', state: 'populated' },
  { key: 'audit-flagged-queue', route: 'audit.flagged-events', path: '/audit/flagged', role: 'merchant_audit', state: 'populated' },
  { key: 'audit-flagged-detail', route: 'audit.flagged-detail', path: `/audit/flagged/${IDS.flagged}`, role: 'merchant_audit', state: 'populated' },
  { key: 'audit-finance', route: 'audit.finance', path: '/audit/finance', role: 'merchant_audit', state: 'populated' },
  { key: 'audit-compensation', route: 'audit.compensation', path: '/audit/compensation', role: 'merchant_audit', state: 'populated' },
  { key: 'audit-export-list', route: 'audit.exports', path: '/audit/exports', role: 'merchant_audit', state: 'populated' },
  { key: 'audit-export-detail', route: 'audit.export-detail', path: `/audit/exports/${IDS.auditExport}`, role: 'merchant_audit', state: 'populated' },
  { key: 'audit-platform-fees', route: 'audit.platform-fees', path: '/audit/platform-fees', role: 'merchant_audit', state: 'populated' },

  // --- cross-role -------------------------------------------------------------
  { key: 'global-search', route: 'search', path: '/search', role: 'merchant_front_office', state: 'populated' },
];

// --- Inventory cross-check ---------------------------------------------------

interface InventoryScreen {
  key: string;
  route: string | null;
  status: 'implemented' | 'phase_11' | 'planned';
  permissions: string[];
}

const INVENTORY_PATH = resolve(
  import.meta.dirname,
  '../../../docs/frontend/screens/inventory.json',
);

/** Every live (non-planned) inventory screen — the exact set the release audit must cover. */
export function liveInventoryScreens(): InventoryScreen[] {
  const raw = JSON.parse(readFileSync(INVENTORY_PATH, 'utf8')) as { screens: InventoryScreen[] };

  // Phase UI-07 reconciled the inventory to the closed §7.2 vocabulary. `implemented` is the only
  // status with a screen to grade: `planned` never had one, `disabled_by_gate` is deliberately
  // absent behind External Gate W, and `removed_by_authority` must appear in no live audit set.
  // Testing `!== 'planned'` was correct only while `planned` was the sole non-live value; it would
  // now enrol five gate-blocked rows that have no route at all.
  return raw.screens.filter((s) => s.status === 'implemented');
}

/** Inventory-declared permissions for a screen key (UX input for the bootstrap stub). */
function inventoryPermissions(): Map<string, string[]> {
  return new Map(liveInventoryScreens().map((s) => [s.key, s.permissions]));
}

const PERMISSIONS_BY_KEY = inventoryPermissions();

/**
 * Permissions the audit grants beyond the screen's own inventory list: shell/navigation reads
 * a role legitimately holds, without which a screen renders a misleading forbidden state.
 */
const ROLE_BASELINE_PERMISSIONS: Record<RoleIdentity, string[]> = {
  public: [],
  super_administrator: [],
  merchant_administrator: ['merchant.branch.view_all', 'branch.profile.view'],
  merchant_branch: ['branch.profile.view', 'branch.dashboard.view'],
  merchant_human_resource: ['staff.view'],
  merchant_finance: ['invoice.view', 'receipt.view', 'customer_payment.view', 'cash_up.view'],
  merchant_front_office: ['client.view', 'appointment.view', 'queue.view', 'invoice.view', 'receipt.view', 'service_session.view'],
  merchant_personnel: [],
  merchant_audit: ['audit.branch_events.view'],
};

// --- Bootstrap + API stubbing ------------------------------------------------

function ok(body: unknown, status = 200) {
  return { status, contentType: 'application/json', body: JSON.stringify(body) };
}

const EMPTY_LIST = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, from: null, to: null },
  links: { first: null, last: null, prev: null, next: null },
};

/** A stubbed API response: an ordered path matcher plus the body to return. */
export interface Fixture {
  match: RegExp;
  method?: string;
  body: unknown;
  status?: number;
}

/**
 * Fixtures that populate the audited screens. Keyed on the API path WITHOUT the `/api/v1`
 * prefix. The first match wins, so specific detail paths precede their collection.
 *
 * Anything unmatched falls back to an empty paginated envelope for a GET and a generic accepted
 * response for a mutation, which renders the screen's real empty state — a legitimate audited
 * state, recorded as such in docs/frontend/phase-23-responsive-dark-audit.md.
 */
export function baseFixtures(): Fixture[] {
  return [...SHARED_FIXTURES];
}

/** A deliberately long merchant name — §9.3 requires long names not to overflow. */
export const LONG_MERCHANT_NAME =
  'Glow Studio Nairobi Westlands Grooming & Wellness Collective Limited';

const MERCHANT_PROFILE = {
  id: id('MERCHANT'),
  business_category: 'Salon and spa',
  contact_email: 'hello@glowstudio.co.ke',
  contact_phone: '+254712000111',
  receipt_display_name: 'Glow Studio Westlands',
  address: 'Ground Floor, Delta Corner Annex, Ring Road Parklands',
  town: 'Nairobi',
  timezone: 'Africa/Nairobi',
  country: 'KE',
  merchant: {
    id: 'm1',
    name: LONG_MERCHANT_NAME,
    slug: 'glow-studio-westlands',
    status: 'active',
    service_fee_tier: 'standard',
  },
  logo: { id: IDS.file, filename: 'glow-studio-logo.png' },
  logo_history: [],
};

/** Branch calendar rows covering every type: three full-day closures plus modified hours. */
const CALENDAR_EXCEPTIONS = [
  {
    date: '2026-08-12',
    type: 'public_holiday',
    closes_branch: true,
    opens_at: null,
    closes_at: null,
    reason: 'Public holiday — branch closed all day',
    created_at: '2026-07-01T08:00:00+00:00',
  },
  {
    date: '2026-08-15',
    type: 'modified_hours',
    closes_branch: false,
    opens_at: '10:00',
    closes_at: '15:30',
    reason: 'Shortened trading while the building lift is serviced',
    created_at: '2026-07-01T08:05:00+00:00',
  },
  {
    date: '2026-08-20',
    type: 'special_closure',
    closes_branch: true,
    opens_at: null,
    closes_at: null,
    reason: 'Annual staff training day',
    created_at: '2026-07-01T08:10:00+00:00',
  },
  {
    date: '2026-08-21',
    type: 'emergency_closure',
    closes_branch: true,
    opens_at: null,
    closes_at: null,
    reason: 'Water outage reported by building management',
    created_at: '2026-07-01T08:15:00+00:00',
  },
];

const BRANCH = {
  id: IDS.branch,
  name: 'Westlands Branch',
  slug: 'westlands',
  status: 'active',
  town: 'Nairobi',
  address: 'Delta Corner Annex, Ring Road Parklands',
  phone: '+254712000222',
  timezone: 'Africa/Nairobi',
  is_open_today: true,
  operating_hours: [0, 1, 2, 3, 4, 5, 6].map((weekday) => ({
    weekday,
    opens_at: weekday === 0 ? null : '08:00',
    closes_at: weekday === 0 ? null : '19:00',
    is_closed: weekday === 0,
    break_start: null,
    break_end: null,
  })),
};

/** Client contact is ALWAYS masked in the API contract; the fixture mirrors that exactly. */
const CLIENT = {
  id: IDS.client,
  full_name: 'Amina Wanjiku',
  phone_masked: '+2547•••••678',
  phone_last_four: '5678',
  email_masked: 'a•••@example.co.ke',
  has_email: true,
  notes: 'Prefers an afternoon appointment.',
  status: 'active',
  sms_consent: 'opted_in',
  can: { view: true, update: true },
};

const QUEUE_ENTRY = {
  id: IDS.queueEntry,
  status: 'waiting',
  position: 1,
  assignment_mode: 'next_available',
  source: 'walk_in',
  queued_at: '2026-07-15T06:30:00+00:00',
  assigned_at: null,
  called_at: null,
  started_at: null,
  completed_at: null,
  cancelled_at: null,
  no_show_at: null,
  transferred_at: null,
  cancellation_reason: null,
  transfer_reason: null,
  preferred_personnel_override_reason: null,
  estimated_wait: {
    label: 'About 25 minutes',
    minutes: 25,
    override_minutes: null,
    override_reason: null,
    effective_minutes: 25,
  },
  service: { id: id('SERVICE'), name: 'Signature cut and finish', duration_minutes: 45 },
  client: {
    id: IDS.client,
    full_name: 'Amina Wanjiku',
    phone_masked: '+2547•••••678',
    phone_last_four: '5678',
  },
  assigned_personnel: null,
  preferred_personnel: null,
  can: {
    view: true, assign: true, call: false, start: false,
    complete: false, transfer: true, cancel: true, no_show: false,
  },
};

const AUDIT_EVENT = {
  id: IDS.auditEvent,
  action: 'branch.calendar_exception_set',
  severity: 'warn',
  actor: 'Ada Mwangi',
  branch: 'Westlands Branch',
  subject_type: 'BranchCalendarException',
  context: { date: '2026-08-12', type: 'public_holiday' },
  correlation_id: id('CORREL'),
  created_at: '2026-07-15T06:00:00+00:00',
  can: { view: true },
};

const HR_STAFF = {
  id: IDS.staff,
  first_name: 'Amina',
  last_name: 'Wanjiku',
  display_name: 'Amina Wanjiku',
  phone: '+254712000333',
  role: 'personnel',
  role_title: 'Senior Stylist',
  status: 'active',
  employment_type: 'full_time',
  employment_status: 'employed',
  primary_branch_id: IDS.branch,
  is_active: true,
  can: { view: true, manage: true },
};

const HR_OVERVIEW = {
  branch: { id: IDS.branch, name: 'Westlands Branch', code: 'WST', town: 'Nairobi' },
  staff: { total: 8, active: 6, by_access_status: { active: 6, invited: 1, suspended: 1 }, pending_invitations: 1 },
  readiness: { eligible_staff: 5, without_eligibility: 1, available_staff: 4, without_availability: 2, configured_compensation: 3, without_compensation: 3 },
  compensation: { by_status: { active: 3, draft: 2 }, drafts_requiring_action: 2 },
  payouts: { by_status: { draft: 1, submitted: 2 }, awaiting_finance: 2 },
  tasks: [
    { key: 'pending-invitations', label: 'Pending staff invitations', count: 1, route_name: 'hr.staff-invite' },
    { key: 'eligibility-gaps', label: 'Active staff without service eligibility', count: 1, route_name: 'hr.eligibility' },
    { key: 'availability-gaps', label: 'Active staff without availability', count: 2, route_name: 'hr.availability' },
    { key: 'compensation-gaps', label: 'Active staff without active or scheduled terms', count: 3, route_name: 'hr.compensation' },
    { key: 'draft-plans', label: 'Draft compensation plans', count: 2, route_name: 'hr.compensation' },
  ],
  get_started: { staff_invited: true, eligibility_configured: true, availability_configured: true, compensation_configured: true, missing_compensation_reviewed: false },
  reports: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
  notifications: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
};

const FINANCE_MONEY = { amount: 125000, currency: 'KES', formatted: 'KES 1,250.00' };
const FINANCE_GROUP = {
  id: IDS.paymentGroup,
  status: 'pending_validation',
  is_pending_validation: true,
  currency: 'KES',
  total: FINANCE_MONEY,
  recorded_at: '2026-07-15T07:45:00+00:00',
  submitted_for_validation_at: '2026-07-15T07:46:00+00:00',
  maker: { id: id('MAKER'), name: 'Njeri Front Office' },
  invoice: { id: IDS.invoice, invoice_number: 'INV-000241' },
  components: [{ id: IDS.payment, method: 'mpesa_offline', amount: FINANCE_MONEY, status: 'pending_validation', reference_masked: '••••••1ABC' }],
  duplicate_checks: [],
};
const FINANCE_OVERVIEW = {
  branch_context: { label: 'Westlands Branch', branches: [{ id: IDS.branch, name: 'Westlands Branch', code: 'WST', town: 'Nairobi' }] },
  payments: { pending_validation: 2, duplicate_risk: 1, pending_recorded: [FINANCE_MONEY] },
  invoices: { outstanding: 3, outstanding_balance: [{ amount: 500000, currency: 'KES', formatted: 'KES 5,000.00' }], validated_payments: [{ amount: 375000, currency: 'KES', formatted: 'KES 3,750.00' }] },
  controls: { original_receipts: 4, active_disputes: 1, refunds_requiring_action: 1, cash_ups_requiring_review: 2, open_periods: 1, reopen_requests: 1 },
  compensation: { salary_due: [{ amount: 2500000, currency: 'KES', formatted: 'KES 25,000.00' }], commission_due: [{ amount: 350000, currency: 'KES', formatted: 'KES 3,500.00' }], payouts_requiring_action: 2, earnings_queries_requiring_action: 1 },
  tasks: [
    { key: 'payment-validations', label: 'Payment groups awaiting validation', count: 2, severity: 'high', route_name: 'finance.payments-validations', step_up_required: false, maker_checker: 'Finance checker' },
    { key: 'duplicate-references', label: 'Duplicate references held for review', count: 1, severity: 'critical', route_name: 'finance.payments-duplicates', step_up_required: true, maker_checker: 'Finance checker' },
    { key: 'cash-up-review', label: 'Cash-ups awaiting checker review', count: 2, severity: 'high', route_name: 'finance.cash-up', step_up_required: false, maker_checker: 'Finance checker' },
  ],
  subscription: { available: false, reason: 'External Gate W is closed. Phase 20D-W has no Wallet payment, attempt, allocation or reconciliation runtime.' },
  reports: { available: false, reason: 'Phase 21N is blocked until External Gate W and Phase 20D-W complete.' },
  notifications: { available: false, reason: 'Phase 21N is blocked until External Gate W and Phase 20D-W complete.' },
};
const FINANCE_DUPLICATE = {
  id: id('DUPCHECK'), method: 'mpesa_offline', result: 'duplicate_suspected', match_type: 'exact_normalized_reference', risk: 'high', reference_masked: '••••••1ABC', amount: FINANCE_MONEY, checked_at: '2026-07-15T07:47:00+00:00',
  current: { group_id: IDS.paymentGroup, group_status: 'recorded', invoice_id: IDS.invoice, invoice_number: 'INV-000241', recorded_by: 'Njeri Front Office', recorded_at: '2026-07-15T07:45:00+00:00' },
  conflict: { payment_id: id('MATCHPAY'), group_id: id('MATCHGROUP'), group_status: 'pending_validation', invoice_id: IDS.invoice, invoice_number: 'INV-000241', amount: FINANCE_MONEY, paid_at: '2026-07-15T07:30:00+00:00' },
  can_override: true,
};
const FINANCE_PARTIAL_SPLIT = {
  invoice: { id: IDS.invoice, number: 'INV-000241', status: 'issued', created_at: '2026-07-15T07:00:00+00:00' },
  balance: { total: { amount: 500000, currency: 'KES', formatted: 'KES 5,000.00' }, validated: { amount: 0, currency: 'KES', formatted: 'KES 0.00' }, pending_recorded: FINANCE_MONEY, remaining: { amount: 500000, currency: 'KES', formatted: 'KES 5,000.00' } },
  group_count: 1, has_multiple_groups: false, has_multi_method_group: true,
  groups: [{ id: IDS.paymentGroup, status: 'pending_validation', total: FINANCE_MONEY, recorded_at: '2026-07-15T07:45:00+00:00', maker: 'Njeri Front Office', receipt: null, components: [{ id: IDS.payment, method: 'mpesa_offline', status: 'pending_validation', amount: FINANCE_MONEY, reference_masked: '••••••1ABC', duplicate_risk: false }] }],
};

const SHARED_FIXTURES: Fixture[] = [
  // --- UI-12 Finance experience ---------------------------------------------
  { match: /^\/finance\/workspace$/, body: { data: { overview: FINANCE_OVERVIEW } } },
  { match: /^\/finance\/duplicate-references$/, body: { data: [FINANCE_DUPLICATE], meta: { ...EMPTY_LIST.meta, per_page: 20, total: 1 } } },
  { match: /^\/finance\/partial-split-payments$/, body: { data: [FINANCE_PARTIAL_SPLIT], meta: { ...EMPTY_LIST.meta, total: 1 } } },
  { match: /^\/payment-recording-groups\/[^/]+$/, body: { data: FINANCE_GROUP } },
  { match: /^\/payment-recording-groups$/, body: { data: [FINANCE_GROUP], meta: { ...EMPTY_LIST.meta, per_page: 20, total: 1 } } },

  // --- REM-SCR-002A: merchant business profile -------------------------------
  { match: /^\/merchant\/profile$/, body: { data: MERCHANT_PROFILE } },
  {
    match: /^\/files\/[^/]+\/download-link$/,
    method: 'POST',
    body: { data: { url: 'blob:audit-signed-link', expires_at: '2026-07-15T09:05:00+00:00' } },
  },

  // --- REM-SCR-002B: branch calendar -----------------------------------------
  {
    match: /^\/branches\/[^/]+\/calendar-exceptions$/,
    method: 'GET',
    body: { data: CALENDAR_EXCEPTIONS, meta: { from: '2026-07-15', to: '2026-10-13' } },
  },

  // --- branches ---------------------------------------------------------------
  { match: /^\/branches\/[^/]+\/operating-hours$/, body: { data: BRANCH.operating_hours } },
  { match: /^\/branches\/[^/]+$/, body: { data: BRANCH } },
  { match: /^\/branches$/, body: { data: [BRANCH], meta: { ...EMPTY_LIST.meta, total: 1 } } },
  {
    match: /^\/branch\/personnel-options$/,
    body: {
      data: [
        { id: IDS.staff, display_name: 'Amina Wanjiku' },
        { id: id('STAFF2'), display_name: 'Brian Otieno' },
      ],
    },
  },

  // --- HR roster (the staff-profile screen resolves its row from this list) ----
  { match: /^\/hr\/workspace$/, body: { data: { overview: HR_OVERVIEW } } },
  {
    match: /^\/hr\/audit-activity$/,
    body: {
      data: [{ ...AUDIT_EVENT, action: 'membership.suspended', actor: 'a***@glowstudio.co.ke', subject_type: 'MerchantUser' }],
      meta: { ...EMPTY_LIST.meta, total: 1 },
    },
  },
  { match: /^\/hr\/service-options$/, body: { data: [{ ulid: id('SERVICE'), name: 'Signature cut and finish' }] } },
  { match: /^\/staff\/[^/]+$/, body: { data: HR_STAFF } },
  {
    match: /^\/staff$/,
    body: {
      data: [HR_STAFF],
      meta: { ...EMPTY_LIST.meta, total: 1 },
    },
  },

  // --- clients ------------------------------------------------------------------
  { match: /^\/clients\/[^/]+$/, body: { data: CLIENT } },
  { match: /^\/clients$/, body: { data: [CLIENT], meta: { ...EMPTY_LIST.meta, total: 1 } } },

  // --- queue ---------------------------------------------------------------------
  { match: /^\/queue-entries\/[^/]+$/, body: { data: QUEUE_ENTRY } },
  { match: /^\/queue-entries$/, body: { data: [QUEUE_ENTRY], meta: { ...EMPTY_LIST.meta, total: 1 } } },

  // --- audit trail ------------------------------------------------------------------
  { match: /^\/audit-logs\/(finance|compensation)$/, body: { data: [AUDIT_EVENT], meta: { current_page: 1, last_page: 1, total: 1 } } },
  { match: /^\/audit-logs\/[^/]+$/, body: { data: AUDIT_EVENT } },
  { match: /^\/audit-logs$/, body: { data: [AUDIT_EVENT], meta: { current_page: 1, last_page: 1, total: 1 } } },
];

/** Build the `/me` bootstrap body for a screen. */
function bootstrapBody(screen: AuditScreen): unknown {
  const o = screen.bootstrap ?? {};
  const isPublic = screen.role === 'public';
  const isPlatform = o.isPlatformStaff ?? screen.role === 'super_administrator';
  const membershipRole =
    'role' in o
      ? o.role
      : isPublic || isPlatform
        ? null
        : MEMBERSHIP_ROLE[screen.role as keyof typeof MEMBERSHIP_ROLE];

  const permissions = o.permissions ?? [
    ...new Set([
      ...(PERMISSIONS_BY_KEY.get(screen.key) ?? []),
      ...ROLE_BASELINE_PERMISSIONS[screen.role],
    ]),
  ];

  return {
    data: {
      user: {
        id: id('USER'),
        email: 'ada@glowstudio.co.ke',
        name: 'Ada Mwangi',
        status: 'active',
        email_verified_at: '2026-06-14T00:00:00+00:00',
        is_platform_staff: isPlatform,
      },
      merchant: isPlatform
        ? null
        : {
            id: 'm1',
            name: LONG_MERCHANT_NAME,
            slug: 'glow-studio-westlands',
            status: 'active',
            service_fee_tier: 'standard',
            setup_completed_at: o.setupRequired === true ? null : '2026-01-01T00:00:00Z',
          },
      membership: membershipRole ? { id: 'mm1', role: membershipRole, status: 'active' } : null,
      memberships: membershipRole ? [{ id: 'mm1', role: membershipRole, status: 'active' }] : [],
      permissions,
      setup: o.setupRequired === true
        ? { required: true, current_step: 'business_profile', completed_at: null }
        : { required: false, current_step: null, completed_at: '2026-01-01T00:00:00Z' },
      branch_ids: o.branchIds ?? (isPlatform ? [] : [IDS.branch]),
      // UI-03 added `account_keys` to /me (derived server-side by AccountContextResolver) and
      // `requiresAccount` asks `holdsAccount()` for it. Without it every guarded account surface
      // denies, which is what took out the platform screens in the first full-suite run.
      account_keys: isPublic ? [] : [screen.role],
      mfa: {
        required: false,
        enrolled: true,
        confirmed: true,
        verified: true,
        enrollment_required: false,
        challenge_required: false,
        step_up_fresh: true,
        step_up_fresh_until: '2026-07-15T09:30:00+00:00',
        recovery_codes_remaining: 8,
        ...(o.mfa ?? {}),
      },
    },
  };
}

/**
 * Install the deterministic environment for one screen: fixed clock, fixed theme, stubbed
 * session and stubbed API. Must be called before the first navigation.
 */
export async function prepare(
  page: Page,
  screen: AuditScreen,
  opts: { theme?: Theme; fixtures?: Fixture[] } = {},
): Promise<void> {
  await page.clock.setFixedTime(new Date(AUDIT_INSTANT_UTC));

  const theme = opts.theme ?? 'light';
  await page.addInitScript((t) => {
    const marker = 'servana.release-audit-initial-theme';
    // A new explicit prepare(light|dark) replaces the preceding case's value. Reloading within
    // that same prepared case leaves a user-selected value alone, which proves real persistence.
    if (sessionStorage.getItem(marker) !== t) {
      localStorage.setItem('servana.theme', t);
      sessionStorage.setItem(marker, t);
    }
  }, theme);

  /*
   * The account context the Laravel shell embeds (Phase UI-02/UI-03). `vite preview` serves a
   * static index.html with no shell, so the element is absent and `currentAccountContext()` is
   * null — which makes UI-03's `requiresAccount` guard fail closed on every account surface it is
   * attached to. Stubbing it is exactly what this harness already does for /me and /sanctum: stub
   * the server so the REAL frontend can be driven. The guard is untouched.
   */
  /*
   * Phase UI-06: the PUBLIC surfaces need the context too. `/`, `/faq` and `/legal/<doc>` resolve
   * their account from the shell exactly as the authenticated surfaces do, so without it they
   * render the fail-closed boundary and the audit would grade a boundary instead of the page. The
   * accommodation is unchanged in kind — stub the server so the REAL frontend can be driven — and
   * `public` screens are bootstrapped as the Merchant Administrator account, which is the host
   * whose public surface the audit walks.
   */
  {
    const accountKeyForContext = screen.role === 'public' ? 'merchant_administrator' : screen.role;
    await page.addInitScript((accountKey) => {
      const inject = (): void => {
        if (document.head === null) return;
        if (document.getElementById('servana-account-context') !== null) return;
        const element = document.createElement('script');
        element.id = 'servana-account-context';
        element.type = 'application/json';
        element.textContent = JSON.stringify({
          account_key: accountKey,
          display_name: accountKey,
          environment: 'local',
          host: 'localhost',
        });
        document.head.appendChild(element);
      };

      if (document.readyState === 'loading') document.addEventListener('readystatechange', inject);
      inject();
    }, accountKeyForContext);
  }

  const fixtures = opts.fixtures ?? baseFixtures();
  const unauthenticated = screen.role === 'public';
  const me = bootstrapBody(screen);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));

  await page.route('**/api/v1/**', (route) => {
    const request = route.request();
    const method = request.method();
    const path = new URL(request.url()).pathname.replace(/^\/api\/v1/, '');

    if (path === '/me') {
      return route.fulfill(
        unauthenticated
          ? ok({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }, 401)
          : ok(me),
      );
    }

    for (const fixture of fixtures) {
      if (fixture.match.test(path) && (fixture.method ?? method) === method) {
        return route.fulfill(ok(fixture.body, fixture.status ?? 200));
      }
    }

    if (method === 'GET') {
      return route.fulfill(ok(EMPTY_LIST));
    }
    return route.fulfill(ok({ data: { id: id('CREATED') } }, method === 'POST' ? 201 : 200));
  });
}

/**
 * The locator proving THIS screen's own content rendered. Most screens are identified by their
 * heading; a rendered access state has no heading, so it declares its own marker.
 */
export function readyLocator(screen: AuditScreen): string {
  return screen.ready ?? 'h1, h2';
}

/** Navigate to a screen and wait for its own content, not merely the shell. */
export async function open(page: Page, screen: AuditScreen): Promise<void> {
  await page.goto(screen.path);
  await expect(page.locator(readyLocator(screen)).first()).toBeVisible();
}

// --- Assertions ---------------------------------------------------------------

/**
 * No page-level horizontal overflow (Plan §28). The failure message names the widest elements
 * that reach past the viewport, so a defect is diagnosable from CI output alone.
 */
export async function assertNoHorizontalOverflow(page: Page, label: string): Promise<void> {
  const { scrollWidth, clientWidth, widest } = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    const widest: string[] = [];
    for (const el of Array.from(document.body.querySelectorAll<HTMLElement>('*'))) {
      const rect = el.getBoundingClientRect();
      if (rect.right > clientWidth + 0.5 && rect.width > 0) {
        widest.push(
          `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}[${el.getAttribute('class')?.slice(0, 70) ?? ''}] right=${Math.round(rect.right)} width=${Math.round(rect.width)}`,
        );
      }
      if (widest.length >= 6) break;
    }
    return { scrollWidth: document.documentElement.scrollWidth, clientWidth, widest };
  });

  expect(
    scrollWidth,
    `${label}: page scrolls horizontally (scrollWidth ${scrollWidth} > clientWidth ${clientWidth})\n${widest.join('\n')}`,
  ).toBeLessThanOrEqual(clientWidth);
}

/**
 * Every rendered element stays inside the viewport width. Catches the overflow a page-level
 * check misses when an inner container clips instead of scrolling the document.
 */
export async function assertNoElementOverflow(page: Page, label: string): Promise<void> {
  const offenders = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const out: string[] = [];
    for (const el of Array.from(document.body.querySelectorAll<HTMLElement>('*'))) {
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) continue;
      // An element inside a deliberately scrollable container is contained, not overflowing.
      let ancestor: HTMLElement | null = el.parentElement;
      let scrollable = false;
      while (ancestor && ancestor !== document.body) {
        const overflowX = getComputedStyle(ancestor).overflowX;
        if (overflowX === 'auto' || overflowX === 'scroll' || overflowX === 'hidden') {
          scrollable = true;
          break;
        }
        ancestor = ancestor.parentElement;
      }
      if (scrollable) continue;
      if (rect.right > width + 1 || rect.left < -1) {
        out.push(
          `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}.${el.className?.toString().slice(0, 60)} → [${Math.round(rect.left)}, ${Math.round(rect.right)}]`,
        );
      }
      if (out.length >= 5) break;
    }
    return out;
  });

  expect(offenders, `${label}: elements extend past the viewport:\n${offenders.join('\n')}`).toEqual([]);
}

/** The application shell renders inside the viewport and its navigation is reachable. */
export async function assertShellUsable(page: Page, screen: AuditScreen, viewport: Viewport): Promise<void> {
  if (screen.role === 'public' || !isShellScreen(screen)) return;

  const label = `${screen.key} @ ${viewport.width}`;
  const main = page.locator('#main-content');
  await expect(main, `${label}: main landmark`).toBeVisible();

  const platform = screen.role === 'super_administrator';
  const mobile = viewport.width < 768;
  const tablet = !platform && viewport.width >= 768 && viewport.width < 1025;
  // ADR-018 keeps Super Administrator navigation in the header from tablet upward. All other
  // account shells use the drawer only on mobile, the collapsible rail on tablet, and the persistent
  // sidebar at the exact desktop floor. This mirrors the token-owned 767/768 and 1024/1025 edges.
  if (mobile) {
    await expect(
      page.locator('[data-testid="nav-drawer-trigger"]'),
      `${label}: mobile navigation trigger`,
    ).toBeVisible();
  } else if (tablet) {
    await expect(
      page.locator('[data-testid="tablet-navigation-rail"]'),
      `${label}: tablet navigation rail`,
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="nav-drawer-trigger"]'),
      `${label}: mobile navigation trigger is absent on tablet`,
    ).toBeHidden();
  } else {
    await expect(
      page.locator(platform ? '[data-testid="header-primary-nav"]' : '[data-testid="sidebar-primary-nav"]'),
      `${label}: persistent primary navigation`,
    ).toBeVisible();
  }
}

/**
 * Screens rendered inside the authenticated role shell with its navigation.
 *
 * `unsupported-role` is excluded deliberately: RoleShell's fail-safe boundary renders a bare
 * `main` with NO navigation, because an unresolved role must not be offered another role's
 * menu. That is the designed behaviour, not a missing shell.
 */
export function isShellScreen(screen: AuditScreen): boolean {
  const STANDALONE = new Set([
    'home', 'not-found', 'design-system', 'legal-document',
    'auth-login', 'auth-register', 'auth-check-email', 'auth-verify',
    'staff-invitation-accept', 'mfa-setup', 'mfa-challenge', 'first-time-setup',
    // `auth-access-denied` renders with `layout: none` (see docs/frontend/screens/inventory.json),
    // exactly like `unsupported-role`: a deliberate role-safe dead end with no account chrome, so
    // there is no shell navigation to assert. Phase UI-03 added the screen without listing it here.
    'global-search', 'unsupported-role', 'auth-access-denied',
  ]);
  return !STANDALONE.has(screen.key);
}

/** Zero serious/critical axe violations (Plan §30). */
export async function assertAxeClean(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  const blocking = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(
    blocking,
    `${label}: axe serious/critical violations:\n${blocking
      .map((v) => `${v.id} (${v.impact}) — ${v.nodes.length} node(s): ${v.nodes[0]?.target.join(' ')}`)
      .join('\n')}`,
  ).toEqual([]);
}

/** Viewport zoom must never be disabled (Plan §28; guardrail 1). */
export async function assertZoomEnabled(page: Page): Promise<void> {
  const viewportMeta = await page.evaluate(
    () => document.querySelector('meta[name="viewport"]')?.getAttribute('content') ?? '',
  );
  expect(viewportMeta, 'viewport meta must not disable zoom').not.toMatch(
    /user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*(1(\.0+)?|0)\b/i,
  );
}
