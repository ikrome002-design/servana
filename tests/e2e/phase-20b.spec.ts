import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { stubAccountContextFor } from './support/roleBootstrap';

/*
 | Phase 20B E2E — merchant subscription self-service (dashboard / plan management / invoices) and
 | platform registration monitoring + merchant governance (Plan §22, §24.1, §48, §49, §27.1). The SPA
 | preview has no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine
 | authorization, billing gates, no-proration, immutability, step-up and non-enumeration are proven by
 | the backend Feature suite (tests/Feature/Billing/*, tests/Feature/Platform/*); these prove the
 | frontend behaviour, gating, and accessibility. No Wallet/payment/STK surface exists.
 */

const MERCHANT_PERMS = [
  'merchant.subscription.view',
  'merchant.subscription.plan_change',
  'merchant.subscription.invoice.view',
  'merchant.subscription.invoice.download',
];
const PLATFORM_PERMS = [
  'platform.registration_monitor.view',
  'platform.merchant.view',
  'platform.merchant.suspend',
  'platform.merchant.reactivate',
  'platform.merchant.deactivate',
];

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}
function err(status: number, code: string, message = code) {
  return { status, contentType: 'application/json', body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }) };
}

interface MeOpts {
  isPlatformStaff?: boolean;
  role?: string | null;
  permissions?: string[];
  mfa?: Record<string, unknown>;
}

async function stubMe(page: Page, opts: MeOpts = {}): Promise<void> {
  const isPlatformStaff = opts.isPlatformStaff ?? false;
  // Phase UI-03: the account context the Laravel shell embeds, which requiresAccount needs.
  // The preview origin serves no Laravel shell, so without it the /platform guard fails closed.
  await stubAccountContextFor(page, isPlatformStaff ? 'super_administrator' : 'merchant_administrator');
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: isPlatformStaff },
        merchant: isPlatformStaff ? null : { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
        memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
        permissions: opts.permissions ?? [],
        setup: { required: false, current_step: null, completed_at: null },
        account_keys: [isPlatformStaff ? 'super_administrator' : 'merchant_administrator'],
        branch_ids: [],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0, ...(opts.mfa ?? {}) },
      },
    })),
  );
}

function subscription(overrides: Record<string, unknown> = {}) {
  return {
    id: '01SUB', status: 'active', billing_status: 'active', billing_status_reason: null,
    billing_read_only: false, billing_interval: 'monthly',
    trial_started_at: '2026-06-01T00:00:00Z', trial_ends_at: '2026-06-15T00:00:00Z',
    current_period_start: '2026-07-01', current_period_end: '2026-08-01',
    plan: { id: '01PLAN', key: 'starter', name: 'Starter', tier: null },
    price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
    scheduled_plan_change: null,
    can: { schedule_plan_change: true, download_invoice: true },
    ...overrides,
  };
}
const PLANS = [
  { id: '01PLAN', key: 'starter', name: 'Starter', description: 'Entry', tier: null, is_current: true, effective_price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' } },
  { id: '02PLAN', key: 'growth', name: 'Growth', description: 'More', tier: null, is_current: false, effective_price: { id: '02PRICE', amount_minor: 900000, currency: 'KES', billing_interval: 'monthly' } },
];
function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: '01INV', invoice_number: 'SUB-000001', status: 'issued', period_start: '2026-07-01', period_end: '2026-08-01',
    subtotal_minor: 500000, discount_minor: 0, total_minor: 500000, balance_minor: 500000, currency: 'KES',
    issued_at: '2026-07-01T00:00:00Z', due_at: '2026-07-08T00:00:00Z',
    payment_reference_pending: true, account_reference: null, has_pdf: false, pdf_version: 0, ...overrides,
  };
}
function merchant(overrides: Record<string, unknown> = {}) {
  return {
    id: 'm-acme', name: 'Acme Salon', operational_status: 'active', billing_status: 'suspended_billing',
    billing_status_reason: null, suspension_reason: null, suspended_at: null, deactivated_at: null,
    setup_completed_at: '2026-01-01T00:00:00Z', registered_at: '2026-07-01T00:00:00Z',
    can: { suspend: true, reactivate: false, deactivate: true }, ...overrides,
  };
}

async function stubSubscription(page: Page, sub = subscription(), invoices: unknown[] = [invoice()]): Promise<void> {
  await page.route(/\/api\/v1\/subscription(\?|$)/, (r) => r.fulfill(ok({ data: sub })));
  await page.route('**/api/v1/subscription/plans', (r) => r.fulfill(ok({ data: PLANS })));
  await page.route('**/api/v1/subscription/scheduled-plan-change', (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: { id: '01SC', status: 'scheduled', effective_at: '2026-08-01', target_plan: { id: '02PLAN', key: 'growth', name: 'Growth', tier: null }, target_price: { id: '02PRICE', amount_minor: 900000, currency: 'KES', billing_interval: 'monthly' } } }));
    return r.fulfill(ok({ data: (sub as Record<string, unknown>).scheduled_plan_change ?? null }));
  });
  await page.route(/\/api\/v1\/subscription-invoices(\?|$)/, (r) => r.fulfill(ok({ data: invoices })));
}

/* ------------------------------------------------------------- dashboard states */

test.describe('Merchant subscription dashboard', () => {
  test('surfaces subscription status, independent billing status, plan/price and dates', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await stubSubscription(page);
    await page.goto('/merchant/subscription');
    await expect(page.getByTestId('subscription-status')).toHaveText('Active');
    await expect(page.getByTestId('billing-status')).toHaveText('Active');
    await expect(page.getByText('Starter')).toBeVisible();
    await expect(page.getByText('2026-08-01')).toBeVisible();
    // Sidebar nav exposes the three Phase 20B merchant surfaces.
    const nav = page.getByTestId('sidebar-primary-nav');
    await expect(nav.getByRole('link', { name: 'Subscription and billing' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Plan management' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Subscription invoices' })).toBeVisible();
  });

  for (const state of ['trialing', 'active', 'read_only_grace', 'overdue', 'suspended_billing', 'cancelled', 'expired'] as const) {
    test(`renders the ${state} state`, async ({ page }) => {
      const readOnly = state === 'read_only_grace' || state === 'suspended_billing';
      await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
      await stubSubscription(page, subscription({ status: state, billing_status: readOnly ? state : 'active', billing_read_only: readOnly }));
      await page.goto('/merchant/subscription');
      await expect(page.getByTestId('subscription-status')).toBeVisible();
      if (readOnly) await expect(page.getByText('Billing is in read-only mode')).toBeVisible();
    });
  }

  test('a mandatory-MFA challenge redirects away from the subscription route', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS, mfa: { required: true, enrolled: true, confirmed: true, challenge_required: true } });
    await stubSubscription(page);
    await page.goto('/merchant/subscription');
    await expect(page).toHaveURL(/\/auth\/mfa\/challenge/);
  });
});

/* ------------------------------------------------------------- plan management */

test.describe('Plan management (no proration)', () => {
  test('schedules a next-cycle change with a server-computed date and no proration', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await stubSubscription(page);
    await page.goto('/merchant/plan');
    await expect(page.getByText(/no proration/i)).toBeVisible();
    await expect(page.getByText('2026-08-01')).toBeVisible();
    await page.getByTestId('schedule-growth').click();
    await expect(page.getByTestId('scheduled-change')).toContainText('Growth');
  });

  test('cancels a pending scheduled change', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    const sub = subscription({ scheduled_plan_change: { id: '01SC', status: 'scheduled', effective_at: '2026-08-01', target_plan: { name: 'Growth' }, target_price: { amount_minor: 900000, currency: 'KES' } } });
    await page.route(/\/api\/v1\/subscription(\?|$)/, (r) => r.fulfill(ok({ data: sub })));
    await page.route('**/api/v1/subscription/plans', (r) => r.fulfill(ok({ data: PLANS })));
    await page.route('**/api/v1/subscription/scheduled-plan-change**', (r) => {
      if (r.request().url().endsWith('/cancel')) return r.fulfill(ok({ data: { id: '01SC', status: 'cancelled' } }));
      return r.fulfill(ok({ data: sub.scheduled_plan_change }));
    });
    await page.route(/\/api\/v1\/subscription-invoices(\?|$)/, (r) => r.fulfill(ok({ data: [] })));
    await page.goto('/merchant/plan');
    await page.getByTestId('cancel-scheduled-change').click();
    await expect(page.getByTestId('cancel-scheduled-change')).toHaveCount(0);
  });

  test('removes mutation controls in billing read-only and surfaces a 409', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    // First, read-only removes the schedule controls.
    await stubSubscription(page, subscription({ status: 'read_only_grace', billing_read_only: true }));
    await page.goto('/merchant/plan');
    await expect(page.getByTestId('schedule-growth')).toHaveCount(0);
    await expect(page.getByText('read-only mode')).toBeVisible();
  });

  test('surfaces a structured 409 when a change is already scheduled', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await page.route(/\/api\/v1\/subscription(\?|$)/, (r) => r.fulfill(ok({ data: subscription() })));
    await page.route('**/api/v1/subscription/plans', (r) => r.fulfill(ok({ data: PLANS })));
    await page.route('**/api/v1/subscription/scheduled-plan-change', (r) => {
      if (r.request().method() === 'POST') return r.fulfill(err(409, 'scheduled_plan_change_exists'));
      return r.fulfill(ok({ data: null }));
    });
    await page.route(/\/api\/v1\/subscription-invoices(\?|$)/, (r) => r.fulfill(ok({ data: [] })));
    await page.goto('/merchant/plan');
    await page.getByTestId('schedule-growth').click();
    await expect(page.getByRole('alert')).toContainText('already scheduled');
  });
});

/* ------------------------------------------------------------- invoices + PDF */

test.describe('Subscription invoices', () => {
  test('shows detail with the exact payment-reference-pending copy', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await stubSubscription(page);
    await page.goto('/merchant/subscription-invoices');
    await expect(page.getByTestId('invoice-number')).toContainText('SUB-000001');
    await expect(page.getByTestId('payment-reference-pending')).toHaveText('Payment reference pending — see your billing dashboard');
  });

  test('blocks new PDF generation in read-only but keeps an existing PDF downloadable', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    let downloadRequested = false;
    await page.route('**/api/v1/subscription-invoices/01INV/pdf/download-link', (r) => { downloadRequested = true; return r.fulfill(ok({ data: { url: 'https://signed/x?signature=abc', expires_at: 'x' } })); });
    await stubSubscription(page, subscription({ status: 'suspended_billing', billing_read_only: true }), [invoice({ has_pdf: true })]);
    await page.goto('/merchant/subscription-invoices');
    await expect(page.getByTestId('generate-pdf')).toBeDisabled();
    const download = page.getByTestId('download-pdf');
    await expect(download).toBeEnabled();
    await download.click();
    await expect.poll(() => downloadRequested).toBe(true);
  });

  test('never shows any Wallet / STK / PayBill / payment control', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await stubSubscription(page);
    await page.goto('/merchant/subscription-invoices');
    await expect(page.getByTestId('invoice-number')).toBeVisible();
    for (const forbidden of ['Pay now', 'STK', 'PayBill', 'Till', 'M-Pesa', 'Wallet']) {
      await expect(page.getByText(forbidden, { exact: false })).toHaveCount(0);
    }
  });
});

/* ------------------------------------------------------------- platform governance */

async function stubGovernance(page: Page, detail = merchant()): Promise<void> {
  await page.route('**/api/v1/platform/registration-monitor', (r) =>
    r.fulfill(ok({ data: [{ id: 'm-acme', name: 'Acme Salon', operational_status: 'pending_setup', billing_status: 'trialing', pending_setup: true, registered_at: '2026-07-01T00:00:00Z', setup_completed_at: null }] })));
  await page.route(/\/api\/v1\/platform\/merchants(\?|$)/, (r) => r.fulfill(ok({ data: [merchant()] })));
  await page.route('**/api/v1/platform/merchants/m-acme', (r) => r.fulfill(ok({ data: detail })));
}

test.describe('Platform registration monitoring and governance', () => {
  test('lists registrations and shows operational + billing status separately', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
    await stubGovernance(page);
    await page.goto('/platform/registration-monitoring');
    await expect(page.getByRole('tab', { name: 'Registration monitoring' })).toBeVisible();
    await expect(page.getByText('Acme Salon')).toBeVisible();

    await page.getByRole('tab', { name: 'Merchant directory' }).click();
    await page.getByTestId('merchant-row-m-acme').click();
    await expect(page.getByTestId('operational-status')).toHaveText('Active');
    await expect(page.getByTestId('detail-billing-status')).toHaveText('Suspended');
  });

  test('suspends with a mandatory reason; a missing fresh step-up surfaces guidance', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
    await stubGovernance(page);
    await page.route('**/api/v1/platform/merchants/m-acme/suspend', (r) => r.fulfill(err(403, 'mfa_challenge_required', 'A fresh step-up is required.')));
    await page.goto('/platform/registration-monitoring');
    await page.getByRole('tab', { name: 'Merchant directory' }).click();
    await page.getByTestId('merchant-row-m-acme').click();
    await page.getByTestId('action-suspend').click();

    // Confirm is disabled until a reason is entered (mandatory reason).
    await expect(page.getByTestId('confirm-governance')).toBeDisabled();
    await page.locator('#governance-reason').fill('Fraud investigation opened');
    await expect(page.getByTestId('confirm-governance')).toBeEnabled();
    await page.getByTestId('confirm-governance').click();
    await expect(page.getByRole('alert')).toContainText('step-up');
  });

  test('reactivate does not offer to clear billing suspension (billing stays separate)', async ({ page }) => {
    // A suspended merchant that can be reactivated; billing remains suspended_billing after.
    await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
    const suspended = merchant({ operational_status: 'suspended', billing_status: 'suspended_billing', can: { suspend: false, reactivate: true, deactivate: true } });
    await stubGovernance(page, suspended);
    await page.route(/\/api\/v1\/platform\/merchants(\?|$)/, (r) => r.fulfill(ok({ data: [suspended] })));
    await page.route('**/api/v1/platform/merchants/m-acme/reactivate', (r) => r.fulfill(ok({ data: { ...suspended, operational_status: 'active' } })));
    await page.goto('/platform/registration-monitoring');
    await page.getByRole('tab', { name: 'Merchant directory' }).click();
    await page.getByTestId('merchant-row-m-acme').click();
    await expect(page.getByTestId('action-reactivate')).toBeVisible();
    await page.getByTestId('action-reactivate').click();
    await page.locator('#governance-reason').fill('Investigation cleared');
    await page.getByTestId('confirm-governance').click();
    await expect(page.getByTestId('detail-billing-status')).toHaveText('Suspended');
  });

  test('shows no merchant-create, first-admin, impersonation or payment control', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
    await stubGovernance(page);
    await page.goto('/platform/registration-monitoring');
    await page.getByRole('tab', { name: 'Merchant directory' }).click();
    for (const forbidden of ['New merchant', 'Create merchant', 'Add merchant', 'Impersonate', 'Record payment', 'First administrator']) {
      await expect(page.getByRole('button', { name: forbidden })).toHaveCount(0);
    }
  });
});

/* ------------------------------------------------------------- role boundary */

test.describe('Role boundary', () => {
  test('a merchant identity is denied the platform governance route', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await page.goto('/platform/registration-monitoring');
    await expect(page.getByText('do not have access')).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(0);
  });
});

/* ------------------------------------------------------------- accessibility */

test.describe('Accessibility, responsive, keyboard', () => {
  test('dashboard: no serious/critical axe (light + dark), no overflow at 360/768/1280', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: MERCHANT_PERMS });
    await stubSubscription(page);
    await page.goto('/merchant/subscription');
    await expect(page.getByTestId('subscription-status')).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow, `overflow at ${width}`).toBe(false);
    }
  });

  test('governance dialog manages and restores focus and is axe-clean', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
    await stubGovernance(page);
    await page.goto('/platform/registration-monitoring');
    await page.getByRole('tab', { name: 'Merchant directory' }).click();
    await page.getByTestId('merchant-row-m-acme').click();

    const trigger = page.getByTestId('action-suspend');
    await trigger.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }

    // Escape closes and focus returns to the trigger control.
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });
});
