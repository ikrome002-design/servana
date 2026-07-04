import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 18B E2E — Finance EXPORT request → ready → private signed download, revoke, and
 | the unavailability of unsupported types (compensation/payouts/billing) (Plan §65, §67,
 | §80). SPA preview has no backend; /me + /api/v1 are stubbed to drive the REAL frontend.
 | Genuine masking/scoping/idempotency/download-accounting are proven by
 | tests/Feature/Finance/FinanceExportTest. Linux CI is the authoritative browser gate.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
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
function exp(overrides: Record<string, unknown> = {}) {
  return {
    id: 'e1', export_type: 'payments', scope: 'merchant', branch: null, status: 'ready', reason: 'Monthly',
    row_count: 3, download_count: 0, expires_at: '2026-08-03T09:00:00Z', first_downloaded_at: null,
    last_downloaded_at: null, failure_code: null, failure_message: null, created_at: '2026-07-03T09:00:00Z', ...overrides,
  };
}

test.describe('Finance exports', () => {
  test('offers only the supported data types (never compensation/payouts/billing)', async ({ page }) => {
    await stubMe(page, 'finance', ['finance_export.create', 'finance_export.download']);
    await page.route('**/api/v1/finance-exports?**', (r) => r.fulfill(ok({ data: [] })));

    await page.goto('/finance/exports');
    await page.getByTestId('export-request-open').click();
    const options = await page.locator('#export-type option').allTextContents();
    const values = options.join(' ').toLowerCase();
    for (const t of ['invoices', 'payments', 'receipts', 'cash-up', 'refunds', 'disputes']) expect(values).toContain(t);
    expect(values).not.toContain('compensation');
    expect(values).not.toContain('payout');
    expect(values).not.toContain('billing');
  });

  test('a ready export downloads via a short-lived signed link', async ({ page }) => {
    await stubMe(page, 'finance', ['finance_export.create', 'finance_export.download']);
    await page.route('**/api/v1/finance-exports?**', (r) => r.fulfill(ok({ data: [exp()] })));
    let downloaded = false;
    await page.route('**/api/v1/finance-exports/e1/download-link', (r) => { downloaded = true; return r.fulfill(ok({ data: { url: 'https://signed.example/export.csv', expires_at: '2026-07-03T09:05:00Z' } })); });

    await page.goto('/finance/exports');
    await expect(page.getByTestId('export-row').first()).toBeVisible();
    await page.getByTestId('export-download').click();
    await expect.poll(() => downloaded).toBe(true);
  });

  test('a downloader (no create) cannot request or revoke', async ({ page }) => {
    await stubMe(page, 'finance', ['finance_export.download']);
    await page.route('**/api/v1/finance-exports?**', (r) => r.fulfill(ok({ data: [exp()] })));

    await page.goto('/finance/exports');
    await expect(page.getByTestId('export-request-open')).toHaveCount(0);
    await expect(page.getByTestId('export-revoke')).toHaveCount(0);
    await expect(page.getByTestId('export-download')).toBeVisible();
  });

  test('no serious/critical a11y (light + dark) and no overflow at 360/768/1280', async ({ page }) => {
    await stubMe(page, 'finance', ['finance_export.create', 'finance_export.download']);
    await page.route('**/api/v1/finance-exports?**', (r) => r.fulfill(ok({ data: [exp()] })));
    await page.goto('/finance/exports');
    await expect(page.getByTestId('export-request-open')).toBeVisible();

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
});
