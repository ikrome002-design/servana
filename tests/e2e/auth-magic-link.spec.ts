import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | UI e2e for the Magic Link flow (Plan §9.1). The SPA preview has no backend, so
 | the /api/v1 + /sanctum endpoints are stubbed to exercise the real frontend
 | behaviour (submit → check-email, verify success → redirect, verify failure →
 | uniform error) and accessibility. The genuine backend + Mailpit flow is proven
 | by the feature suite and the API transcript in docs/proof/phase-5.md.
 */

const AUTH_USER = {
  id: '01J0000000000000000000USER',
  email: 'owner@salon.co.ke',
  name: 'Owner',
  status: 'active',
  email_verified_at: '2026-06-14T00:00:00+00:00',
  memberships: [],
  permissions: [],
  is_platform_staff: false,
};

/** Routes the app bootstrap always calls; default to logged-out. */
async function stubBaseline(page: Page): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }),
    }),
  );
}

test.describe('Magic Link UI', () => {
  test('login submits the email and shows the check-email screen', async ({ page }) => {
    await stubBaseline(page);
    await page.route('**/api/v1/auth/magic-link', (route) =>
      route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'If the email exists and is active, a link was sent.' }),
      }),
    );

    await page.goto('/auth/login');
    await page.fill('#email', 'owner@salon.co.ke');
    await page.click('button[type="submit"]');

    await expect(page.getByText('Check your email')).toBeVisible();
    await expect(page).toHaveURL(/\/auth\/check-email/);
  });

  test('verify consumes the token and redirects home on success', async ({ page }) => {
    await stubBaseline(page);
    await page.route('**/api/v1/auth/magic-link/verify', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: AUTH_USER }),
      }),
    );

    await page.goto('/auth/verify?token=good-token');
    await expect(page).toHaveURL(/\/$/);
  });

  test('verify shows a uniform error for an invalid or expired token', async ({ page }) => {
    await stubBaseline(page);
    await page.route('**/api/v1/auth/magic-link/verify', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          error: {
            code: 'invalid_or_expired_token',
            message: 'This sign-in link is invalid or has expired. Please request a new one.',
            fields: {},
            meta: {},
          },
        }),
      }),
    );

    await page.goto('/auth/verify?token=dead-token');
    await expect(page.getByText('invalid or has expired')).toBeVisible();
  });

  test('no horizontal scroll on the login page at 360/768/1280', async ({ page }) => {
    await stubBaseline(page);
    for (const width of [360, 768, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/auth/login');
      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    }
  });

  test('axe is clean on login, check-email and verify pages', async ({ page }) => {
    await stubBaseline(page);
    await page.route('**/api/v1/auth/magic-link/verify', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          error: { code: 'invalid_or_expired_token', message: 'This sign-in link is invalid or has expired. Please request a new one.', fields: {}, meta: {} },
        }),
      }),
    );

    for (const path of ['/auth/login', '/auth/check-email?email=owner@salon.co.ke', '/auth/verify?token=dead']) {
      await page.goto(path);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations).toEqual([]);
    }
  });
});
