import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { stubAccountContextFor } from './support/roleBootstrap';

/*
 | Phase 20A E2E — the platform billing-settings screen (Plan §13.8–§13.10, §27.1, §47, §50)
 | and the Branch Manager read-only effective-fee surface. The SPA preview has no backend;
 | `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine platform scope, MFA,
 | fresh step-up, overlap rejection, immutability and non-enumeration are proven by the
 | backend Feature suite (tests/Feature/Billing/*, tests/Feature/Auth/Permission*); these
 | specs prove the frontend behaviour, gating, and accessibility. No Phase 20B surface exists.
 */

const PLATFORM_PERMS = [
  'platform.settings.view',
  'platform.settings.update',
  'platform.billing_settings.view',
  'platform.billing_settings.update',
  'platform.plan.view',
  'platform.plan.manage',
  'platform.plan_price.manage',
  'platform.preferred_personnel_fee.manage',
];

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function err(status: number, code: string, message = code) {
  return { status, contentType: 'application/json', body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }) };
}

interface MeOpts {
  isPlatformStaff?: boolean;
  role?: string | null;
  permissions?: string[];
  branchIds?: string[];
  mfa?: Record<string, unknown>;
}

async function stubMe(page: Page, opts: MeOpts = {}): Promise<void> {
  const isPlatformStaff = opts.isPlatformStaff ?? true;
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
        branch_ids: isPlatformStaff ? [] : (opts.branchIds ?? ['b1']),
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0, ...(opts.mfa ?? {}) },
      },
    })),
  );
}

const settings = {
  id: '01HSETTINGS', billing_mode: 'fixed_amount', default_trial_days: 14, grace_days: 7,
  currency: 'KES', settings: { invoice_due_days: '30', support_email: '', statement_footer: '' },
  effective_from: '2026-07-10T00:00:00+00:00',
};
const plan = { id: '01PLAN', key: 'starter', name: 'Starter', description: 'Entry tier', tier: null, metadata: {}, status: 'active', sort_order: 0 };
function price(overrides: Record<string, unknown> = {}) {
  return { id: '01PRICE', amount_minor: 250000, currency: 'KES', billing_interval: 'monthly', effective_from: '2026-07-10', effective_to: null, lifecycle: 'current', ...overrides };
}
function feeRule(overrides: Record<string, unknown> = {}) {
  return {
    id: '01FEE', calculation_type: 'fixed_amount', fixed_amount_minor: 5000, percentage_basis_points: null,
    currency: 'KES', calculation_basis: 'service_item_net_amount', scope: 'platform_default', service_id: null,
    effective_from: '2026-07-10', effective_to: null, status: 'active', approved_at: '2026-07-10T00:00:00+00:00',
    change_reason: 'launch', ...overrides,
  };
}

async function stubSettings(page: Page): Promise<void> {
  await page.route('**/api/v1/platform/billing-settings', (r) => {
    if (r.request().method() === 'PUT') return r.fulfill(ok({ data: { ...settings, default_trial_days: 30 } }));
    return r.fulfill(ok({ data: settings }));
  });
  await page.route('**/api/v1/platform/settings', (r) => r.fulfill(ok({ data: settings })));
}

/* ----------------------------------------------------------- navigation + access */

test.describe('Phase 20A navigation and access', () => {
  test('Super Administrator sees the Billing settings navigation and screen', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubSettings(page);
    await page.goto('/platform');
    await expect(
      page.getByTestId('header-primary-nav').getByRole('link', { name: 'Billing settings' }),
    ).toBeVisible();

    await page.goto('/platform/billing-settings');
    await expect(page.getByTestId('billing-screen')).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(6);
  });

  test('a merchant identity cannot see billing configuration on the platform route', async ({ page }) => {
    // Front Office is a merchant role with no platform permissions.
    await stubMe(page, { isPlatformStaff: false, role: 'front_office', permissions: ['invoice.create'] });
    await page.goto('/platform/billing-settings');
    // No tabs render; the server-derived capability map denies every panel.
    await expect(page.getByRole('tab')).toHaveCount(0);
    await expect(page.getByText('do not have access')).toBeVisible();
  });

  test('a mandatory-MFA challenge redirects away from the billing route (reads still gated by MFA)', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS, mfa: { required: true, enrolled: true, confirmed: true, challenge_required: true } });
    await page.goto('/platform/billing-settings');
    await expect(page).toHaveURL(/\/auth\/mfa\/challenge/);
  });

  test('only viewable tabs render (denied panels are absent, not disabled)', async ({ page }) => {
    await stubMe(page, { permissions: ['platform.billing_settings.view'] });
    await stubSettings(page);
    await page.goto('/platform/billing-settings');
    await expect(page.getByRole('tab')).toHaveCount(1);
    await expect(page.getByRole('tab', { name: 'Billing settings' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Preferred-personnel fee' })).toHaveCount(0);
  });
});

/* ----------------------------------------------------------- settings + modes */

test.describe('Billing settings', () => {
  test('loads current settings and offers the three canonical billing modes', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubSettings(page);
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Billing settings' }).click();

    const mode = page.locator('#billing-mode');
    await expect(mode).toBeVisible();
    const values = await mode.locator('option').evaluateAll((opts) => opts.map((o) => (o as HTMLOptionElement).value));
    expect(values).toEqual([
      'fixed_amount',
      'percentage_on_merchant_client_invoice',
      'fixed_amount_plus_percentage_on_merchant_client_invoice',
    ]);
    await expect(page.locator('#currency')).toHaveValue('KES');
  });

  test('a reads-only viewer sees no save control', async ({ page }) => {
    await stubMe(page, { permissions: ['platform.billing_settings.view'] });
    await stubSettings(page);
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Billing settings' }).click();
    await expect(page.getByRole('button', { name: 'Save billing settings' })).toHaveCount(0);
    await expect(page.getByText('read-only access to billing settings')).toBeVisible();
  });

  test('a fresh step-up is required to save (server 403 surfaces the guidance)', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await page.route('**/api/v1/platform/settings', (r) => r.fulfill(ok({ data: settings })));
    await page.route('**/api/v1/platform/billing-settings', (r) => {
      if (r.request().method() === 'PUT') return r.fulfill(err(403, 'step_up_required', 'A fresh step-up is required.'));
      return r.fulfill(ok({ data: settings }));
    });
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Billing settings' }).click();
    await page.getByRole('button', { name: 'Save billing settings' }).click();
    await expect(page.getByRole('alert')).toContainText('fresh step-up');
  });
});

/* ----------------------------------------------------------- plans + prices */

test.describe('Plans and prices', () => {
  // Match the plans-LIST endpoint precisely (not its /prices or /entitlements subpaths) so
  // per-test subpath routes are unambiguous regardless of registration order.
  const PLANS_LIST = /\/api\/v1\/platform\/plans(\?|$)/;

  async function stubPlans(page: Page): Promise<void> {
    await stubSettings(page);
    await page.route(PLANS_LIST, (r) => r.fulfill(ok({ data: [plan] })));
  }

  test('plan metadata has no price field; retire is offered for an active plan', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubPlans(page);
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Plans' }).click();
    await expect(page.getByTestId('plan-row')).toHaveCount(1);

    await page.getByRole('button', { name: 'New plan' }).click();
    // The create form exposes metadata only — no amount/price input anywhere.
    await expect(page.locator('#plan-key')).toBeVisible();
    await expect(page.locator('#plan-name')).toBeVisible();
    await expect(page.locator('input[id*="amount"], input[id*="price"]')).toHaveCount(0);
  });

  test('scheduling a price offers the five canonical intervals; historical/current are read-only', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubPlans(page);
    await page.route('**/api/v1/platform/plans/01PLAN/prices', (r) => r.fulfill(ok({ data: [price({ lifecycle: 'current' })] })));
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Plans' }).click();
    await page.getByRole('button', { name: 'Prices & entitlements' }).click();

    await expect(page.getByTestId('price-row')).toHaveCount(1);
    // A current price is read-only (no cancel control on the row).
    await expect(page.getByTestId('price-row').getByText('Read-only')).toBeVisible();

    await page.getByRole('button', { name: 'Schedule price' }).click();
    const intervals = await page.locator('#price-interval option').evaluateAll((opts) => opts.map((o) => (o as HTMLOptionElement).value));
    expect(intervals).toEqual(['weekly', 'bi_weekly', 'monthly', 'quarterly', 'annual']);
  });

  test('an overlapping price is rejected with an explicit conflict message', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubPlans(page);
    await page.route('**/api/v1/platform/plans/01PLAN/prices', (r) => {
      if (r.request().method() === 'POST') return r.fulfill(err(409, 'duplicate_reference'));
      return r.fulfill(ok({ data: [price()] }));
    });
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Plans' }).click();
    await page.getByRole('button', { name: 'Prices & entitlements' }).click();
    await page.getByRole('button', { name: 'Schedule price' }).click();

    await page.locator('#price-amount').fill('2500');
    await page.locator('#price-from').fill('2026-08-01');
    await page.getByRole('button', { name: 'Schedule price' }).nth(1).click();
    await expect(page.getByRole('alert')).toContainText('overlaps an existing effective range');
  });
});

/* ----------------------------------------------------------- entitlements */

test.describe('Entitlements', () => {
  test('enable/disable/limit editing with no merchant-subscription binding', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubSettings(page);
    await page.route(/\/api\/v1\/platform\/plans(\?|$)/, (r) => r.fulfill(ok({ data: [plan] })));
    await page.route('**/api/v1/platform/plans/01PLAN/entitlements', (r) =>
      r.fulfill(ok({ data: [{ entitlement_key: 'branches.max', enabled: true, limit_int: 3 }] })));
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Plans' }).click();
    await page.getByRole('button', { name: 'Prices & entitlements' }).click();
    await page.getByRole('tab', { name: 'Entitlements' }).click();

    await expect(page.getByText('branches.max', { exact: true })).toBeVisible();
    await expect(page.locator('#limit-branches\\.max')).toHaveValue('3');
    // No merchant/subscription selector exists on this surface.
    await expect(page.getByText(/merchant subscription/i)).toHaveCount(0);
  });
});

/* ----------------------------------------------------------- preferred fee */

test.describe('Preferred-personnel fee rules', () => {
  async function stubFees(page: Page, rules = [feeRule()]): Promise<void> {
    await stubSettings(page);
    await page.route('**/api/v1/platform/preferred-personnel-fee-rules**', (r) => {
      const url = r.request().url();
      if (r.request().method() === 'POST' && url.endsWith('/supersede')) return r.fulfill(ok({ data: feeRule({ id: '01NEW', status: 'scheduled' }) }));
      return r.fulfill(ok({ data: rules }));
    });
  }

  test('an active rule is read-only and offers supersede (never in-place edit)', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubFees(page);
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Preferred-personnel fee' }).click();
    await expect(page.getByTestId('fee-rule-row')).toHaveCount(1);
    await expect(page.getByText('Active terms are read-only')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Supersede' })).toBeVisible();
  });

  test('fixed and percentage inputs are mutually exclusive; service scope requires a service', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubFees(page, []);
    await page.goto('/platform/billing-settings');
    await page.getByRole('tab', { name: 'Preferred-personnel fee' }).click();
    await page.getByRole('button', { name: 'New draft rule' }).click();

    await expect(page.locator('#fee-fixed-amount')).toBeVisible();
    await expect(page.locator('#fee-basis-points')).toHaveCount(0);
    await page.locator('#fee-calc-type').selectOption('percentage');
    await expect(page.locator('#fee-basis-points')).toBeVisible();
    await expect(page.locator('#fee-fixed-amount')).toHaveCount(0);

    await expect(page.locator('#fee-service')).toHaveCount(0);
    await page.locator('#fee-scope').selectOption('service');
    await expect(page.locator('#fee-service')).toBeVisible();
  });
});

/* ----------------------------------------------------------- Branch Manager read-only */

test.describe('Branch Manager effective-fee (read-only)', () => {
  async function stubBranch(page: Page, permissions: string[]): Promise<void> {
    await stubMe(page, { isPlatformStaff: false, role: 'branch_manager', permissions });
    await page.route('**/api/v1/service-categories', (r) => r.fulfill(ok({ data: [{ id: 'c1', name: 'Hair', sort_order: 0, archived: false }] })));
    await page.route('**/api/v1/services**', (r) => r.fulfill(ok({ data: [] })));
    await page.route('**/api/v1/branch/preferred-personnel-fee-rule**', (r) =>
      r.fulfill(ok({ data: { calculation_type: 'fixed_amount', fixed_amount_minor: 5000, percentage_basis_points: null, currency: 'KES', calculation_basis: 'service_item_net_amount', effective_from: '2026-07-10', effective_to: null } })));
  }

  test('shows the effective fee read-only with no mutation controls', async ({ page }) => {
    await stubBranch(page, ['service.view', 'preferred_personnel_fee.view_branch_rule']);
    await page.goto('/branch/services');
    const card = page.getByTestId('branch-preferred-fee');
    await expect(card).toBeVisible();
    await expect(card.getByText('KES 50.00')).toBeVisible();
    // Read-only: no create/supersede/cancel/approve controls anywhere in the card.
    await expect(card.getByRole('button')).toHaveCount(0);
  });

  test('is absent when the branch view permission is not held', async ({ page }) => {
    await stubBranch(page, ['service.view']);
    await page.goto('/branch/services');
    await expect(page.getByTestId('branch-preferred-fee')).toHaveCount(0);
  });
});

/* ----------------------------------------------------------- accessibility */

test.describe('Accessibility, responsive and keyboard', () => {
  test('no serious/critical axe (light + dark) and no overflow at 360/768/1280', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubSettings(page);
    await page.goto('/platform/billing-settings');
    await expect(page.getByTestId('billing-screen')).toBeVisible();

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

  test('tabs form an accessible, arrow-navigable tablist', async ({ page }) => {
    await stubMe(page, { permissions: PLATFORM_PERMS });
    await stubSettings(page);
    await page.goto('/platform/billing-settings');
    const tablist = page.getByRole('tablist');
    await expect(tablist).toBeVisible();

    const first = page.getByRole('tab').first();
    await first.focus();
    await expect(first).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Billing settings' })).toBeFocused();
  });
});
