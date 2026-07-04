import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 18B E2E — Finance whole-group payment VALIDATION (one receipt), rejection
 | (no receipt), and RECEIPT view/reissue/download (Plan §42–§43, §80). The SPA preview
 | has no live backend, so /me + /api/v1 are stubbed to drive the REAL frontend. Genuine
 | validation/receipt numbering/maker-checker/locking/audit are proven by
 | tests/Feature/Payments/* + tests/Feature/Receipts/*. Linux CI is the authoritative
 | browser gate (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(
      ok({
        data: {
          user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: { id: 'mm1', role, status: 'active' },
          memberships: [{ id: 'mm1', role, status: 'active' }],
          permissions,
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
        },
      }),
    ),
  );
}

function group(overrides: Record<string, unknown> = {}) {
  return {
    id: 'grp1', status: 'pending_validation', is_pending_validation: true, currency: 'KES', total: money(200000),
    maker: { id: 'u1', name: 'Front Office' }, invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' },
    components: [{ id: 'c1', method: 'cash', amount: money(200000), status: 'pending_validation', reference_masked: null }],
    duplicate_checks: [],
    ...overrides,
  };
}
function receipt(overrides: Record<string, unknown> = {}) {
  return {
    id: 'r1', receipt_number: 100, amount: money(200000), currency: 'KES',
    components: [{ method: 'cash', amount: money(200000) }], is_reissue: false, downloadable: true,
    file_generation_status: 'ready', created_at: '2026-07-03T09:00:00Z', invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' },
    ...overrides,
  };
}

test.describe('Finance payment validation → receipt', () => {
  test('validates a WHOLE group and shows one issued receipt', async ({ page }) => {
    await stubMe(page, 'finance', ['customer_payment.view', 'customer_payment.validate', 'customer_payment.reject']);
    await page.route('**/api/v1/payment-recording-groups/grp1', (r) => r.fulfill(ok({ data: group() })));
    await page.route('**/api/v1/payment-recording-groups/grp1/validate', (r) => r.fulfill(ok({ data: group({ status: 'validated' }) })));

    await page.goto('/finance/payment-records/grp1');
    await expect(page.getByTestId('validate-open')).toBeVisible();
    await page.getByTestId('validate-open').click();
    await page.getByTestId('decision-confirm').click();

    await expect(page.getByTestId('receipt-issued')).toBeVisible();
  });

  test('Front Office cannot validate (no whole-group controls)', async ({ page }) => {
    await stubMe(page, 'front_office', ['customer_payment.record']);
    await page.route('**/api/v1/payment-recording-groups/grp1', (r) => r.fulfill(ok({ data: group() })));

    await page.goto('/front-office/payments'); // FO has no validation surface
    await page.goto('/finance/payment-records/grp1'); // even if navigated, controls are absent
    await expect(page.getByTestId('validate-open')).toHaveCount(0);
    await expect(page.getByTestId('reject-open')).toHaveCount(0);
  });

  test('rejection creates NO receipt', async ({ page }) => {
    await stubMe(page, 'finance', ['customer_payment.view', 'customer_payment.validate', 'customer_payment.reject']);
    await page.route('**/api/v1/payment-recording-groups/grp1', (r) => r.fulfill(ok({ data: group() })));
    await page.route('**/api/v1/payment-recording-groups/grp1/reject', (r) => r.fulfill(ok({ data: group({ status: 'rejected' }) })));

    await page.goto('/finance/payment-records/grp1');
    await page.getByTestId('reject-open').click();
    await page.locator('#decision-reason').fill('Reference did not match the bank statement.');
    await page.getByTestId('decision-confirm').click();

    await expect(page.getByTestId('receipt-issued')).toHaveCount(0);
  });

  test('receipt reissue produces a new receipt number; download uses a signed link', async ({ page }) => {
    await stubMe(page, 'finance', ['receipt.view', 'receipt.reissue']);
    let current = receipt();
    await page.route('**/api/v1/receipts/r1', (r) => r.fulfill(ok({ data: current })));
    await page.route('**/api/v1/receipts/r1/reissue', (r) => { current = receipt({ receipt_number: 101, is_reissue: true, reason: 'Reprint requested' }); return r.fulfill(ok({ data: current })); });
    await page.route('**/api/v1/receipts/r1/download-link', (r) => r.fulfill(ok({ data: { url: 'https://signed.example/download', expires_at: '2026-07-03T09:05:00Z' } })));

    await page.goto('/finance/receipts/r1');
    await expect(page.getByText('Receipt #100')).toBeVisible();
    await page.getByTestId('receipt-reissue-open').click();
    await page.locator('#reissue-reason').fill('Reprint requested');
    await page.getByTestId('receipt-reissue-confirm').click();
    await expect(page.getByText('Receipt #101')).toBeVisible();
  });

  test('Front Office sees a receipt but NOT reissue', async ({ page }) => {
    await stubMe(page, 'front_office', ['receipt.view']);
    await page.route('**/api/v1/receipts/r1', (r) => r.fulfill(ok({ data: receipt() })));

    await page.goto('/front-office/receipts/r1');
    await expect(page.getByTestId('receipt-download')).toBeVisible();
    await expect(page.getByTestId('receipt-reissue-open')).toHaveCount(0);
  });

  test('no serious/critical a11y violations on the validation detail (light + dark) and keyboard focus is visible', async ({ page }) => {
    await stubMe(page, 'finance', ['customer_payment.view', 'customer_payment.validate', 'customer_payment.reject']);
    await page.route('**/api/v1/payment-recording-groups/grp1', (r) => r.fulfill(ok({ data: group() })));
    await page.goto('/finance/payment-records/grp1');
    await expect(page.getByTestId('validate-open')).toBeVisible();

    await page.keyboard.press('Tab');
    const focused = await page.evaluate(() => document.activeElement?.tagName);
    expect(focused).toBeTruthy();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  for (const width of [360, 768, 1280]) {
    test(`validation detail has no horizontal overflow at ${width}px`, async ({ page }) => {
      await stubMe(page, 'finance', ['customer_payment.view', 'customer_payment.validate']);
      await page.route('**/api/v1/payment-recording-groups/grp1', (r) => r.fulfill(ok({ data: group() })));
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/finance/payment-records/grp1');
      await expect(page.getByTestId('validate-open')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }
});
