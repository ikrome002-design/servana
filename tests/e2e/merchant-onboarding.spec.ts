import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | UI e2e for merchant onboarding (Scope §3.1/§3.2, Plan §27 Phase 6). The SPA
 | preview has no backend, so /api/v1 + /sanctum are stubbed to exercise the real
 | frontend: self-registration → uniform success, pending_setup → wizard, wizard
 | completion → merchant active → dashboard, plus accessibility. The genuine
 | backend/Mailpit flow is proven by the feature suite + docs/proof/phase-6.md.
 */

const OWNER = {
  id: '01J0000000000000000000USER',
  email: 'owner@example.com',
  name: 'Paul Nderitu',
  status: 'active',
  email_verified_at: null,
  is_platform_staff: false,
};

const MERCHANT_PENDING = {
  id: '01J000000000000000000MERCH',
  name: 'Servana Demo Salon',
  slug: 'servana-demo-salon',
  status: 'pending_setup',
  service_fee_tier: null,
  setup_completed_at: null,
};

const MERCHANT_ACTIVE = { ...MERCHANT_PENDING, status: 'active', service_fee_tier: 'split_tier', setup_completed_at: '2026-06-14T00:00:00+00:00' };

const MEMBERSHIP = { id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' };

function bootstrap(merchant: unknown, setup: unknown): string {
  return JSON.stringify({
    data: {
      user: OWNER,
      merchant,
      membership: MEMBERSHIP,
      memberships: [MEMBERSHIP],
      permissions: [],
      setup,
    },
  });
}

async function stubLoggedOut(page: Page): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }),
    }),
  );
}

test.describe('Merchant onboarding UI', () => {
  test('self-registration submits and shows the uniform success state', async ({ page }) => {
    await stubLoggedOut(page);
    await page.route('**/api/v1/merchant-registration/self-register', (route) =>
      route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'If this is a new business, we have sent a sign-in link.' }),
      }),
    );

    await page.goto('/auth/register');
    await page.fill('#owner_name', 'Paul Nderitu');
    await page.fill('#business_name', 'Servana Demo Salon');
    await page.fill('#email', 'owner@example.com');
    await page.click('button[type="submit"]');

    await expect(page.getByTestId('register-success')).toBeVisible();
  });

  test('pending_setup owner is routed to the first-time setup wizard', async ({ page }) => {
    // /me returns a pending owner; visiting the dashboard must bounce to setup.
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: bootstrap(MERCHANT_PENDING, { required: true, current_step: 'service_fee_tier', completed_at: null }) }),
    );

    await page.goto('/merchant');
    await expect(page).toHaveURL(/\/onboarding\/first-time-setup/);
    await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
  });

  test('completing the wizard flips the merchant active and lands on the dashboard', async ({ page }) => {
    let completed = false;

    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: completed
          ? bootstrap(MERCHANT_ACTIVE, { required: false, current_step: 'done', completed_at: '2026-06-14T00:00:00+00:00' })
          : bootstrap(MERCHANT_PENDING, { required: true, current_step: 'service_fee_tier', completed_at: null }),
      }),
    );
    await page.route('**/api/v1/merchant-registration/first-time-setup', (route) => {
      completed = true;
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { merchant: MERCHANT_ACTIVE, redirect: 'merchant.dashboard' } }),
      });
    });

    await page.goto('/onboarding/first-time-setup');

    // Step 1 — tier.
    await page.selectOption('#service_fee_tier', 'split_tier');
    await page.getByRole('button', { name: 'Continue' }).click();
    // Step 2 — profile.
    await page.fill('#business_category', 'Salon');
    await page.fill('#contact_phone', '+254700000000');
    await page.getByRole('button', { name: 'Continue' }).click();
    // Step 3 — branch.
    await page.fill('#branch_name', 'Main Branch');
    await page.fill('#branch_code', 'MAIN');
    await page.getByRole('button', { name: 'Continue' }).click();
    // Step 4 — staff.
    await page.fill('#branch_manager_email', 'bm@demo.co.ke');
    await page.fill('#hr_email', 'hr@demo.co.ke');
    await page.getByRole('button', { name: 'Finish setup' }).click();

    await expect(page).toHaveURL(/\/merchant$/);
    await expect(page.getByRole('heading', { name: /Welcome/ })).toBeVisible();
  });

  test('axe is clean on the registration and setup wizard pages', async ({ page }) => {
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/me', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: bootstrap(MERCHANT_PENDING, { required: true, current_step: 'service_fee_tier', completed_at: null }) }),
    );

    for (const path of ['/auth/register', '/onboarding/first-time-setup']) {
      await page.goto(path);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations).toEqual([]);
    }
  });
});
