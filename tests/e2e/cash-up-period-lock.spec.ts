import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 18B E2E — Branch Manager CASH-UP submit, Finance review (approve, no
 | self-approve is backend-enforced), PERIOD LOCK create + 423 on a locked mutation +
 | PL-n/a operations still usable, and Merchant Administrator exceptional-reopen approval
 | only (Plan §45–§46, §80). SPA preview has no backend; /me + /api/v1 are stubbed to
 | drive the REAL frontend. Genuine server-derived totals, maker/checker, 423 enforcement
 | and reopen governance are proven by tests/Feature/Finance/*. Linux CI is authoritative.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function money(minor: number) {
  return { amount: minor, currency: 'KES', formatted: `KES ${(minor / 100).toFixed(2)}` };
}
async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: { id: 'mm1', role, status: 'active' }, memberships: [{ id: 'mm1', role, status: 'active' }],
        permissions, setup: { required: false, current_step: null, completed_at: null }, branch_ids: ['b1'],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
      },
    })),
  );
}
function cashUp(overrides: Record<string, unknown> = {}) {
  return {
    id: 'cu1', business_date: '2026-07-03', status: 'draft',
    expected: money(300000), counted: money(0), variance: money(-300000),
    expected_minor: 300000, counted_minor: 0, variance_minor: -300000, review_note: null,
    lines: [{ method: 'cash', expected_minor: 300000, counted_minor: 0, variance_minor: -300000 }],
    ...overrides,
  };
}
function lock(overrides: Record<string, unknown> = {}) {
  return {
    id: 'l1', scope: 'merchant', branch: null, period_start: '2026-06-01', period_end: '2026-06-30',
    status: 'locked', exception_required: true, reopen_reason: 'Correcting a posting error.',
    reopen_requested_at: '2026-07-02T09:00:00Z', reopen_approved_at: null, reopened_at: null,
    created_at: '2026-07-01T09:00:00Z', ...overrides,
  };
}

test.describe('Cash-up', () => {
  test('Branch Manager submits a cash-up — with NO approval control', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.cash_up.submit']);
    await page.route('**/api/v1/branches/b1/cash-ups/**', (r) => {
      if (r.request().method() === 'PUT') return r.fulfill(ok({ data: cashUp({ id: 'cu1', counted: money(300000), counted_minor: 300000, variance: money(0), variance_minor: 0 }) }));
      return r.fulfill(ok({ data: cashUp() }));
    });
    await page.route('**/api/v1/cash-ups/cu1/submit', (r) => r.fulfill(ok({ data: cashUp({ status: 'submitted', counted_minor: 300000, counted: money(300000) }) })));

    await page.goto('/branch/cash-up');
    await expect(page.getByTestId('cash-up-submit')).toBeVisible();
    await expect(page.getByTestId('cash-up-approve')).toHaveCount(0);
    await page.getByTestId('counted-cash').fill('300000');
    await page.getByTestId('cash-up-save').click();
    await page.getByTestId('cash-up-submit').click();
  });

  test('Finance approves a submitted cash-up', async ({ page }) => {
    await stubMe(page, 'finance', ['cash_up.view', 'cash_up.approve', 'cash_up.reject', 'cash_up.request_correction']);
    await page.route('**/api/v1/cash-ups/cu1', (r) => r.fulfill(ok({ data: cashUp({ status: 'submitted', counted_minor: 300000, counted: money(300000), variance_minor: 0, variance: money(0) }) })));
    await page.route('**/api/v1/cash-ups/cu1/approve', (r) => r.fulfill(ok({ data: cashUp({ status: 'approved' }) })));

    await page.goto('/finance/cash-up/cu1');
    await expect(page.getByTestId('cash-up-approve')).toBeVisible();
    await page.getByTestId('cash-up-approve').click();
    await page.getByTestId('cash-up-decision-confirm').click();
  });
});

test.describe('Period locks', () => {
  test('a locked period blocks a mutation with 423, surfaced to the user (PL-enforced)', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.cash_up.submit']);
    await page.route('**/api/v1/branches/b1/cash-ups/**', (r) => {
      if (r.request().method() === 'PUT') {
        return r.fulfill({ status: 423, contentType: 'application/json', body: JSON.stringify({ error: { code: 'financial_period_locked', message: 'This financial period is locked; the action cannot be completed.', fields: {}, meta: {} } }) });
      }
      return r.fulfill(ok({ data: cashUp() }));
    });

    await page.goto('/branch/cash-up');
    await page.getByTestId('counted-cash').fill('1000');
    await page.getByTestId('cash-up-save').click();
    await expect(page.getByRole('alert')).toContainText(/locked/i);
  });

  test('PL-n/a: receipts remain usable while a period is locked', async ({ page }) => {
    await stubMe(page, 'finance', ['receipt.view']);
    await page.route('**/api/v1/receipts?**', (r) => r.fulfill(ok({ data: [{ id: 'r1', receipt_number: 100, amount: money(200000), currency: 'KES', is_reissue: false, downloadable: true, file_generation_status: 'ready', invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' } }] })));
    await page.goto('/finance/receipts');
    await expect(page.getByTestId('receipt-row').first()).toBeVisible();
  });

  test('Merchant Administrator approves an exceptional reopen — with NO lock/execute controls', async ({ page }) => {
    await stubMe(page, 'merchant_admin', ['merchant.period_reopen.approve_exception']);
    await page.route('**/api/v1/period-locks?**', (r) => r.fulfill(ok({ data: [lock()] })));
    await page.route('**/api/v1/period-locks/l1/reopen/approve', (r) => r.fulfill(ok({ data: lock({ reopen_approved_at: '2026-07-03T09:00:00Z' }) })));

    await page.goto('/merchant/period-reopen-approvals');
    await expect(page.getByTestId('reopen-approve')).toBeVisible();
    await expect(page.getByTestId('period-lock-create-open')).toHaveCount(0);
    await expect(page.getByTestId('period-reopen-execute')).toHaveCount(0);
    await page.getByTestId('reopen-approve').click();
  });

  test('no serious/critical a11y (light + dark) on the Finance periods screen', async ({ page }) => {
    await stubMe(page, 'finance', ['period_lock.create', 'period_lock.reopen']);
    await page.route('**/api/v1/period-locks?**', (r) => r.fulfill(ok({ data: [lock({ exception_required: false, reopen_requested_at: null })] })));
    await page.goto('/finance/periods');
    await expect(page.getByTestId('period-lock-create-open')).toBeVisible();
    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  for (const width of [360, 768, 1280]) {
    test(`branch cash-up has no horizontal overflow at ${width}px`, async ({ page }) => {
      await stubMe(page, 'branch_manager', ['branch.cash_up.submit']);
      await page.route('**/api/v1/branches/b1/cash-ups/**', (r) => r.fulfill(ok({ data: cashUp() })));
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/branch/cash-up');
      await expect(page.getByTestId('cash-up-submit')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }
});
