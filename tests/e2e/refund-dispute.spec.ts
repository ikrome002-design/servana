import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 18B E2E — external REFUND approve/finalize (distinct checker, irreversible
 | finalize) and finance DISPUTE lifecycle (source read-only) (Plan §44, §80). SPA
 | preview has no backend; /me + /api/v1 are stubbed to drive the REAL frontend. Genuine
 | maker/checker, step-up, reversal accounting and isolation are proven by
 | tests/Feature/Refunds/* + tests/Feature/Finance/*. Linux CI is the authoritative gate.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}
async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, role, false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: { id: 'mm1', role, status: 'active' }, memberships: [{ id: 'mm1', role, status: 'active' }],
        account_keys: [accountKeyForRole(role, false)],
        permissions, setup: { required: false, current_step: null, completed_at: null }, branch_ids: ['b1'],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
      },
    })),
  );
}
function refund(overrides: Record<string, unknown> = {}) {
  return {
    id: 'rf1', status: 'requested', amount: money(50000), currency: 'KES', method: 'cash', reference_masked: null,
    reason: 'Client returned the service.', refund_group: 'g1', approved_at: null, finalized_at: null, rejected_at: null,
    created_at: '2026-07-03T09:00:00Z', invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001', status: 'refund_pending' },
    payment_record: { id: 'c1', method: 'cash' }, ...overrides,
  };
}
function dispute(overrides: Record<string, unknown> = {}) {
  return {
    id: 'd1', status: 'under_review', reason: 'Client disputes the charge.', resolution_note: null, has_evidence: false,
    created_at: '2026-07-03T09:00:00Z', updated_at: '2026-07-03T09:00:00Z',
    invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' }, payment_record: null, ...overrides,
  };
}

test.describe('External refunds', () => {
  test('approves then finalizes with an irreversible warning', async ({ page }) => {
    await stubMe(page, 'finance', ['refund.approve', 'refund.finalize']);
    let current = refund();
    await page.route('**/api/v1/refunds/rf1', (r) => r.fulfill(ok({ data: current })));
    await page.route('**/api/v1/refunds/rf1/approve', (r) => { current = refund({ status: 'approved', approved_at: '2026-07-03T10:00:00Z' }); return r.fulfill(ok({ data: current })); });
    await page.route('**/api/v1/refunds/rf1/finalize', (r) => { current = refund({ status: 'finalized', approved_at: '2026-07-03T10:00:00Z', finalized_at: '2026-07-03T10:05:00Z' }); return r.fulfill(ok({ data: current })); });

    await page.goto('/finance/refunds/rf1');
    await page.getByTestId('refund-approve').click();
    await page.getByTestId('refund-confirm').click();
    await expect(page.getByTestId('refund-finalize')).toBeVisible();
    await page.getByTestId('refund-finalize').click();
    await expect(page.getByText(/IRREVERSIBLE/)).toBeVisible();
    await page.getByTestId('refund-confirm').click();
    await expect(page.getByText('finalized')).toBeVisible();
  });

  test('a requester (no approve/finalize) sees no checker controls', async ({ page }) => {
    await stubMe(page, 'finance', ['refund.create']);
    await page.route('**/api/v1/refunds/rf1', (r) => r.fulfill(ok({ data: refund() })));
    await page.goto('/finance/refunds/rf1');
    await expect(page.getByTestId('refund-approve')).toHaveCount(0);
    await expect(page.getByTestId('refund-finalize')).toHaveCount(0);
  });
});

test.describe('Finance disputes', () => {
  test('resolves an under-review dispute with a note; source is read-only', async ({ page }) => {
    await stubMe(page, 'finance', ['finance_dispute.manage']);
    let current = dispute();
    await page.route('**/api/v1/finance-disputes/d1', (r) => r.fulfill(ok({ data: current })));
    await page.route('**/api/v1/finance-disputes/d1/resolve', (r) => { current = dispute({ status: 'resolved', resolution_note: 'Charge confirmed valid.' }); return r.fulfill(ok({ data: current })); });

    await page.goto('/finance/disputes/d1');
    await expect(page.getByText(/read-only/i)).toBeVisible();
    await page.getByTestId('dispute-resolve').click();
    await page.locator('#dispute-note').fill('Charge confirmed valid.');
    await page.getByTestId('dispute-decision-confirm').click();
    await expect(page.getByText('resolved')).toBeVisible();
  });

  test('no serious/critical a11y violations (light + dark) on the refund detail', async ({ page }) => {
    await stubMe(page, 'finance', ['refund.approve']);
    await page.route('**/api/v1/refunds/rf1', (r) => r.fulfill(ok({ data: refund() })));
    await page.goto('/finance/refunds/rf1');
    await expect(page.getByTestId('refund-approve')).toBeVisible();
    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  for (const width of [360, 768, 1280]) {
    test(`refund detail has no horizontal overflow at ${width}px`, async ({ page }) => {
      await stubMe(page, 'finance', ['refund.approve']);
      await page.route('**/api/v1/refunds/rf1', (r) => r.fulfill(ok({ data: refund() })));
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/finance/refunds/rf1');
      await expect(page.getByTestId('refund-approve')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }
});
