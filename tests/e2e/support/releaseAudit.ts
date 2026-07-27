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
    key: 'legal-document',
    route: 'legal.document',
    path: '/legal/merchant_administrator/terms-of-service',
    role: 'public',
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
    route: 'onboarding.first-time-setup',
    path: '/onboarding/first-time-setup',
    role: 'merchant_administrator',
    bootstrap: { setupRequired: true },
    state: 'static',
  },

  // --- access states (rendered, route-less) ---------------------------------
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

  // --- Super Administrator ---------------------------------------------------
  { key: 'platform-landing', route: 'platform.landing', path: '/platform', role: 'super_administrator', state: 'static' },
  { key: 'platform-get-started', route: 'platform.get-started', path: '/platform/get-started', role: 'super_administrator', state: 'static' },
  { key: 'platform-dashboard', route: 'platform.dashboard', path: '/platform/dashboard', role: 'super_administrator', state: 'static' },
  { key: 'platform-billing-settings', route: 'platform.billing-settings', path: '/platform/billing-settings', role: 'super_administrator', state: 'populated' },
  { key: 'platform-promotions', route: 'platform.promotions', path: '/platform/promotions', role: 'super_administrator', state: 'populated' },
  { key: 'platform-registration-monitoring', route: 'platform.registration-monitoring', path: '/platform/registration-monitoring', role: 'super_administrator', state: 'populated' },

  // --- Merchant Administrator ------------------------------------------------
  { key: 'merchant-landing', route: 'merchant.landing', path: '/merchant', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-get-started', route: 'merchant.get-started', path: '/merchant/get-started', role: 'merchant_administrator', state: 'static' },
  { key: 'merchant-dashboard', route: 'merchant.dashboard', path: '/merchant/dashboard', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-profile', route: 'merchant.profile', path: '/merchant/profile', role: 'merchant_administrator', state: 'populated' },
  { key: 'subscription-dashboard', route: 'merchant.subscription', path: '/merchant/subscription', role: 'merchant_administrator', state: 'populated' },
  { key: 'plan-management', route: 'merchant.plan', path: '/merchant/plan', role: 'merchant_administrator', state: 'populated' },
  { key: 'subscription-invoices', route: 'merchant.invoices', path: '/merchant/subscription-invoices', role: 'merchant_administrator', state: 'populated' },
  { key: 'compensation-summary', route: 'merchant.compensation-summary', path: '/merchant/compensation-summary', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-period-reopen-approvals', route: 'merchant.period-reopen-approvals', path: '/merchant/period-reopen-approvals', role: 'merchant_administrator', state: 'populated' },
  { key: 'merchant-platform-fees', route: 'merchant.platform-fees', path: '/merchant/platform-fees', role: 'merchant_administrator', state: 'populated' },
  { key: 'branch-create', route: 'branch.create', path: '/branch/create', role: 'merchant_administrator', state: 'static' },

  // --- Branch Manager --------------------------------------------------------
  { key: 'branch-landing', route: 'branch.landing', path: '/branch', role: 'merchant_branch', state: 'static' },
  { key: 'branch-get-started', route: 'branch.get-started', path: '/branch/get-started', role: 'merchant_branch', state: 'static' },
  { key: 'branch-list', route: 'branch.list', path: '/branch/list', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-detail', route: 'branch.detail', path: `/branch/${IDS.branch}`, role: 'merchant_branch', state: 'populated' },
  { key: 'branch-operating-hours', route: 'branch.operating-hours', path: `/branch/${IDS.branch}/operating-hours`, role: 'merchant_branch', state: 'populated' },
  { key: 'branch-calendar', route: 'branch.calendar', path: `/branch/${IDS.branch}/calendar`, role: 'merchant_branch', state: 'populated' },
  { key: 'service-catalogue', route: 'branch.services', path: '/branch/services', role: 'merchant_branch', state: 'populated' },
  { key: 'personnel-schedule', route: 'branch.personnel-schedule', path: '/branch/personnel-schedule', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-appointments', route: 'branch.appointments', path: '/branch/appointments', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-queue', route: 'branch.queue', path: '/branch/queue', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-queue-configuration', route: 'branch.queue-configuration', path: '/branch/queue-configuration', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-cash-up', route: 'branch.cash-up', path: '/branch/cash-up', role: 'merchant_branch', state: 'populated' },
  { key: 'branch-platform-fees', route: 'branch.platform-fees', path: '/branch/platform-fees', role: 'merchant_branch', state: 'populated' },

  // --- HR --------------------------------------------------------------------
  { key: 'hr-landing', route: 'hr.landing', path: '/hr', role: 'merchant_human_resource', state: 'static' },
  { key: 'hr-get-started', route: 'hr.get-started', path: '/hr/get-started', role: 'merchant_human_resource', state: 'static' },
  { key: 'hr-staff', route: 'hr.staff', path: '/hr/staff', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-staff-profile', route: 'hr.staff-profile', path: `/hr/staff/${IDS.staff}`, role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-invitations', route: 'hr.invitations', path: '/hr/invitations', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-permission-preview', route: 'hr.permission-preview', path: '/hr/permission-preview', role: 'merchant_human_resource', state: 'populated' },
  { key: 'service-eligibility', route: 'hr.eligibility', path: '/hr/eligibility', role: 'merchant_human_resource', state: 'populated' },
  { key: 'personnel-availability', route: 'hr.availability', path: '/hr/availability', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-compensation', route: 'hr.compensation', path: '/hr/compensation', role: 'merchant_human_resource', state: 'populated' },
  { key: 'hr-payout-prep', route: 'hr.payout-runs', path: '/hr/payout-runs', role: 'merchant_human_resource', state: 'populated' },

  // --- Finance ---------------------------------------------------------------
  { key: 'finance-landing', route: 'finance.landing', path: '/finance', role: 'merchant_finance', state: 'static' },
  { key: 'finance-get-started', route: 'finance.get-started', path: '/finance/get-started', role: 'merchant_finance', state: 'static' },
  { key: 'finance-task-inbox', route: 'finance.dashboard', path: '/finance/dashboard', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-pending-validations', route: 'finance.pending-validations', path: '/finance/pending-validations', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-receipts', route: 'finance.receipts', path: '/finance/receipts', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-receipt-detail', route: 'finance.receipts.detail', path: `/finance/receipts/${IDS.receipt}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-refunds', route: 'finance.refunds', path: '/finance/refunds', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-refund-detail', route: 'finance.refunds.detail', path: `/finance/refunds/${IDS.refund}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-disputes', route: 'finance.disputes', path: '/finance/disputes', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-dispute-detail', route: 'finance.disputes.detail', path: `/finance/disputes/${IDS.dispute}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-cash-up', route: 'finance.cash-up', path: '/finance/cash-up', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-cash-up-detail', route: 'finance.cash-up.detail', path: `/finance/cash-up/${IDS.cashUp}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-periods', route: 'finance.periods', path: '/finance/periods', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-exports', route: 'finance.exports', path: '/finance/exports', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-invoices', route: 'finance.invoices', path: '/finance/invoices', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-invoice-detail', route: 'finance.invoices.detail', path: `/finance/invoices/${IDS.invoice}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payment-records', route: 'finance.payment-records', path: '/finance/payment-records', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payment-records-detail', route: 'finance.payment-records.detail', path: `/finance/payment-records/${IDS.paymentGroup}`, role: 'merchant_finance', state: 'populated' },
  { key: 'finance-audit', route: 'finance.audit', path: '/finance/audit', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-platform-fees', route: 'finance.platform-fees', path: '/finance/platform-fees', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-compensation-liabilities', route: 'finance.liabilities', path: '/finance/liabilities', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-payouts', route: 'finance.payout-runs', path: '/finance/payout-runs', role: 'merchant_finance', state: 'populated' },
  { key: 'finance-earnings-queries', route: 'finance.earnings-queries', path: '/finance/earnings-queries', role: 'merchant_finance', state: 'populated' },

  // --- Front Office ----------------------------------------------------------
  { key: 'front-office-landing', route: 'front-office.landing', path: '/front-office', role: 'merchant_front_office', state: 'static' },
  { key: 'front-office-get-started', route: 'front-office.get-started', path: '/front-office/get-started', role: 'merchant_front_office', state: 'static' },
  { key: 'front-office-dashboard', route: 'front-office.dashboard', path: '/front-office/dashboard', role: 'merchant_front_office', state: 'static' },
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
  { key: 'personnel-dashboard', route: 'personnel.dashboard', path: '/personnel/dashboard', role: 'merchant_personnel', state: 'static' },
  { key: 'personnel-appointments', route: 'personnel.appointments', path: '/personnel/appointments', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-queue', route: 'personnel.queue', path: '/personnel/queue', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-sessions', route: 'personnel.sessions', path: '/personnel/sessions', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-my-earnings', route: 'personnel.earnings', path: '/personnel/earnings', role: 'merchant_personnel', state: 'populated' },
  { key: 'personnel-sms', route: 'personnel.sms', path: '/personnel/sms', role: 'merchant_personnel', state: 'populated' },

  // --- Audit -----------------------------------------------------------------
  { key: 'audit-landing', route: 'audit.landing', path: '/audit', role: 'merchant_audit', state: 'static' },
  { key: 'audit-get-started', route: 'audit.get-started', path: '/audit/get-started', role: 'merchant_audit', state: 'static' },
  { key: 'audit-dashboard', route: 'audit.dashboard', path: '/audit/dashboard', role: 'merchant_audit', state: 'static' },
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
  return raw.screens.filter((s) => s.status !== 'planned');
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

const SHARED_FIXTURES: Fixture[] = [
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
  {
    match: /^\/staff$/,
    body: {
      data: [
        {
          id: IDS.staff,
          first_name: 'Amina',
          last_name: 'Wanjiku',
          display_name: 'Amina Wanjiku',
          phone: '+254712000333',
          role: 'personnel',
          role_title: 'Senior Stylist',
          status: 'active',
          employment_type: 'permanent',
          employment_status: 'active',
          primary_branch_id: IDS.branch,
          is_active: true,
        },
      ],
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
  await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);

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
  // Sidebar roles collapse below lg (1024); the platform header nav collapses below md (768).
  const collapsed = platform ? viewport.width < 768 : viewport.width < 1024;
  if (collapsed) {
    await expect(
      page.locator('[data-testid="nav-drawer-trigger"]'),
      `${label}: mobile navigation trigger`,
    ).toBeVisible();
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
    'global-search', 'unsupported-role',
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
