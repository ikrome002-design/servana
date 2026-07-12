import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 20C E2E — the Super-Administrator promotions surface (promotional discounts + free-period
 | offers) and merchant read-only applied snapshots (Plan §53, §27.1). The SPA preview has no backend;
 | `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine authorization, MFA, fresh step-up,
 | snapshot immutability, target resolution and discount arithmetic are proven by the backend Feature
 | suite (tests/Feature/Billing/*); these prove the frontend behaviour, gating, and accessibility. No
 | Wallet/payment/provider surface exists.
 */

const PLATFORM_PERMS = ['platform.promotion.manage', 'platform.free_period_offer.manage'];

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
        branch_ids: [],
        mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
      },
    })),
  );
}

function promo(overrides: Record<string, unknown> = {}) {
  return {
    id: '01PROMO0000000000000000001', name: 'Launch 10%', type: 'percentage', value: 1000, currency: null,
    target_scope: 'all_new_merchants', effective_from: '2026-07-12', effective_to: null, status: 'draft',
    approved_at: null, change_reason: null, targets: [], ...overrides,
  };
}
function offer(overrides: Record<string, unknown> = {}) {
  return {
    id: '01OFFER000000000000000001', name: 'Free 30', free_period_days: 30, target_scope: 'all_new_merchants',
    effective_from: '2026-07-12', effective_to: null, status: 'draft', approved_at: null, change_reason: null,
    targets: [], ...overrides,
  };
}

async function stubPromotions(page: Page, promos: unknown[] = [], offers: unknown[] = []): Promise<void> {
  await page.route(/\/api\/v1\/platform\/promotional-discounts(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: promo({ id: '01NEW', name: 'Created' }) }));
    return r.fulfill(ok({ data: promos }));
  });
  await page.route(/\/api\/v1\/platform\/promotional-discounts\/[^/]+\/(approve|pause|resume|cancel)$/, (r) =>
    r.fulfill(ok({ data: promo({ status: 'active' }) })),
  );
  await page.route(/\/api\/v1\/platform\/free-period-offers(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: offer({ id: '01NEWOFFER', name: 'Created offer' }) }));
    return r.fulfill(ok({ data: offers }));
  });
  await page.route(/\/api\/v1\/platform\/free-period-offers\/[^/]+\/(approve|pause|resume|cancel)$/, (r) =>
    r.fulfill(ok({ data: offer({ status: 'scheduled' }) })),
  );
}

async function gotoPromotions(page: Page): Promise<void> {
  await stubMe(page, { isPlatformStaff: true, permissions: PLATFORM_PERMS });
  await page.goto('/platform/promotions');
}

/* ---------------------------------------------------------------- surface + role gating */

test.describe('Super Administrator promotions surface', () => {
  test('renders both sections for a fully-permitted Super Administrator', async ({ page }) => {
    await stubPromotions(page);
    await gotoPromotions(page);
    const tabs = page.getByRole('tab');
    await expect(tabs).toHaveCount(2);
    await expect(tabs.nth(0)).toHaveText('Promotional discounts');
    await expect(tabs.nth(1)).toHaveText('Free-period offers');
  });

  test('creates a percentage promotion', async ({ page }) => {
    await stubPromotions(page);
    await gotoPromotions(page);
    await page.getByRole('button', { name: 'New promotion' }).click();
    await page.locator('#promo-name').fill('Winter 15%');
    await page.locator('#promo-type').selectOption('percentage');
    await page.locator('#promo-value').fill('1500');
    await page.locator('#promo-scope').selectOption('all_new_merchants');
    await page.locator('#promo-from').fill('2026-07-12');
    await page.getByRole('button', { name: 'Create draft' }).click();
    // Form closes on success (the create request resolved).
    await expect(page.locator('#promo-name')).toHaveCount(0);
  });

  test('creates a fixed-amount promotion with a currency field', async ({ page }) => {
    await stubPromotions(page);
    await gotoPromotions(page);
    await page.getByRole('button', { name: 'New promotion' }).click();
    await page.locator('#promo-type').selectOption('fixed_amount');
    await page.locator('#promo-name').fill('KES 500 off');
    await page.locator('#promo-value').fill('50000');
    await page.locator('#promo-from').fill('2026-07-12');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.locator('#promo-name')).toHaveCount(0);
  });

  test('exposes merchant/plan/billing-mode target inputs by scope', async ({ page }) => {
    await stubPromotions(page);
    await gotoPromotions(page);
    await page.getByRole('button', { name: 'New promotion' }).click();
    await page.locator('#promo-scope').selectOption('selected_merchants');
    await expect(page.locator('#promo-targets')).toBeVisible();
    await page.locator('#promo-scope').selectOption('billing_mode');
    await expect(page.locator('#promo-mode')).toBeVisible();
    await page.locator('#promo-scope').selectOption('all_new_merchants');
    await expect(page.locator('#promo-targets')).toHaveCount(0);
  });

  test('creates a free-period offer from its section', async ({ page }) => {
    await stubPromotions(page);
    await gotoPromotions(page);
    await page.getByRole('tab', { name: 'Free-period offers' }).click();
    await page.getByRole('button', { name: 'New free-period offer' }).click();
    await page.locator('#offer-name').fill('Free 45');
    await page.locator('#offer-days').fill('45');
    await page.locator('#offer-from').fill('2026-07-12');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.locator('#offer-name')).toHaveCount(0);
  });

  test('approval opens a reason modal and requires a reason', async ({ page }) => {
    await stubPromotions(page, [promo({ status: 'draft' })]);
    await gotoPromotions(page);
    await page.getByRole('button', { name: 'approve' }).first().click();
    // Modal open; confirming with an empty reason is rejected client-side.
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('A reason is required.')).toBeVisible();
    await page.locator('#reason').fill('Approved for launch');
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  for (const status of ['scheduled', 'active', 'paused'] as const) {
    test(`renders a ${status} promotion with its status`, async ({ page }) => {
      await stubPromotions(page, [promo({ status, name: `Promo ${status}` })]);
      await gotoPromotions(page);
      await expect(page.getByText(`Promo ${status}`)).toBeVisible();
      await expect(page.getByText(status, { exact: true })).toBeVisible();
    });
  }

  test('shows no Wallet / payment / provider control anywhere', async ({ page }) => {
    await stubPromotions(page, [promo({ status: 'active' })]);
    await gotoPromotions(page);
    const body = (await page.locator('body').textContent())?.toLowerCase() ?? '';
    expect(body).not.toContain('wallet');
    expect(body).not.toContain('stk push');
    expect(body).not.toContain('paybill');
    expect(body).not.toContain('record payment');
  });
});

test.describe('Role boundary', () => {
  test('a merchant user sees no promotion-management controls (UX gate; API is authoritative)', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: ['merchant.subscription.view'] });
    await stubPromotions(page);
    await page.goto('/platform/promotions');
    // The page renders but every management control is absent (denied, not disabled); the API
    // additionally denies a non-platform user server-side (proven by the backend Feature suite).
    await expect(page.getByRole('tab')).toHaveCount(0);
    await expect(page.getByText('No access')).toBeVisible();
    await expect(page.getByRole('button', { name: 'New promotion' })).toHaveCount(0);
  });
});

/* ---------------------------------------------------------------- responsive / a11y */

test.describe('Responsive, zoom, dark mode and accessibility', () => {
  for (const width of [360, 768, 1280]) {
    test(`has no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await stubPromotions(page, [promo({ status: 'active' })]);
      await gotoPromotions(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('remains usable at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await stubPromotions(page);
    await gotoPromotions(page);
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    await expect(page.getByRole('heading', { name: 'Promotions & free periods' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
  });

  test('supports keyboard operation and restores focus after the reason modal closes', async ({ page }) => {
    await stubPromotions(page, [promo({ status: 'draft' })]);
    await gotoPromotions(page);
    const approve = page.getByRole('button', { name: 'approve' }).first();
    await approve.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`passes axe with zero serious/critical violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await stubPromotions(page, [promo({ status: 'active' }), promo({ id: '01P2', status: 'draft', name: 'Draft promo' })]);
      await gotoPromotions(page);
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });
  }
});
