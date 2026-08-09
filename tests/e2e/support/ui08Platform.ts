import type { Page, Request } from '@playwright/test';
import { stubAccountContextFor } from './roleBootstrap';

/**
 * The Super Administrator browser harness for Phase UI-08 (Increment 10).
 *
 * `vite preview` serves the built SPA with no backend, so the platform reads are stubbed and the
 * REAL frontend is driven against them: real router, real guards, real components, real stores.
 * Server-side authorization is proven by the feature suites — this proves the experience.
 *
 * Every fixture below is SYNTHETIC. No real merchant, person, phone number, credential, token,
 * session identifier or provider payload appears in any response or any screenshot.
 */

export const SUPER_ADMIN_PERMISSIONS = [
  'platform.merchant.view',
  'platform.merchant.suspend',
  'platform.merchant.reactivate',
  'platform.merchant.deactivate',
  'platform.registration_monitor.view',
  'platform.billing_settings.view',
  'platform.billing_settings.update',
  'platform.settings.view',
  'platform.settings.update',
  'platform.plan.view',
  'platform.plan.manage',
  'platform.plan_price.manage',
  'platform.promotion.manage',
  'platform.free_period_offer.manage',
  'platform.preferred_personnel_fee.manage',
  'platform.platform_fee.configure',
  'platform.audit.view',
  'platform.internal_access.view',
  'platform.internal_access.manage',
];

/** The 17 implemented contract destinations, in contract order. */
export const IMPLEMENTED_PAGES = [
  { screen: 'dashboard', path: '/dashboard', testid: 'platform-dashboard-screen', h1: 'Platform dashboard', group: 'Home' },
  { screen: 'get-started', path: '/get-started', testid: 'platform-get-started-screen', h1: 'Get started', group: 'Home' },
  { screen: 'billing-settings', path: '/billing/settings', testid: 'platform-billing-settings-screen', h1: 'Platform billing settings', group: 'Billing & Commercial' },
  { screen: 'billing-plans', path: '/billing/plans', testid: 'platform-plans-screen', h1: 'Plans and entitlements', group: 'Billing & Commercial' },
  { screen: 'billing-prices', path: '/billing/prices', testid: 'platform-prices-screen', h1: 'Plan prices and billing periods', group: 'Billing & Commercial' },
  { screen: 'billing-promotions', path: '/billing/promotions', testid: 'platform-promotions-screen', h1: 'Promotional discounts', group: 'Billing & Commercial' },
  { screen: 'billing-free-periods', path: '/billing/free-periods', testid: 'platform-free-periods-screen', h1: 'Free-period offers', group: 'Billing & Commercial' },
  { screen: 'billing-preferred-personnel-fees', path: '/billing/preferred-personnel-fees', testid: 'platform-preferred-fees-screen', h1: 'Preferred personnel fee rules', group: 'Billing & Commercial' },
  { screen: 'billing-sms', path: '/billing/sms', testid: 'sms-billing-screen', h1: 'SMS billing settings', group: 'Billing & Commercial' },
  { screen: 'merchant-registrations', path: '/merchants/registrations', testid: 'platform-merchant-registrations-screen', h1: 'Registration monitoring', group: 'Merchants' },
  { screen: 'merchants', path: '/merchants', testid: 'platform-merchant-directory-screen', h1: 'Merchant directory', group: 'Merchants' },
  { screen: 'merchant-detail', path: '/merchants/01JQ0000000000000000000001', testid: 'platform-merchant-detail-screen', h1: 'Acme Salon', group: 'Merchants' },
  { screen: 'billing-subscriptions', path: '/billing/subscriptions', testid: 'subscription-operations-screen', h1: 'Subscription operations', group: 'Billing Operations' },
  { screen: 'audit', path: '/audit', testid: 'platform-audit-screen', h1: 'Platform audit', group: 'Reporting & Audit' },
  { screen: 'platform-access', path: '/platform-access', testid: 'platform-access-screen', h1: 'Internal platform access', group: 'Platform Administration' },
  { screen: 'feature-flags', path: '/platform/feature-flags', testid: 'feature-flags-screen', h1: 'Feature flags', group: 'Platform Administration' },
  { screen: 'account', path: '/account', testid: 'platform-account-screen', h1: 'Account and security', group: 'Utility' },
];

/** The five contract entries that stay blocked. Their canonical paths must NOT resolve to a page. */
export const GATED_PAGES = [
  { screen: 'billing-reconciliation-exceptions', path: '/billing/reconciliation-exceptions', key: 'super_administrator.billing-reconciliation-exceptions', group: 'Billing Operations' },
  { screen: 'integrations', path: '/integrations', key: 'super_administrator.integrations', group: 'Integrations' },
  { screen: 'integrations-refer-and-earn-qualifications', path: '/integrations/refer-and-earn/qualifications', key: 'super_administrator.integrations-refer-and-earn-qualifications', group: 'Integrations' },
  { screen: 'reports', path: '/reports', key: 'super_administrator.reports', group: 'Reporting & Audit' },
  { screen: 'notifications', path: '/notifications', key: 'super_administrator.notifications', group: 'Utility' },
];

export const NAVIGATION_GROUPS = [
  'Home',
  'Billing & Commercial',
  'Merchants',
  'Billing Operations',
  'Integrations',
  'Reporting & Audit',
  'Platform Administration',
  'Utility',
];

const MERCHANT_A = '01JQ0000000000000000000001';
const MERCHANT_B = '01JQ0000000000000000000002';

const json = (body: unknown, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(body),
});

const page1 = (rows: unknown[]) => ({ data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, per_page: 25 } });

function merchant(overrides: Record<string, unknown> = {}) {
  return {
    id: MERCHANT_A,
    name: 'Acme Salon',
    operational_status: 'active',
    billing_status: 'suspended_billing',
    billing_status_reason: 'Invoice unpaid past grace',
    suspension_reason: null,
    suspended_at: null,
    deactivated_at: null,
    setup_completed_at: '2026-07-03T00:00:00+00:00',
    registered_at: '2026-07-01T00:00:00+00:00',
    can: { suspend: true, reactivate: false, deactivate: true },
    ...overrides,
  };
}

/**
 * Stub `/me` for the Super Administrator, with the account context the Laravel shell embeds.
 *
 * `accountKey` is what the SERVER says this host serves — never the URL. Passing a different
 * account is how the deny cases below are built.
 */
export async function stubSuperAdmin(
  page: Page,
  options: { permissions?: string[]; accountKey?: string; accountKeys?: string[]; mfaChallengeRequired?: boolean } = {},
): Promise<void> {
  const accountKey = options.accountKey ?? 'super_administrator';
  await stubAccountContextFor(page, accountKey, accountKey);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill(
      json({
        data: {
          user: {
            id: '01JUSER0000000000000000001',
            email: 'owner@citrus.example',
            name: 'Platform Owner',
            status: 'active',
            email_verified_at: '2026-01-01T00:00:00+00:00',
            is_platform_staff: true,
            theme_preference: null,
            resolved_theme: 'light',
          },
          merchant: null,
          membership: null,
          memberships: [],
          permissions: options.permissions ?? SUPER_ADMIN_PERMISSIONS,
          account_keys: options.accountKeys ?? ['super_administrator'],
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: [],
          mfa: {
            required: true, enrolled: true, confirmed: true, verified: true,
            enrollment_required: false, challenge_required: options.mfaChallengeRequired ?? false,
            step_up_fresh: true, step_up_fresh_until: '2099-01-01T00:00:00+00:00', recovery_codes_remaining: 8,
          },
        },
      }),
    ),
  );
}

/** Every platform read the seventeen pages make, with synthetic data. */
export async function stubPlatformApi(page: Page): Promise<void> {
  const routes: Array<[RegExp, unknown]> = [
    [/\/api\/v1\/platform\/dashboard$/, {
      data: {
        registrations: { total: 128, last_7_days: 9, pending_setup: 4, completed_setup: 124 },
        lifecycle: { active: 118, suspended: 3, deactivated: 3, pending_setup: 4 },
        commercial: { billing_mode: 'subscription_only', active_plans: 3, active_promotions: 1, active_free_period_offers: 1 },
        billing: { trialing: 12, overdue: 5, read_only_grace: 2, suspended_billing: 3, open_invoice_minor_units: 480000, currency: 'KES' },
        audit: { events_last_24h: 46, high_severity_last_7_days: 2, critical_last_7_days: 0 },
        integrations: {
          availability: 'disabled_by_gate',
          gate: 'external_gate_w',
          gate_statement: 'External Gate W (Wallet by Citrus collections readiness) is closed, so no Wallet client health, reconciliation or settlement figure exists to report.',
        },
        tasks: { merchants_suspended_for_billing: 3, merchants_suspended_by_policy: 1, overdue_invoices: 5 },
      },
      meta: { generated_at: '2026-08-09T06:00:00+00:00' },
    }],
    [/\/api\/v1\/platform\/billing-settings$/, { data: { id: '01SET', billing_mode: 'subscription_only', trial_days: 14, grace_days: 7, overdue_days: 14, suspension_days: 30, effective_from: '2026-01-01', version: 3, can: { update: true } } }],
    [/\/api\/v1\/platform\/plans\/[^/]+\/prices/, page1([{ id: '01PRICE', plan_id: '01PLAN', billing_interval: 'monthly', amount_minor_units: 250000, currency: 'KES', effective_from: '2026-01-01', effective_to: null, lifecycle: 'current', can: { cancel: false } }])],
    [/\/api\/v1\/platform\/plans\/[^/]+\/entitlements/, page1([{ entitlement_key: 'branches.max', enabled: true, limit_int: 3 }])],
    [/\/api\/v1\/platform\/plans(\?|$)/, page1([{ id: '01PLAN', key: 'growth', name: 'Growth', status: 'active', prices: [], entitlements: [], can: { retire: true, manage: true } }])],
    [/\/api\/v1\/platform\/promotional-discounts/, page1([{ id: '01PROMO', name: 'Launch 10%', status: 'active', discount_type: 'percentage', basis_points: 1000, target_type: 'global', effective_from: '2026-07-01', effective_to: null, can: { approve: false, pause: true, resume: false, cancel: true, update: false } }])],
    [/\/api\/v1\/platform\/free-period-offers/, page1([{ id: '01FREE', name: 'Free 30', status: 'active', free_days: 30, target_type: 'global', effective_from: '2026-07-01', effective_to: null, can: { approve: false, pause: true, resume: false, cancel: true, update: false } }])],
    [/\/api\/v1\/platform\/preferred-personnel-fee-rules/, page1([{ id: '01FEE', calculation_type: 'fixed', fixed_amount_minor_units: 5000, basis_points: null, scope: 'platform_default', service_id: null, status: 'active', effective_from: '2026-01-01', can: { approve: false, cancel: false, supersede: true } }])],
    [/\/api\/v1\/platform\/platform-fee-configurations/, page1([{ id: '01PFC', tier: 'shared', basis_points: 250, merchant_split_basis_points: 125, status: 'active', effective_from: '2026-01-01', can: { approve: false, supersede: true, cancel: false, update: false } }])],
    [/\/api\/v1\/platform\/sms-billing-usage/, page1([{ merchant_id: MERCHANT_A, merchant_name: 'Acme Salon', billable_units: 420, amount_minor: 42000, currency: 'KES', period_start: '2026-07-01', period_end: '2026-07-31' }])],
    [/\/api\/v1\/platform\/sms-billing-charge-reconciliation/, {
      data: {
        as_of: '2026-08-09T06:00:00+00:00',
        status_rollup: [{ status: 'billed', entry_count: 12, amount_minor: 42000 }],
        invoice_mapping: { linked_count: 12, linked_amount_minor: 42000, unlinked_count: 0, unlinked_amount_minor: 0 },
        thresholds: {
          billable_units_this_month: 420,
          billable_units_previous_month: 390,
          warning_threshold_units: '1000',
          warning_state: 'normal',
          anomaly_threshold_basis_points: '5000',
          growth_basis_points: 769,
          anomaly_state: 'normal',
        },
      },
      meta: { currency: 'KES' },
    }],
    [/\/api\/v1\/platform\/sms-billing-settings\/cost-notice-preview/, { data: { billable_units: 3, unit_price_minor: 100, amount_minor: 300, currency: 'KES' } }],
    [/\/api\/v1\/platform\/sms-billing-settings\/versions/, page1([{ id: '01SMS', unit_price_minor: 100, currency: 'KES', state: 'in_force', effective_from: '2026-01-01', effective_to: null, scheduled_by: 'o***@citrus.example', can: { cancel: false } }])],
    [/\/api\/v1\/platform\/sms-billing-settings(\?|$)/, { data: { current: { id: '01SMS', unit_price_minor: 100, currency: 'KES', state: 'in_force', effective_from: '2026-01-01', effective_to: null, can: { cancel: false } }, next: null, currency: 'KES', currency_authority: 'Platform billing settings' } }],
    [/\/api\/v1\/platform\/subscription-operations\/summary/, {
      data: {
        as_of: '2026-08-09T06:00:00+00:00',
        subscriptions_by_status: JSON.stringify({ active: 106, trialing: 12, suspended_billing: 3 }),
        invoices_by_status: JSON.stringify({ issued: 18, paid: 240, void: 2 }),
        cohorts: { trialing: '12', in_grace: '2', overdue: '5', suspended_billing: '3', cancelled_or_expired: '4' },
        funnel: { trial_started: '40', converted_to_active: '31', lapsed: '9' },
        totals: { subscriptions: 128, invoices: 260, open_invoice_balance_minor: 480000 },
      },
      meta: {
        definitions: {
          subscriptions_by_status: 'Every subscription counted once, by its current state.',
          invoices_by_status: 'Every subscription invoice counted once, by its current state.',
          cohorts: 'Merchants grouped by the billing cohort they are in today.',
          funnel: 'Trials started in the window, and what became of them.',
          open_invoice_balance_minor: 'The unpaid balance across issued subscription invoices, in minor units.',
        },
        time_range: 'Last 30 days, Africa/Nairobi business days.',
        authorization_authority: 'platform.merchant.view; the API re-checks it on every read.',
      },
    }],
    [/\/api\/v1\/platform\/subscriptions(\?|$)/, page1([{ id: '01SUB', merchant: { id: MERCHANT_A, name: 'Acme Salon' }, plan: { id: '01PLAN', name: 'Growth' }, billing_interval: 'monthly', state: 'active', current_state: { state: 'active', explanation: 'Paid and inside the current billing period.' }, current_period_end: '2026-08-31', amount_minor: 250000, currency: 'KES' }])],
    [/\/api\/v1\/platform\/subscription-invoices/, page1([{ id: '01INV', number: 'SUB-2026-000042', merchant: { id: MERCHANT_A, name: 'Acme Salon' }, state: 'issued', total_minor: 250000, balance_minor: 250000, currency: 'KES', issued_at: '2026-08-01T00:00:00+00:00', due_at: '2026-08-15T00:00:00+00:00' }])],
    [/\/api\/v1\/platform\/billing-credits/, page1([{ id: '01CR', merchant: { id: MERCHANT_A, name: 'Acme Salon' }, amount_minor: 15000, currency: 'KES', reason: 'Goodwill', issued_at: '2026-07-20T00:00:00+00:00' }])],
    [/\/api\/v1\/platform\/subscription-escalations/, page1([{ id: '01ESC', merchant: { id: MERCHANT_A, name: 'Acme Salon' }, current_state: { state: 'open', explanation: 'The subscription is past its grace window with an unpaid invoice.' }, state: 'open', severity: 'high', opened_at: '2026-08-02T00:00:00+00:00', reason: 'Overdue beyond grace' }])],
    [/\/api\/v1\/platform\/registration-monitor/, page1([
      { id: MERCHANT_A, name: 'Acme Salon', operational_status: 'active', billing_status: 'suspended_billing', pending_setup: false, registered_at: '2026-07-01T00:00:00+00:00', setup_completed_at: '2026-07-03T00:00:00+00:00' },
      { id: MERCHANT_B, name: 'Bella Spa', operational_status: 'pending_setup', billing_status: 'trialing', pending_setup: true, registered_at: '2026-07-04T00:00:00+00:00', setup_completed_at: null },
    ])],
    [/\/api\/v1\/platform\/merchants\/[^/?]+$/, { data: merchant() }],
    [/\/api\/v1\/platform\/merchants(\?|$)/, page1([merchant(), merchant({ id: MERCHANT_B, name: 'Bella Spa', operational_status: 'suspended', billing_status: 'active', can: { suspend: false, reactivate: true, deactivate: true } })])],
    [/\/api\/v1\/platform\/audit-logs\/[^/?]+$/, { data: { id: '01AUDIT0000000000000000001', action: 'merchant.suspended', severity: 'high', actor: 'o***@citrus.example', subject_type: 'Merchant', context: { merchant_id: MERCHANT_A, from_status: 'active', to_status: 'suspended', reason: 'Policy review' }, correlation_id: 'corr-demo-1', created_at: '2026-08-01T09:00:00+00:00', can: { view: true } } }],
    [/\/api\/v1\/platform\/audit-logs(\?|$)/, page1([{ id: '01AUDIT0000000000000000001', action: 'merchant.suspended', severity: 'high', actor: 'o***@citrus.example', subject_type: 'Merchant', context: { merchant_id: MERCHANT_A, from_status: 'active', to_status: 'suspended' }, correlation_id: 'corr-demo-1', created_at: '2026-08-01T09:00:00+00:00', can: { view: true } }])],
    [/\/api\/v1\/platform\/internal-access\/invitations/, page1([{ id: '01INVIT', email: 'n***@citrus.example', status: 'pending', invited_at: '2026-08-05T00:00:00+00:00', expires_at: '2026-08-12T00:00:00+00:00', can: { resend: true, revoke: true } }])],
    [/\/api\/v1\/platform\/internal-access\/users/, page1([{
      id: '01PU',
      status: 'active',
      grants_access: true,
      granted_permissions: ['platform.merchant.view'],
      denied_permissions: [],
      user: { id: '01JUSER0000000000000000002', name: 'Second Owner', email: 's***@citrus.example', last_login_at: '2026-08-08T00:00:00+00:00' },
      can: { suspend: true, reactivate: false, deactivate: true, revoke_sessions: true, update_permissions: true },
    }])],
    [/\/api\/v1\/platform\/feature-flag-change-requests/, page1([{ id: '01FR', flag_key: 'platform.demo_flag', status: 'pending', requested_state: 'enabled', reason: 'Pilot', requested_at: '2026-08-07T00:00:00+00:00', can: { approve: true, reject: true, cancel: true } }])],
    [/\/api\/v1\/platform\/feature-flags/, page1([{ key: 'platform.demo_flag', state: 'disabled', description: 'A synthetic demonstration flag.', targets: [], history: [], can: { request_change: true, pause: false } }])],
    [/\/api\/v1\/auth\/sessions/, { data: [{ id: '01JSESSION000000000000001', account_key: 'super_administrator', host: 'citrus.servana.ke', merchant_name: null, branch_name: null, created_at: '2026-08-01T08:00:00+00:00', last_activity_at: '2026-08-09T06:00:00+00:00', revoked: false, is_current: true }] }],
    [/\/api\/v1\/auth\/mfa$/, { data: { mfa: { enrolled: true, confirmed: true, required: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: '2099-01-01T00:00:00+00:00', recovery_codes_remaining: 8 } } }],
    [/\/api\/v1\/auth\/account-contexts/, { data: [{ context_id: 'c1', account_key: 'super_administrator', display_name: 'Citrus platform', target_host: 'citrus.servana.ke', default_route: '/dashboard', requires_mfa: true, merchant_id: null, merchant_name: null, branch_id: null, branch_name: null, role_label: 'Super Administrator', is_current: true }] }],
  ];

  // Playwright matches the LAST matching route first, so the fallback is registered FIRST and the
  // specific fixtures below override it. Registering it last silently answered every platform read
  // with an empty collection — the pages rendered, but on no data.
  //
  // The fallback exists so an unstubbed read is an empty, well-formed collection rather than a
  // failed request: a missing fixture must not be mistaken for a product defect.
  await page.route(/\/api\/v1\/platform\//, (route) => route.fulfill(json(page1([]))));

  for (const [pattern, body] of routes) {
    await page.route(pattern, (route) => route.fulfill(json(body)));
  }
}

/** Collect console errors, page errors and failed requests for the browser-health assertions. */
export interface BrowserHealth {
  consoleErrors: string[];
  pageErrors: string[];
  failedRequests: string[];
  apiRequests: string[];
}

export function watchBrowserHealth(page: Page): BrowserHealth {
  const health: BrowserHealth = { consoleErrors: [], pageErrors: [], failedRequests: [], apiRequests: [] };

  page.on('console', (message) => {
    if (message.type() === 'error') health.consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => health.pageErrors.push(error.message));
  page.on('requestfailed', (request: Request) => {
    // A navigation aborted by a same-page redirect is not a failed resource.
    if (request.failure()?.errorText === 'net::ERR_ABORTED') return;
    health.failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText ?? 'unknown'}`);
  });
  page.on('request', (request) => {
    if (request.url().includes('/api/v1/')) health.apiRequests.push(request.url());
  });

  return health;
}
