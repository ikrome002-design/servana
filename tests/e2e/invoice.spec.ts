import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 17 E2E — Front Office invoicing (list, draft create, finalize, read-only
 | issued state) and Finance void/adjust controls (Plan §40, §25.3, §80). The SPA
 | preview has no live backend, so /me + /api/v1 are stubbed to drive the REAL
 | frontend. Genuine backend authorization / isolation / idempotency / numbering /
 | snapshots / audit are proven by tests/Feature/Invoicing/*. Linux CI is the
 | authoritative browser gate (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

const CLIENT = { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' };

function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}

function draft() {
  return {
    id: 'inv1', invoice_number: null, status: 'draft', is_draft: true, currency: 'KES', client: CLIENT,
    subtotal: money(500000), discount: money(0), tax: money(0), preferred_personnel_fee: money(20000),
    total: money(520000), validated_paid: money(0), balance: money(520000), percentage_fee_config_snapshot: null,
    finalized_at: null, voided_at: null, void_reason: null, adjusted_at: null, adjustment_reason: null, created_at: '2026-07-01T06:00:00+00:00',
    items: [{ id: 'it1', service: { id: 'sv1', name: 'Haircut' }, personnel: { id: 'st1', display_name: 'Joy W.' }, description: 'Haircut', quantity: 1, unit_price: money(500000), line_total: money(500000), preferred_personnel_fee: money(20000), eligible_for_commission: true, currency: 'KES' }],
    can: { update: true, finalize: true, void: false, void_execute: false, void_reject: false, adjust: false },
  };
}

function issued() {
  return { ...draft(), invoice_number: 'KIL-INV-000001', status: 'issued', is_draft: false, finalized_at: '2026-07-01T07:00:00+00:00', can: { update: false, finalize: false, void: false, void_execute: false, void_reject: false, adjust: false } };
}

function issuedForFinance() {
  return { ...issued(), can: { update: false, finalize: false, void: true, void_execute: false, void_reject: false, adjust: true } };
}

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, role, false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(
      ok({
        data: {
          user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: { id: 'mm1', role, status: 'active' },
          memberships: [{ id: 'mm1', role, status: 'active' }],
          account_keys: [accountKeyForRole(role, false)],
          permissions,
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
        },
      }),
    ),
  );
}

test.describe('Front Office invoicing', () => {
  test('lists invoices with a New invoice button and no invoice number for a draft', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
    await page.route('**/api/v1/invoices?**', (r) => r.fulfill(ok({ data: [draft()] })));
    await page.goto('/front-office/invoices');

    await expect(page.getByRole('heading', { name: 'Invoices', exact: true })).toBeVisible();
    await expect(page.getByTestId('new-invoice')).toBeVisible();
    await expect(page.getByTestId('invoice-status-badge')).toHaveText('Draft');
  });

  test('shows the number only after finalization and marks the issued invoice read-only', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: draft() })));
    await page.goto('/front-office/invoices/inv1');

    // Draft: no number, a Finalize control, the preferred fee shown separately.
    await expect(page.getByRole('heading', { name: 'Draft', exact: true })).toBeVisible();
    await expect(page.getByTestId('finalize')).toBeVisible();
    await expect(page.getByTestId('item-preferred-fee')).toContainText('Preferred-personnel fee');
    await expect(page.getByTestId('readonly-note')).toHaveCount(0);

    // Finalize: the number appears and the read-only snapshot note shows.
    await page.route('**/api/v1/invoices/inv1/finalize', (r) => r.fulfill(ok({ data: issued() })));
    await page.getByTestId('finalize').click();
    await page.getByTestId('finalize-confirm').click();

    await expect(page.getByRole('heading', { name: 'KIL-INV-000001', exact: true })).toBeVisible();
    await expect(page.getByTestId('readonly-note')).toBeVisible();
    await expect(page.getByTestId('finalize')).toHaveCount(0);
  });

  test('exposes no payment or receipt control', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issued() })));
    await page.goto('/front-office/invoices/inv1');
    await expect(page.getByRole('heading', { name: 'KIL-INV-000001', exact: true })).toBeVisible();

    const body = (await page.getByRole('main').textContent())?.toLowerCase() ?? '';
    expect(body).not.toContain('record payment');
    expect(body).not.toContain('receipt');
    expect(body).not.toContain('mark paid');
  });

  test('has no serious or critical accessibility violations (light + dark)', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issued() })));
    await page.goto('/front-office/invoices/inv1');
    await expect(page.getByRole('heading', { name: 'KIL-INV-000001', exact: true })).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  for (const width of [360, 768, 1280]) {
    test(`invoice detail has no horizontal overflow at ${width}px`, async ({ page }) => {
      await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
      await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issued() })));
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/front-office/invoices/inv1');
      await expect(page.getByTestId('invoice-total')).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('remains legible at 200% browser zoom', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'invoice.create']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issued() })));
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/front-office/invoices/inv1');
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    await expect(page.getByTestId('invoice-total')).toBeVisible();
  });
});

test.describe('Finance invoicing controls', () => {
  test('shows capability-gated void + adjust controls with an irreversible-action warning', async ({ page }) => {
    await stubMe(page, 'finance', ['invoice.view', 'invoice.void.request_or_execute_as_policy', 'invoice.adjustment.manage']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedForFinance() })));
    await page.goto('/finance/invoices/inv1');

    await expect(page.getByTestId('void')).toBeVisible();
    await expect(page.getByTestId('adjust')).toBeVisible();
    await expect(page.getByTestId('finalize')).toHaveCount(0);

    await page.getByTestId('void').click();
    await expect(page.getByText('irreversible financial action', { exact: false })).toBeVisible();
  });
});
