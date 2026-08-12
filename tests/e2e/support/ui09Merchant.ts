import type { Page, Request } from '@playwright/test';
import { stubAccountContextFor } from './roleBootstrap';

export const MERCHANT_PERMISSIONS = [
  'merchant.profile.view',
  'merchant.profile.update',
  'branches.create',
  'branches.manage_users_lifecycle',
  'merchant.subscription.view',
  'merchant.subscription.plan_change',
  'merchant.subscription.invoice.view',
  'merchant.subscription.invoice.download',
  'merchant.compensation_summary.view',
  'merchant.payout.approve_high_value',
  'merchant.period_reopen.approve_exception',
];

export const BRANCH_ID = '01JQBRANCH0000000000000001';
export const FOREIGN_BRANCH_ID = '01JQBRANCH0000000000000009';
export const INVOICE_ID = '01JQINVOICE000000000000001';

export const IMPLEMENTED_PAGES = [
  { screen: 'setup', path: '/setup', h1: 'Set up your business', api: '/merchant-registration/first-time-setup' },
  { screen: 'dashboard', path: '/dashboard', h1: 'Merchant overview', api: '/merchant/dashboard' },
  { screen: 'get-started', path: '/get-started', h1: 'Get started', api: '/merchant/dashboard' },
  { screen: 'merchant-profile', path: '/merchant/profile', h1: 'Business profile', api: '/merchant/profile' },
  { screen: 'branches', path: '/branches', h1: 'Branches', api: '/branches' },
  { screen: 'branch-detail', path: `/branches/${BRANCH_ID}`, h1: 'Kilimani Studio', api: `/branches/${BRANCH_ID}` },
  { screen: 'staff', path: '/staff', h1: 'Staff overview and lifecycle', api: '/merchant/staff-overview' },
  { screen: 'subscription', path: '/subscription', h1: 'Subscription and billing', api: '/subscription' },
  { screen: 'subscription-plan', path: '/subscription/plan', h1: 'Plan management', api: '/subscription/plans' },
  { screen: 'subscription-invoices', path: '/subscription/invoices', h1: 'Subscription invoices', api: '/subscription-invoices' },
  { screen: 'subscription-invoice-detail', path: `/subscription/invoices/${INVOICE_ID}`, h1: 'SUB-2026-000042', api: `/subscription-invoices/${INVOICE_ID}` },
  { screen: 'compensation', path: '/compensation', h1: 'Compensation summary', api: '/merchant/compensation-summary' },
  { screen: 'compensation-payout-approvals', path: '/compensation/payout-approvals', h1: 'High-value payout approvals', api: '/merchant/payout-runs' },
  { screen: 'finance-period-reopen-approvals', path: '/finance/period-reopen-approvals', h1: 'Exceptional period-reopen approvals', api: '/period-locks' },
  { screen: 'account', path: '/account', h1: 'Account and security', api: '/auth/sessions' },
] as const;

export const GATED_PAGES = [
  { screen: 'subscription-payment-attempts', path: '/subscription/payment-attempts', key: 'merchant_administrator.subscription-payment-attempts', group: 'Subscription & Billing' },
  { screen: 'subscription-recovery', path: '/subscription/recovery', key: 'merchant_administrator.subscription-recovery', group: 'Subscription & Billing' },
  { screen: 'reports', path: '/reports', key: 'merchant_administrator.reports', group: 'Reports' },
  { screen: 'reports-branches', path: '/reports/branches', key: 'merchant_administrator.reports-branches', group: 'Reports' },
  { screen: 'reports-services', path: '/reports/services', key: 'merchant_administrator.reports-services', group: 'Reports' },
  { screen: 'reports-staff', path: '/reports/staff', key: 'merchant_administrator.reports-staff', group: 'Reports' },
  { screen: 'reports-daily', path: '/reports/daily', key: 'merchant_administrator.reports-daily', group: 'Reports' },
  { screen: 'notifications', path: '/notifications', key: 'merchant_administrator.notifications', group: 'Utility' },
] as const;

export const NAVIGATION_GROUPS = [
  'Home',
  'Merchant',
  'Subscription & Billing',
  'Reports',
  'Compensation & Approvals',
  'Utility',
] as const;

const json = (body: unknown, status = 200) => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify(body),
});

const branch = {
  id: BRANCH_ID,
  name: 'Kilimani Studio',
  code: 'KLM',
  address: 'Ngong Road',
  town: 'Nairobi',
  phone: '+254700000000',
  email: 'kilimani@example.test',
  business_category: 'Salon',
  status: 'active',
  status_reason: null,
  archived_at: null,
};

const subscription = {
  id: '01JQSUBSCRIPTION000000000001',
  status: 'active',
  billing_status: 'active',
  billing_status_reason: null,
  billing_read_only: false,
  billing_interval: 'monthly',
  trial_started_at: '2026-08-01T00:00:00Z',
  trial_ends_at: '2026-08-15T00:00:00Z',
  current_period_start: '2026-08-01',
  current_period_end: '2026-09-01',
  plan: { id: '01JQPLAN000000000000000001', key: 'starter', name: 'Starter', tier: 'starter' },
  price: { id: '01JQPRICE00000000000000001', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
  scheduled_plan_change: null,
  can: { schedule_plan_change: true, download_invoice: true },
};

const plans = [
  {
    id: '01JQPLAN000000000000000001', key: 'starter', name: 'Starter', description: 'For one growing branch', tier: 'starter', is_current: true,
    effective_price: { id: '01JQPRICE00000000000000001', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
  },
  {
    id: '01JQPLAN000000000000000002', key: 'growth', name: 'Growth', description: 'For multi-branch businesses', tier: 'growth', is_current: false,
    effective_price: { id: '01JQPRICE00000000000000002', amount_minor: 900000, currency: 'KES', billing_interval: 'monthly' },
  },
];

const invoice = {
  id: INVOICE_ID,
  invoice_number: 'SUB-2026-000042',
  status: 'issued',
  period_start: '2026-08-01',
  period_end: '2026-09-01',
  subtotal_minor: 500000,
  discount_minor: 0,
  total_minor: 500000,
  balance_minor: 500000,
  currency: 'KES',
  issued_at: '2026-08-01T00:00:00Z',
  due_at: '2026-08-15T00:00:00Z',
  payment_reference_pending: true,
  account_reference: null,
  has_pdf: false,
  pdf_version: 0,
};

export interface MerchantBootstrapOptions {
  accountKey?: string;
  accountKeys?: string[];
  permissions?: string[];
  setupRequired?: boolean;
  mfaChallengeRequired?: boolean;
  stepUpFresh?: boolean;
}

export async function stubMerchant(page: Page, options: MerchantBootstrapOptions = {}): Promise<void> {
  const accountKey = options.accountKey ?? 'merchant_administrator';
  await stubAccountContextFor(page, accountKey, 'Glow Studio');
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) => route.fulfill(json({
    data: {
      user: {
        id: '01JQUSER000000000000000001',
        email: 'owner@example.test',
        name: 'Amina Owner',
        status: 'active',
        email_verified_at: '2026-08-01T00:00:00Z',
        is_platform_staff: false,
        theme_preference: null,
        resolved_theme: 'light',
      },
      merchant: {
        id: '01JQMERCHANT00000000000001',
        name: 'Glow Studio',
        slug: 'glow-studio',
        status: options.setupRequired ? 'pending_setup' : 'active',
        service_fee_tier: options.setupRequired ? null : 'customer_centric',
        setup_completed_at: options.setupRequired ? null : '2026-08-02T00:00:00Z',
      },
      membership: { id: '01JQMEMBERSHIP000000000001', role: 'merchant_admin', status: 'active' },
      memberships: [{ id: '01JQMEMBERSHIP000000000001', role: 'merchant_admin', status: 'active' }],
      permissions: options.permissions ?? MERCHANT_PERMISSIONS,
      account_keys: options.accountKeys ?? ['merchant_administrator'],
      setup: options.setupRequired
        ? { required: true, current_step: 'plan', completed_at: null }
        : { required: false, current_step: null, completed_at: '2026-08-02T00:00:00Z' },
      branch_ids: [BRANCH_ID],
      mfa: {
        required: true,
        enrolled: true,
        confirmed: true,
        verified: true,
        enrollment_required: false,
        challenge_required: options.mfaChallengeRequired ?? false,
        step_up_fresh: options.stepUpFresh ?? true,
        step_up_fresh_until: '2099-01-01T00:00:00Z',
        recovery_codes_remaining: 8,
      },
    },
  })));
}

export async function stubMerchantApi(page: Page): Promise<void> {
  const routes: Array<[RegExp, unknown]> = [
    [/\/api\/v1\/merchant-registration\/first-time-setup$/, { data: { options: { service_fee_tiers: [
      { value: 'customer_centric', label: 'Customer centric' },
      { value: 'split_tier', label: 'Shared' },
      { value: 'business_centric', label: 'Business centric' },
    ], subscription_plans: plans.map((plan) => ({
      id: plan.id,
      name: plan.name,
      description: plan.description,
      tier: plan.tier,
      prices: [plan.effective_price],
    })) } } }],
    [/\/api\/v1\/merchant\/dashboard$/, { data: { overview: {
      subscription: {
        status: 'active', billing_status: 'active', billing_read_only: false, plan_name: 'Starter', billing_interval: 'monthly',
        amount_minor: 500000, currency: 'KES', trial_ends_at: '2026-08-15T00:00:00Z', current_period_end: '2026-09-01', scheduled_change: false,
      },
      billing: {
        next_invoice: { id: INVOICE_ID, invoice_number: 'SUB-2026-000042', status: 'issued', balance_minor: 500000, currency: 'KES', due_at: '2026-08-15T00:00:00Z' },
        outstanding_by_currency: [{ currency: 'KES', amount_minor: 500000 }],
        payment_runtime: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness is closed.' },
      },
      branches: { total: 1, active: 1, suspended: 0, archived: 0, limit: 3, remaining_capacity: 2 },
      staff: { active: 3, invited: 1, suspended: 0, deactivated: 0, pending_owner_invitations: 1 },
      get_started: {
        setup_complete: true, subscription_selected: true, profile_complete: true, logo_uploaded: true,
        billing_phone_confirmed: true, first_branch_created: true, initial_team_invited: true, initial_team_active: true,
        operational_roles_active: true,
        daily_reports: { available: false, reason: 'External Gate W is closed.' },
      },
      compensation: {
        pending_high_value_approvals: 1,
        payout_runs_by_status: { pending_merchant_admin_approval: 1 },
        outstanding_liability_by_currency: [{ currency: 'KES', combined_net_liability_minor: 350000 }],
      },
      reporting: { available: false, reason: 'External Gate W is closed.', omitted_metrics: ['validated revenue', 'branch performance'] },
    } } }],
    [/\/api\/v1\/merchant\/profile$/, { data: {
      id: '01JQMERCHANT00000000000001', business_category: 'Salon', contact_email: 'hello@example.test', contact_phone: '+254700000000',
      receipt_display_name: 'Glow Studio', address: 'Ngong Road', town: 'Nairobi', timezone: 'Africa/Nairobi', country: 'KE',
      merchant: { id: '01JQMERCHANT00000000000001', name: 'Glow Studio', slug: 'glow-studio', status: 'active', service_fee_tier: 'customer_centric' },
      logo: null,
      logo_history: [{ id: '01JQLOGO000000000000000001', filename: 'glow-logo.png', available_at: '2026-08-05T00:00:00Z' }],
    } }],
    [/\/api\/v1\/branches\?/, { data: [branch] }],
    [/\/api\/v1\/branches$/, { data: [branch] }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}$`), { data: branch }],
    [/\/api\/v1\/staff-invitations$/, { data: [{ id: '01JQINVITE0000000000000001', email: 'manager@example.test', role: 'branch_manager', role_title: null, branch_id: BRANCH_ID, status: 'pending', resend_count: 0, expires_at: '2026-08-18T00:00:00Z', last_sent_at: '2026-08-11T00:00:00Z' }] }],
    [/\/api\/v1\/merchant\/staff-overview/, { data: [{
      id: '01JQMEMBERSHIP000000000002', staff_profile_id: '01JQSTAFF00000000000000001', display_name: 'Brian Manager', email: 'brian@example.test',
      role: 'branch_manager', status: 'active', account_status: 'active', activated_at: '2026-08-03T00:00:00Z', last_login_at: '2026-08-10T00:00:00Z',
      branches: [{ id: BRANCH_ID, name: 'Kilimani Studio', code: 'KLM' }], active_session_count: 1,
      assignment_history: [{ branch: 'Kilimani Studio', status: 'active', assigned_at: '2026-08-03T00:00:00Z', revoked_at: null }],
      status_history: [{ field: 'status', from: 'invited', to: 'active', changed_at: '2026-08-03T00:00:00Z' }],
      can: { manage_lifecycle: true },
    }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }],
    [/\/api\/v1\/subscription$/, { data: subscription }],
    [/\/api\/v1\/subscription\/plans$/, { data: plans }],
    [/\/api\/v1\/subscription\/scheduled-plan-change$/, { data: null }],
    [/\/api\/v1\/subscription-invoices\?/, { data: [invoice], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }],
    [/\/api\/v1\/subscription-invoices$/, { data: [invoice], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }],
    [new RegExp(`/api/v1/subscription-invoices/${INVOICE_ID}$`), { data: invoice }],
    [/\/api\/v1\/merchant\/compensation-summary$/, { data: {
      outstanding_liability_by_currency: [{ currency: 'KES', gross_salary_accrual_minor: 300000, salary_reversal_minor: 0, net_salary_liability_minor: 300000, gross_earned_commission_minor: 50000, commission_reversal_minor: 0, net_commission_liability_minor: 50000, compensation_adjustment_minor: 0, combined_net_liability_minor: 350000 }],
      paid_by_currency: [{ currency: 'KES', paid_gross_minor: 280000, run_count: 1 }],
      payout_runs_by_status: { pending_merchant_admin_approval: 1 }, pending_high_value_approvals: 1,
    } }],
    [/\/api\/v1\/merchant\/payout-runs/, { data: [{
      id: '01JQRUN0000000000000000001', branch_id: BRANCH_ID, period_start: '2026-07-01', period_end: '2026-07-31', currency: 'KES',
      status: 'pending_merchant_admin_approval', gross_total_minor: 900000, high_value_threshold_snapshot_minor: 800000, is_high_value: true,
      rejection_reason: null, has_external_payment_reference: false, paid_at: null, item_count: 3, items: [], created_at: '2026-08-01T00:00:00Z', updated_at: '2026-08-01T00:00:00Z',
    }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } }],
    [/\/api\/v1\/period-locks/, { data: [{
      id: '01JQLOCK000000000000000001', scope: 'merchant', branch: null, period_start: '2026-07-01', period_end: '2026-07-31', status: 'locked',
      exception_required: true, reopen_reason: 'Approved audit correction', reopen_requested_at: '2026-08-10T00:00:00Z', reopen_approved_at: null, reopened_at: null, created_at: '2026-08-01T00:00:00Z',
    }] }],
    [/\/api\/v1\/auth\/sessions$/, { data: [{
      id: '01JQSESSION0000000000000001', account_key: 'merchant_administrator', host: 'servana.ke', merchant_name: 'Glow Studio', branch_name: null,
      created_at: '2026-08-01T00:00:00Z', last_activity_at: '2026-08-11T00:00:00Z', revoked: false, is_current: true,
    }] }],
    [/\/api\/v1\/auth\/mfa$/, { data: { mfa: { required: true, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: '2099-01-01T00:00:00Z', recovery_codes_remaining: 8 } } }],
  ];

  // Axios prepends `/api/v1`; register one honest fallback first, then let specific fixtures
  // override it because Playwright resolves the most recently registered matching route first.
  await page.route(/\/api\/v1\//, (route) => {
    // `/me` is installed by `stubMerchant` and must reach that earlier handler. Letting this
    // collection fallback answer it with `{ data: [] }` makes the real auth guard correctly
    // treat the browser as signed out, which hides the product pages the harness is meant to
    // exercise.
    if (new URL(route.request().url()).pathname === '/api/v1/me') return route.fallback();
    return route.fulfill(json({ data: [] }));
  });
  for (const [pattern, body] of routes) {
    await page.route(pattern, (route) => route.fulfill(json(body)));
  }
}

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
    if (request.failure()?.errorText === 'net::ERR_ABORTED') return;
    health.failedRequests.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText ?? 'unknown'}`);
  });
  page.on('request', (request) => {
    if (request.url().includes('/api/v1/')) health.apiRequests.push(request.url());
  });
  return health;
}
