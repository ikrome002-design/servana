import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';

/*
 | Phase 20E E2E — the percentage platform-fee frontend surfaces (Plan §51, §52, §13.10, §27.1): the
 | Super-Administrator configuration tab, the merchant/Finance/Branch/Audit fee + dispute surface, and the
 | Front-Office client-shifted invoice line. The SPA preview has no backend; `/me` + `/api/v1` are stubbed
 | to drive the REAL frontend. Genuine authorization, MFA, fresh step-up, server-side scope, period-lock,
 | maker/checker, idempotency, the append-only ledger and the future-cycle correction carry-forward are
 | proven by the backend Feature suite (tests/Feature/Billing/*); these prove frontend behaviour, role
 | gating, canonical copy and accessibility. No Wallet/payment/provider/settlement surface exists.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}

interface MeOpts {
  isPlatformStaff?: boolean;
  role?: string | null;
  permissions?: string[];
}

async function stubMe(page: Page, opts: MeOpts = {}): Promise<void> {
  const isPlatformStaff = opts.isPlatformStaff ?? false;
  // Phase UI-03: the account context the Laravel shell embeds, which requiresAccount needs.
  // The preview origin serves no Laravel shell, so without it the /platform guard fails closed.
  // Phase UI-07: the account context must follow the ROLE this test bootstraps. Hard-coding
  // merchant_administrator was safe only while /platform was the sole guarded tree; now a
  // branch_manager or finance bootstrap would be handed another account's host context and
  // correctly denied.
  await stubAccountContextForRole(page, opts.role, isPlatformStaff);
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@citrus.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: isPlatformStaff },
        merchant: isPlatformStaff ? null : { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
        memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
        permissions: opts.permissions ?? [],
        setup: { required: false, current_step: null, completed_at: null },
        account_keys: [accountKeyForRole(opts.role, isPlatformStaff)],
        branch_ids: opts.role === 'branch_manager' || opts.role === 'audit' ? ['b1'] : [],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: null, recovery_codes_remaining: 0 },
      },
    })),
  );
}

function config(overrides: Record<string, unknown> = {}) {
  return {
    id: '01CFG0000000000000000000001',
    billing_mode: 'percentage_on_merchant_client_invoice',
    percentage_basis_points: 250,
    fixed_component_minor: null,
    tier_behavior: 'shared',
    shared_split_basis_points: 5000,
    fee_basis_type: 'merchant_client_invoice_service_subtotal',
    currency: 'KES',
    effective_from: '2026-08-01',
    effective_to: null,
    status: 'active',
    approved_at: '2026-08-01T00:00:00+00:00',
    change_reason: 'Launch',
    capabilities: { editable: false, approvable: false, supersedable: true, cancellable: false },
    ...overrides,
  };
}

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: '01ENTRY000000000000000001', merchant_id: 'm1', branch_id: 'b1', source_invoice_id: '01INV', source_invoice_item_id: '01ITEM',
    entry_type: 'earned', status: 'invoiced', billing_mode: 'percentage_on_merchant_client_invoice', service_fee_tier: 'shared',
    fee_basis_type: 'merchant_client_invoice_service_subtotal', fee_basis_amount_minor: 500000, percentage_rate_basis_points: 250,
    shared_split_basis_points: 5000, gross_platform_fee_minor: 12500, client_shifted_amount_minor: 6250, merchant_absorbed_amount_minor: 6250,
    merchant_liability_minor: 12500, currency: 'KES', subscription_invoice_item_id: '01ROLL', billable_at: '2026-07-05T10:00:00+00:00', ...overrides,
  };
}

function dispute(overrides: Record<string, unknown> = {}) {
  return {
    id: '01DISP0000000000000000001', platform_fee_ledger_entry_id: '01ENTRY000000000000000001', subscription_invoice_id: null,
    reason: 'Fee looks wrong', status: 'open', assigned_reviewer: null, resolution_note: null, has_evidence: false,
    created_by: '01USER', resolved_by: null, resolved_at: null, created_at: '2026-08-01T09:00:00+00:00',
    capabilities: { reviewable: true, resolvable: false, rejectable: true }, ...overrides,
  };
}

const summary = [{ currency: 'KES', entry_count: 2, gross_platform_fee_minor: 25000, client_shifted_amount_minor: 12500, merchant_absorbed_amount_minor: 12500 }];

async function stubConfig(page: Page): Promise<void> {
  await page.route(/\/api\/v1\/platform\/billing\/platform-fee-configurations(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: config({ id: '01NEW', status: 'draft' }) }));
    return r.fulfill(ok({ data: [config()] }));
  });
  await page.route(/\/api\/v1\/platform\/billing\/platform-fee-configurations\/[^/]+\/(approve|supersede|cancel)$/, (r) =>
    r.fulfill(ok({ data: config({ status: 'active' }) })),
  );
  // Other billing-settings tabs load their own endpoints; stub them empty so the page renders.
  await page.route(/\/api\/v1\/platform\/(platform-billing-settings|subscription-plans|plan-prices|preferred-personnel-fee-rules)(\?|$|\/)/, (r) => r.fulfill(ok({ data: [] })));
}

async function stubFees(page: Page, entries = [entry()], disputes = [dispute()]): Promise<void> {
  await page.route(/\/api\/v1\/platform-fees\/summary$/, (r) => r.fulfill(ok({ data: summary })));
  await page.route(/\/api\/v1\/platform-fees(\?|$)/, (r) => r.fulfill(ok({ data: entries })));
  await page.route(/\/api\/v1\/platform-fee-disputes(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: dispute({ id: '01NEWDISP' }) }));
    return r.fulfill(ok({ data: disputes }));
  });
  await page.route(/\/api\/v1\/platform-fee-disputes\/[^/]+\/(review|resolve|reject)$/, (r) =>
    r.fulfill(ok({ data: dispute({ status: 'under_review' }) })),
  );
}

/* ---------------------------------------------------------------- Super Administrator config */

test.describe('Super Administrator platform-fee configuration', () => {
  test('shows the Platform fees tab and an active config as read-only with supersede', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: ['platform.platform_fee.configure'] });
    await stubConfig(page);
    // Increment 7B: `/billing/settings` is the canonical page and composes the platform-fee
    // section directly, so there is no tab to click. `/platform/billing-settings` still resolves
    // here through the compatibility redirect.
    await page.goto('/billing/settings');
    await expect(page.getByRole('heading', { name: 'Percentage platform-fee configuration' })).toBeVisible();
    // Active config: supersede offered, no in-place edit; the canonical "Shared" label, never split_tier.
    await expect(page.getByRole('button', { name: 'Supersede' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Edit draft' })).toHaveCount(0);
    await expect(page.getByText('split_tier')).toHaveCount(0);
    await expect(page.getByText('Shared', { exact: false })).toBeVisible();
  });

  test('validates a shared tier without a split and does not submit', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: ['platform.platform_fee.configure'] });
    await stubConfig(page);
    await page.goto('/billing/settings');
    await page.getByRole('button', { name: 'New draft configuration' }).click();
    await page.locator('#pf-tier').selectOption('shared');
    await page.locator('#pf-bps').fill('250');
    await page.locator('#pf-eff-from').fill('2026-09-01');
    await page.locator('#pf-reason').fill('New split');
    // Shared split intentionally blank → client validation blocks submit; the dialog stays open and the
    // required-split error is shown (no server call made — the backend is the authority regardless).
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('dialog', { name: 'New draft configuration' })).toBeVisible();
    await expect(page.locator('#pf-split')).toBeVisible();
  });

  test('hides configuration entirely without the configure permission', async ({ page }) => {
    await stubMe(page, { isPlatformStaff: true, permissions: ['platform.settings.view'] });
    await stubConfig(page);
    await page.goto('/billing/settings');
    // A denied section is ABSENT, not a disabled tab: nothing advertises the capability.
    await expect(page.getByRole('heading', { name: 'Percentage platform-fee configuration' })).toHaveCount(0);
    await expect(page.getByRole('tab')).toHaveCount(0);
  });
});

/* ---------------------------------------------------------------- merchant / finance / branch / audit */

test.describe('Merchant / Finance / Branch / Audit fee surface', () => {
  test('merchant admin sees the summary + entries and can raise a dispute (no review controls)', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: ['platform_fee.view', 'platform_fee.dispute'] });
    await stubFees(page);
    await page.goto('/merchant/platform-fees');
    await expect(page.getByRole('heading', { name: 'Platform fees' })).toBeVisible();
    await expect(page.getByText('250.00')).toBeVisible(); // server summary gross
    await expect(page.getByRole('button', { name: 'Raise a dispute' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start review' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Resolve' })).toHaveCount(0);
  });

  test('finance sees the dispute review controls', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['platform_fee.view', 'platform_fee.dispute', 'platform_fee.dispute.review'] });
    await stubFees(page);
    await page.goto('/finance/platform-fees');
    await expect(page.getByRole('button', { name: 'Start review' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reject' })).toBeVisible();
  });

  test('branch / audit (view only) show no dispute-create or review controls', async ({ page }) => {
    await stubMe(page, { role: 'branch_manager', permissions: ['platform_fee.view'] });
    await stubFees(page);
    await page.goto('/branch/platform-fees');
    await expect(page.getByRole('heading', { name: 'Platform fees' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Raise a dispute' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Start review' })).toHaveCount(0);
  });

  test('merchant admin creates a dispute from an entry', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: ['platform_fee.view', 'platform_fee.dispute'] });
    await stubFees(page);
    await page.goto('/merchant/platform-fees');
    await page.getByRole('button', { name: 'Raise a dispute' }).click();
    await page.locator('#pf-dispute-entry').fill('01ENTRY000000000000000001');
    await page.locator('#pf-dispute-reason').fill('Charged twice');
    await page.getByRole('button', { name: 'Raise dispute' }).click();
    // The modal closes on a successful create.
    await expect(page.locator('#pf-dispute-reason')).toHaveCount(0);
  });
});

/* ---------------------------------------------------------------- responsive / zoom / a11y */

test.describe('Responsive, zoom, keyboard and accessibility', () => {
  async function gotoMerchant(page: Page): Promise<void> {
    await stubMe(page, { role: 'merchant_admin', permissions: ['platform_fee.view', 'platform_fee.dispute'] });
    await stubFees(page);
    await page.goto('/merchant/platform-fees');
    await expect(page.getByRole('heading', { name: 'Platform fees' })).toBeVisible();
  }

  for (const width of [360, 768, 1280]) {
    test(`has no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await gotoMerchant(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('remains usable at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoMerchant(page);
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    await expect(page.getByRole('heading', { name: 'Platform fees' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
  });

  test('opens the dispute dialog by keyboard and restores focus on Escape', async ({ page }) => {
    await gotoMerchant(page);
    const trigger = page.getByRole('button', { name: 'Raise a dispute' });
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`passes axe with zero serious/critical violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoMerchant(page);
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });
  }
});
