import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 18A E2E — Front Office payment recording (single, split, method-aware
 | evidence, pending-validation success, duplicate warning, no receipt/validation)
 | and Finance payment-records read + duplicate override capability (Plan §41, §80).
 | The SPA preview has no live backend, so /me + /api/v1 are stubbed to drive the
 | REAL frontend. Genuine backend recording/locking/overpayment/idempotency/duplicate/
 | override/audit/isolation are proven by tests/Feature/Payments/*. Linux CI is the
 | authoritative browser gate (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}

const CLIENT = { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' };

function issuedInvoice() {
  return {
    id: 'inv1', invoice_number: 'KIL-INV-000001', status: 'issued', is_draft: false, currency: 'KES', client: CLIENT,
    subtotal: money(500000), discount: money(0), tax: money(0), preferred_personnel_fee: null,
    total: money(500000), validated_paid: money(0), balance: money(500000), percentage_fee_config_snapshot: null,
    finalized_at: '2026-07-01T07:00:00+00:00', voided_at: null, void_reason: null, adjusted_at: null, adjustment_reason: null,
    created_at: '2026-07-01T06:00:00+00:00', items: [],
    can: { update: false, finalize: false, void: false, void_execute: false, void_reject: false, adjust: false },
  };
}

function group(overrides: Record<string, unknown> = {}) {
  return {
    id: 'grp1', status: 'pending_validation', is_pending_validation: true, currency: 'KES', total: money(200000),
    recorded_at: '2026-07-02T08:00:00+00:00', submitted_for_validation_at: '2026-07-02T08:00:00+00:00',
    maker: { id: 'u1', name: 'Ada' }, invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' },
    components: [{ id: 'c1', method: 'cash', amount: money(200000), status: 'pending_validation', reference_masked: null }],
    duplicate_checks: [],
    ...overrides,
  };
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

test.describe('Front Office payment recording', () => {
  test('records a cash payment that stays pending validation with no receipt', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));
    await page.route('**/api/v1/invoices/inv1/payment-recording-groups', (r) => r.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: group() }) }));

    await page.goto('/front-office/payments/record/inv1');
    await expect(page.getByTestId('available-amount')).toContainText('5000.00');

    await page.locator('#amount-0').fill('2000');
    await page.getByTestId('review-payment').click();
    await page.getByTestId('confirm-record').click();

    const success = page.getByTestId('record-success');
    await expect(success).toBeVisible();
    await expect(success).toContainText(/pending validation/i);
    const body = (await page.getByRole('main').textContent())?.toLowerCase() ?? '';
    expect(body).toContain('no receipt');
    await expect(page.getByRole('button', { name: /validate/i })).toHaveCount(0);
  });

  test('builds a split payment and reflects the running total', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));

    await page.goto('/front-office/payments/record/inv1');
    await page.locator('#amount-0').fill('1500');
    await page.getByTestId('add-component').click();
    await page.locator('#amount-1').fill('2500');
    await expect(page.getByTestId('group-total')).toContainText('4,000.00');
  });

  test('shows a reference field for a non-cash method and masks it in the duplicate warning', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));
    await page.route('**/api/v1/invoices/inv1/payment-recording-groups', (r) =>
      r.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: { code: 'payment_reference_duplicate_suspected', message: 'Duplicate', fields: {}, meta: { group_id: 'grp1', method: 'mpesa_offline', masked_reference: '••••1ABC' } } }) }),
    );

    await page.goto('/front-office/payments/record/inv1');
    await page.locator('#method-0').selectOption('mpesa_offline');
    await expect(page.locator('#reference-0')).toBeVisible();
    await page.locator('#amount-0').fill('1000');
    await page.locator('#reference-0').fill('QGX7YT1ABC');
    await page.getByTestId('review-payment').click();
    await page.getByTestId('confirm-record').click();

    const warning = page.getByTestId('duplicate-warning');
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('••••1ABC');
  });

  test('blocks an overpayment beyond the available balance', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));

    await page.goto('/front-office/payments/record/inv1');
    await page.locator('#amount-0').fill('6000');
    await expect(page.getByRole('main')).toContainText(/exceeds the amount available/i);
    await expect(page.getByTestId('review-payment')).toBeDisabled();
  });

  test('has no serious or critical accessibility violations (light + dark)', async ({ page }) => {
    await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
    await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));
    await page.goto('/front-office/payments/record/inv1');
    await expect(page.getByTestId('available-amount')).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  for (const width of [360, 768, 1280]) {
    test(`recording form has no horizontal overflow at ${width}px`, async ({ page }) => {
      await stubMe(page, 'front_office', ['invoice.view', 'customer_payment.record']);
      await page.route('**/api/v1/invoices/inv1', (r) => r.fulfill(ok({ data: issuedInvoice() })));
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/front-office/payments/record/inv1');
      await expect(page.getByTestId('group-total')).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }
});

test.describe('Finance payment records', () => {
  test('lists pending groups and offers a capability-gated duplicate override', async ({ page }) => {
    await stubMe(page, 'finance', ['customer_payment.view', 'customer_payment.duplicate_override']);
    await page.route('**/api/v1/payment-recording-groups?**', (r) => r.fulfill(ok({ data: [group({ status: 'recorded' })] })));
    await page.goto('/finance/payment-records');
    await expect(page.getByRole('heading', { name: 'Payment recordings', exact: true })).toBeVisible();
    await expect(page.getByTestId('group-status-badge')).toContainText(/duplicate review/i);

    await page.route('**/api/v1/payment-recording-groups/grp1', (r) =>
      r.fulfill(ok({ data: group({ status: 'recorded', duplicate_checks: [{ id: 'chk1', method: 'mpesa_offline', reference_masked: '••••1ABC' }] }) })),
    );
    await page.goto('/finance/payment-records/grp1');
    await expect(page.getByTestId('duplicate-review')).toBeVisible();
    await expect(page.getByTestId('override-open')).toBeVisible();
  });

  test('hides the override control without the capability and shows no receipt/validate action', async ({ page }) => {
    await stubMe(page, 'finance', ['customer_payment.view']);
    await page.route('**/api/v1/payment-recording-groups/grp1', (r) =>
      r.fulfill(ok({ data: group({ status: 'recorded', duplicate_checks: [{ id: 'chk1', method: 'mpesa_offline', reference_masked: '••••1ABC' }] }) })),
    );
    await page.goto('/finance/payment-records/grp1');
    await expect(page.getByTestId('duplicate-review')).toBeVisible();
    await expect(page.getByTestId('override-open')).toHaveCount(0);

    const body = (await page.getByRole('main').textContent())?.toLowerCase() ?? '';
    expect(body).not.toContain('validate');
    expect(body).not.toContain('receipt');
  });
});
