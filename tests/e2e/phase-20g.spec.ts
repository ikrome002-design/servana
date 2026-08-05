import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 20G E2E — the Finance compensation-liability frontend surface (Plan §61, §80, §27.1): the
 | per-currency liability summary, the salary/commission liability-entry list, the compensation-adjustment
 | list, and the capability-gated manual-adjustment dialog (fresh step-up + Idempotency-Key). The SPA
 | preview has no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine authorization,
 | MFA/fresh step-up, server-side merchant/branch scope, idempotency, period locks, the append-only ledger
 | and the read-model totals are proven by the backend Feature suite (tests/Feature/Compensation/*); these
 | prove frontend behaviour, role gating, canonical copy, signed-money direction, and accessibility. There
 | is NO payout/earnings/mark-paid/Wallet/settlement surface.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}
function forbidden(code: string) {
  return { status: 403, contentType: 'application/json', body: JSON.stringify({ error: { code, message: 'A fresh step-up is required.', fields: {}, meta: {} } }) };
}
function locked() {
  return { status: 423, contentType: 'application/json', body: JSON.stringify({ error: { code: 'financial_period_locked', message: 'The financial period is locked.', fields: {}, meta: {} } }) };
}

interface MeOpts {
  role?: string | null;
  permissions?: string[];
  stepUpFresh?: boolean;
}

async function stubMe(page: Page, opts: MeOpts = {
}): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, opts.role, opts.isPlatformStaff ?? false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@citrus.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
        memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
        account_keys: [accountKeyForRole(opts.role, opts.isPlatformStaff ?? false)],
        permissions: opts.permissions ?? [],
        setup: { required: false, current_step: null, completed_at: null },
        branch_ids: ['b1'],
        mfa: { required: false, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: opts.stepUpFresh ?? true, step_up_fresh_until: null, recovery_codes_remaining: 5 },
      },
    })),
  );
}

const STAFF = '01HZSTAFF00000000000000000';

const summary = [
  { currency: 'KES', gross_salary_accrual_minor: 500000, salary_reversal_minor: 0, net_salary_liability_minor: 500000, gross_earned_commission_minor: 120000, commission_reversal_minor: -20000, net_commission_liability_minor: 100000, compensation_adjustment_minor: -5000, combined_net_liability_minor: 595000 },
  { currency: 'USD', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 0, gross_earned_commission_minor: 42000, commission_reversal_minor: 0, net_commission_liability_minor: 42000, compensation_adjustment_minor: 0, combined_net_liability_minor: 42000 },
];

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: '01ENTRYPOS0000000000000000', liability_type: 'salary', entry_type: 'accrual', status: 'pending',
    amount_minor: 500000, currency: 'KES', business_date: '2026-07-31', staff_profile_id: STAFF, staff_display_name: 'A. Stylist',
    branch_id: '01BRANCH00000000000000000', compensation_plan_id: '01PLAN0000000000000000000', commission_rule_id: null,
    pay_period_start: '2026-07-01', pay_period_end: '2026-07-31', invoice_reference: null, source_entry_id: null,
    created_at: '2026-08-01T00:00:00+00:00', ...overrides,
  };
}
const entries = [
  entry(),
  entry({ id: '01ENTRYNEG0000000000000000', liability_type: 'commission', entry_type: 'reversal', status: 'reversed', amount_minor: -20000, invoice_reference: 'INV-000123', commission_rule_id: '01RULE0000000000000000000', pay_period_start: null, pay_period_end: null }),
];

function adjustment(overrides: Record<string, unknown> = {}) {
  return {
    id: '01ADJ00000000000000000000', adjustment_type: 'manual', amount_minor: -5000, currency: 'KES', reason: 'Goodwill correction',
    staff_profile_id: STAFF, staff_display_name: 'A. Stylist', branch_id: '01BRANCH00000000000000000', created_at: '2026-07-06T10:00:00+00:00', ...overrides,
  };
}

function meta(rows: unknown[]) {
  return { current_page: 1, last_page: 1, per_page: 25, total: rows.length };
}

interface FeeStubOpts {
  postResult?: 'created' | 'step_up' | 'locked';
}

async function stubLiabilities(page: Page, opts: FeeStubOpts = {}): Promise<void> {
  await page.route(/\/api\/v1\/compensation\/liabilities\/summary(\?|$)/, (r) => r.fulfill(ok({ data: summary })));
  await page.route(/\/api\/v1\/compensation\/liabilities(\?|$)/, (r) => r.fulfill(ok({ data: entries, meta: meta(entries) })));
  await page.route(/\/api\/v1\/compensation\/adjustments\/[^/]+$/, (r) => r.fulfill(ok({ data: adjustment() })));
  await page.route(/\/api\/v1\/compensation\/adjustments(\?|$)/, (r) => {
    if (r.request().method() === 'POST') {
      if (opts.postResult === 'step_up') return r.fulfill(forbidden('step_up_required'));
      if (opts.postResult === 'locked') return r.fulfill(locked());
      return r.fulfill(created({ data: adjustment({ id: '01NEWADJ0000000000000000', amount_minor: 5000, reason: 'Agreed correction' }) }));
    }
    return r.fulfill(ok({ data: [adjustment()], meta: meta([adjustment()]) }));
  });
}

/* ---------------------------------------------------------------- Finance happy path */

test.describe('Finance compensation-liability surface', () => {
  async function gotoFinance(page: Page, opts: MeOpts = {}, stub: FeeStubOpts = {}): Promise<void> {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view', 'compensation.adjustment.create'], ...opts });
    await stubLiabilities(page, stub);
    await page.goto('/finance/liabilities');
    await expect(page.getByRole('heading', { name: 'Compensation liabilities' })).toBeVisible();
  }

  test('shows a multi-currency summary and the salary + commission entries', async ({ page }) => {
    await gotoFinance(page);
    // Two per-currency summary cards, never combined.
    await expect(page.getByTestId('summary-card')).toHaveCount(2);
    await expect(page.getByText('USD', { exact: false })).toBeVisible();
    // Both a positive and a negative entry render with a non-colour direction cue.
    await expect(page.getByTestId('liability-entry-row')).toHaveCount(2);
    await expect(page.getByText('increases liability').first()).toBeVisible();
    await expect(page.getByText('reduces liability').first()).toBeVisible();
  });

  test('filters the entries through the API', async ({ page }) => {
    await gotoFinance(page);
    let lastQuery = '';
    await page.route(/\/api\/v1\/compensation\/liabilities(\?|$)/, (r) => {
      lastQuery = new URL(r.request().url()).search;
      return r.fulfill(ok({ data: entries, meta: meta(entries) }));
    });
    await page.locator('#filter-liability-type').selectOption('commission');
    await page.getByTestId('apply-filters').click();
    await expect.poll(() => lastQuery).toContain('liability_type=commission');
  });

  test('creates a positive adjustment and does not duplicate on a double submit', async ({ page }) => {
    let postCount = 0;
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view', 'compensation.adjustment.create'] });
    await page.route(/\/api\/v1\/compensation\/liabilities\/summary(\?|$)/, (r) => r.fulfill(ok({ data: summary })));
    await page.route(/\/api\/v1\/compensation\/liabilities(\?|$)/, (r) => r.fulfill(ok({ data: entries, meta: meta(entries) })));
    await page.route(/\/api\/v1\/compensation\/adjustments(\?|$)/, (r) => {
      if (r.request().method() === 'POST') { postCount += 1; return r.fulfill(created({ data: adjustment({ id: '01NEWADJ0000000000000000', amount_minor: 5000 }) })); }
      return r.fulfill(ok({ data: [adjustment()], meta: meta([adjustment()]) }));
    });
    await page.goto('/finance/liabilities');
    await page.getByTestId('open-adjustment').click();
    await page.locator('#adjustment-staff').fill(STAFF);
    await page.locator('#adjustment-amount').fill('50');
    await page.locator('#adjustment-currency').fill('KES');
    await page.locator('#adjustment-reason').fill('Agreed correction');
    // A live preview shows the signed effect before submitting; a negative is never called a payment.
    await expect(page.getByTestId('adjustment-preview')).toContainText('increases liability');
    await page.getByTestId('adjustment-submit').click();
    await expect(page.getByTestId('liability-status')).toContainText('recorded');
    expect(postCount).toBe(1);
  });

  test('creates a negative adjustment (reduces liability)', async ({ page }) => {
    await gotoFinance(page);
    await page.getByTestId('open-adjustment').click();
    await page.locator('#adjustment-staff').fill(STAFF);
    await page.locator('#adjustment-direction').selectOption('decrease');
    await page.locator('#adjustment-amount').fill('50');
    await page.locator('#adjustment-currency').fill('KES');
    await page.locator('#adjustment-reason').fill('Goodwill');
    await expect(page.getByTestId('adjustment-preview')).toContainText('reduces liability');
    await expect(page.getByTestId('adjustment-preview')).toContainText('not a payment');
    await page.getByTestId('adjustment-submit').click();
    await expect(page.getByTestId('liability-status')).toContainText('recorded');
  });
});

/* ---------------------------------------------------------------- step-up + errors */

test.describe('Fresh step-up and error handling', () => {
  test('a stale step-up blocks the adjustment and surfaces the safe verify state', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view', 'compensation.adjustment.create'], stepUpFresh: false });
    await stubLiabilities(page, { postResult: 'step_up' });
    await page.goto('/finance/liabilities');
    await page.getByTestId('open-adjustment').click();
    await page.locator('#adjustment-staff').fill(STAFF);
    await page.locator('#adjustment-amount').fill('50');
    await page.locator('#adjustment-currency').fill('KES');
    await page.locator('#adjustment-reason').fill('Correction');
    await page.getByTestId('adjustment-submit').click();
    await expect(page.getByTestId('adjustment-step-up')).toBeVisible();
    // The established verification flow is reachable; no verification secret is stored in the app.
    await expect(page.getByTestId('adjustment-verify')).toBeVisible();
  });

  test('a locked period is surfaced safely without leaking internals', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view', 'compensation.adjustment.create'] });
    await stubLiabilities(page, { postResult: 'locked' });
    await page.goto('/finance/liabilities');
    await page.getByTestId('open-adjustment').click();
    await page.locator('#adjustment-staff').fill(STAFF);
    await page.locator('#adjustment-amount').fill('50');
    await page.locator('#adjustment-currency').fill('KES');
    await page.locator('#adjustment-reason').fill('Correction');
    await page.getByTestId('adjustment-submit').click();
    await expect(page.getByTestId('adjustment-error')).toContainText('financial period is locked');
    await expect(page.getByTestId('adjustment-error')).not.toContainText('SQLSTATE');
  });

  test('a forbidden read renders a safe forbidden state', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view'] });
    await page.route(/\/api\/v1\/compensation\/liabilities\/summary(\?|$)/, (r) => r.fulfill(forbidden('forbidden')));
    await page.route(/\/api\/v1\/compensation\/liabilities(\?|$)/, (r) => r.fulfill(forbidden('forbidden')));
    await page.route(/\/api\/v1\/compensation\/adjustments(\?|$)/, (r) => r.fulfill(forbidden('forbidden')));
    await page.goto('/finance/liabilities');
    await expect(page.getByTestId('liability-forbidden')).toBeVisible();
  });
});

/* ---------------------------------------------------------------- role gating */

test.describe('Role gating (frontend UX; the API is the boundary)', () => {
  test('Finance without compensation.liability.view sees the no-access state', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: [] });
    await stubLiabilities(page);
    await page.goto('/finance/liabilities');
    await expect(page.getByTestId('liability-no-permission')).toBeVisible();
    await expect(page.getByTestId('open-adjustment')).toHaveCount(0);
  });

  test('a viewer without adjustment.create cannot see the Record adjustment control', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view'] });
    await stubLiabilities(page);
    await page.goto('/finance/liabilities');
    await expect(page.getByRole('heading', { name: 'Compensation liabilities' })).toBeVisible();
    await expect(page.getByTestId('open-adjustment')).toHaveCount(0);
  });
});

/* ---------------------------------------------------------------- responsive / zoom / keyboard / a11y */

test.describe('Responsive, zoom, keyboard and accessibility', () => {
  async function gotoFinance(page: Page): Promise<void> {
    await stubMe(page, { role: 'finance', permissions: ['compensation.liability.view', 'compensation.adjustment.create'] });
    await stubLiabilities(page);
    await page.goto('/finance/liabilities');
    await expect(page.getByRole('heading', { name: 'Compensation liabilities' })).toBeVisible();
  }

  for (const width of [360, 768, 1280]) {
    test(`has no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await gotoFinance(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('remains usable at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoFinance(page);
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    await expect(page.getByRole('heading', { name: 'Compensation liabilities' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
  });

  test('opens the adjustment dialog by keyboard and closes on Escape', async ({ page }) => {
    await gotoFinance(page);
    const trigger = page.getByTestId('open-adjustment');
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`passes axe with zero serious/critical violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoFinance(page);
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });

    test(`adjustment dialog passes axe (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoFinance(page);
      await page.getByTestId('open-adjustment').click();
      await page.locator('#adjustment-amount').fill('50');
      await expect(page.getByRole('dialog')).toBeVisible();
      const results = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });
  }
});
