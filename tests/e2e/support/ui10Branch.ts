import type { Page } from '@playwright/test';
import { stubAccountContextFor } from './roleBootstrap';

export const BRANCH_ID = '01JQBRANCH0000000000001001';

export const BRANCH_PERMISSIONS = [
  'branch.profile.manage', 'branch.calendar.manage', 'branch.dashboard.view',
  'service.view', 'service.create', 'service.update', 'service.archive', 'day.open_close',
  'branch.cash_up.submit', 'receipt.view', 'platform_fee.view', 'reports.view',
  'preferred_personnel_fee.view_branch_rule',
];

export const IMPLEMENTED_PAGES = [
  { screen: 'dashboard', path: '/dashboard', h1: 'Good day, Brian Branch', api: `/branches/${BRANCH_ID}/dashboard` },
  { screen: 'get-started', path: '/get-started', h1: 'Get started', api: `/branches/${BRANCH_ID}/dashboard`, headingLevel: 2 },
  { screen: 'branch-profile', path: '/branch/profile', h1: 'Branch profile', api: `/branches/${BRANCH_ID}/dashboard` },
  { screen: 'branch-calendar', path: '/branch/calendar', h1: 'Branch calendar', api: `/branches/${BRANCH_ID}/calendar-exceptions` },
  { screen: 'branch-day', path: '/branch/day', h1: 'Branch day', api: `/branches/${BRANCH_ID}/dashboard` },
  { screen: 'services', path: '/services', h1: 'Services', api: '/services' },
  { screen: 'staff', path: '/staff', h1: 'Personnel schedule', api: '/branch/personnel-options' },
  { screen: 'operations-queue', path: '/operations/queue', h1: 'Branch queue', api: '/queue-entries' },
  { screen: 'operations-appointments', path: '/operations/appointments', h1: 'Appointments', api: '/appointments' },
  { screen: 'finance-invoices', path: '/finance/invoices', h1: 'Invoices', api: `/branches/${BRANCH_ID}/financial-visibility/invoices` },
  { screen: 'finance-payments', path: '/finance/payments', h1: 'Payment records', api: `/branches/${BRANCH_ID}/financial-visibility/payment-records` },
  { screen: 'finance-receipts', path: '/finance/receipts', h1: 'Receipts', api: '/receipts' },
  { screen: 'cash-up', path: '/cash-up', h1: 'Cash-up and reconciliation', api: `/branches/${BRANCH_ID}/cash-ups/` },
  { screen: 'audit', path: '/audit', h1: 'Branch audit', api: `/branches/${BRANCH_ID}/audit-events` },
  { screen: 'account', path: '/account', h1: 'Account and security', api: '/auth/sessions' },
] as const;

export const GATED_PAGES = [
  { screen: 'reports', path: '/reports', key: 'merchant_branch.reports' },
  { screen: 'subscription-payment', path: '/subscription/payment', key: 'merchant_branch.subscription-payment' },
  { screen: 'notifications', path: '/notifications', key: 'merchant_branch.notifications' },
] as const;

export const NAVIGATION_GROUPS = [
  'Home', 'Branch Setup', 'Branch Operations', 'Operational Visibility',
  'Financial Visibility', 'Reporting', 'Billing Notice', 'Utility',
] as const;

const branch = {
  id: BRANCH_ID, name: 'Westlands Studio', code: 'WST', address: 'Woodvale Grove', town: 'Nairobi',
  phone: '+254700000001', email: 'westlands@example.test', business_category: 'Salon',
  status: 'active', status_reason: null, archived_at: null,
};

const overview = {
  branch,
  business_date: '2026-08-12',
  day: { id: '01JQDAY000000000000000001', status: 'open', opened_at: '2026-08-12T05:00:00Z', closed_at: null, queue_is_open: true, close_blockers: ['active_queue_entries'], financial_close_blockers: ['cash_up_not_approved'] },
  services: { total: 8, active: 7, archived: 1 },
  staff: { active: 5 },
  queue: { total: 4, active: 3, by_status: { waiting: 3, completed: 1 } },
  appointments: { today: 6, active_today: 4, by_status: { confirmed: 4, cancelled: 2 } },
  financial: { invoices_total: 4, invoices_by_status: { issued: 2, paid: 2 }, invoices_with_balance: 2, pending_payment_validations: 1, receipts_issued_today: 2, validated_revenue_by_currency: [{ currency: 'KES', amount_minor: 125000 }] },
  cash_up: null,
  billing: { status: 'active', next_invoice: null, payment_runtime: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness' } },
  reporting: { available: false, reason: 'Phase 21N reporting runtime is not implemented' },
  notifications: { available: false, reason: 'External Gate W — Wallet by Citrus collections readiness' },
  get_started: { profile_complete: true, calendar_configured: true, service_catalogue_ready: true, staff_ready: true, day_opened: true, cash_up_prepared: false, reports: { available: false, reason: 'Phase 21N reporting runtime is not implemented' } },
};

const pageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const json = (body: unknown, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });

export interface BranchBootstrapOptions {
  accountKey?: string;
  accountKeys?: string[];
  branchIds?: string[];
  permissions?: string[];
}

export async function stubBranch(page: Page, options: BranchBootstrapOptions = {}): Promise<void> {
  const accountKey = options.accountKey ?? 'merchant_branch';
  await stubAccountContextFor(page, accountKey, 'Glow Studio');
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) => route.fulfill(json({ data: {
    user: { id: '01JQUSER000000000000001001', email: 'branch@example.test', name: 'Brian Branch', status: 'active', email_verified_at: '2026-08-01T00:00:00Z', is_platform_staff: false, theme_preference: null, resolved_theme: 'light' },
    merchant: { id: '01JQMERCHANT00000000001001', name: 'Glow Studio', slug: 'glow-studio', status: 'active', service_fee_tier: 'customer_centric', setup_completed_at: '2026-08-02T00:00:00Z' },
    membership: { id: '01JQMEMBERSHIP000000001001', role: 'branch_manager', status: 'active' },
    memberships: [{ id: '01JQMEMBERSHIP000000001001', role: 'branch_manager', status: 'active' }],
    permissions: options.permissions ?? BRANCH_PERMISSIONS,
    account_keys: options.accountKeys ?? ['merchant_branch'],
    setup: { required: false, current_step: null, completed_at: '2026-08-02T00:00:00Z' },
    branch_ids: options.branchIds ?? [BRANCH_ID],
    mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
  } })));
}

export async function stubBranchApi(page: Page): Promise<void> {
  const cashUp = { data: { id: null, business_date: '2026-08-12', status: 'draft', expected: { amount: 0, currency: 'KES', formatted: 'KES 0.00' }, counted: { amount: 0, currency: 'KES', formatted: 'KES 0.00' }, variance: { amount: 0, currency: 'KES', formatted: 'KES 0.00' }, expected_minor: 0, counted_minor: 0, variance_minor: 0, lines: [] } };
  const routes: Array<[RegExp, unknown]> = [
    [/\/api\/v1\/branches(?:\?.*)?$/, { data: [branch] }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/dashboard$`), { data: { overview } }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}$`), { data: branch }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/calendar-exceptions`), { data: [], meta: { from: '2026-08-12', to: '2026-11-12' } }],
    [/\/api\/v1\/service-categories$/, { data: [] }],
    [/\/api\/v1\/services(?:\?.*)?$/, { data: [] }],
    [/\/api\/v1\/branch\/preferred-personnel-fee-rule$/, { data: null }],
    [/\/api\/v1\/branch\/personnel-options$/, { data: [] }],
    [/\/api\/v1\/queue-entries(?:\?.*)?$/, { data: [] }],
    [/\/api\/v1\/appointments(?:\?.*)?$/, { data: [] }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/financial-visibility/invoices`), { data: [{ id: '01JQINV000000000000000001', invoice_number: 'INV-001', status: 'issued', total_minor: 250000, validated_paid_minor: 100000, balance_minor: 150000, currency: 'KES', finalized_at: '2026-08-12T05:00:00Z', created_at: '2026-08-12T05:00:00Z', can: { create: false, update: false, finalize: false, void: false, adjust: false } }], meta: pageMeta }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/financial-visibility/payment-records`), { data: [{ id: '01JQPAY000000000000000001', invoice: { id: '01JQINV000000000000000001', invoice_number: 'INV-001' }, status: 'pending_validation', total_amount_minor: 100000, currency: 'KES', recorded_at: '2026-08-12T05:00:00Z', submitted_for_validation_at: '2026-08-12T05:05:00Z', validated_at: null, created_at: '2026-08-12T05:00:00Z', can: { record: false, validate: false, reject: false, correct_reference: false } }], meta: pageMeta }],
    [/\/api\/v1\/receipts(?:\?.*)?$/, { data: [{ id: '01JQREC000000000000000001', receipt_number: 42, amount: { amount: 1000, currency: 'KES', formatted: 'KES 1,000.00' }, currency: 'KES', is_reissue: false, downloadable: true, file_generation_status: 'ready', created_at: '2026-08-12T05:10:00Z', invoice: { id: '01JQINV000000000000000001', invoice_number: 'INV-001' } }] }],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/cash-ups/`), cashUp],
    [new RegExp(`/api/v1/branches/${BRANCH_ID}/audit-events`), { data: [{ id: '01JQAUDIT0000000000000001', action: 'branch.profile_updated', severity: 'info', actor: 'b••@example.test', subject_type: 'MerchantBranch', context: {}, created_at: '2026-08-12T05:10:00Z' }], meta: pageMeta }],
    [/\/api\/v1\/auth\/sessions$/, { data: [{ id: '01JQSESSION0000000000001001', account_key: 'merchant_branch', host: 'branch.servana.ke', merchant_name: 'Glow Studio', branch_name: 'Westlands Studio', created_at: '2026-08-01T00:00:00Z', last_activity_at: '2026-08-12T05:00:00Z', revoked: false, is_current: true }] }],
    [/\/api\/v1\/auth\/mfa$/, { data: { mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 } } }],
  ];

  await page.route(/\/api\/v1\//, (route) => {
    if (new URL(route.request().url()).pathname === '/api/v1/me') return route.fallback();
    return route.fulfill(json({ data: [] }));
  });
  for (const [pattern, body] of routes) await page.route(pattern, (route) => route.fulfill(json(body)));
}
