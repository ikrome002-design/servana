import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | UI e2e for the privileged MFA flow (Plan §18, Phase R3). The SPA preview has
 | no backend, so /api/v1 + /sanctum endpoints are stubbed to exercise the real
 | frontend behaviour: a mandatory user is routed to enrollment or the session
 | challenge, completes it, and lands on the app. The genuine backend flow is
 | proven by the feature suite (tests/Feature/Auth/Mfa*) and docs/proof/phase-r3.md.
 */

const BASE_USER = {
  user: {
    id: '01J0000000000000000000USER',
    email: 'owner@salon.co.ke',
    name: 'Owner',
    status: 'active',
    email_verified_at: '2026-06-14T00:00:00+00:00',
    is_platform_staff: false,
  },
  merchant: null,
  membership: { id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' },
  memberships: [{ id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' }],
  permissions: [],
  setup: { required: false, current_step: null, completed_at: null },
  branch_ids: [],
};

function mfa(overrides: Record<string, unknown>) {
  return {
    required: true,
    enrolled: false,
    confirmed: false,
    verified: false,
    enrollment_required: false,
    challenge_required: false,
    step_up_fresh: false,
    step_up_fresh_until: null,
    recovery_codes_remaining: 0,
    ...overrides,
  };
}

async function stubMe(page: Page, mfaState: Record<string, unknown>): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...BASE_USER, mfa: mfaState } }),
    }),
  );
}

test.describe('Privileged MFA UI', () => {
  test('routes a mandatory user to enrollment and completes setup', async ({ page }) => {
    await stubMe(page, mfa({ enrollment_required: true }));
    await page.route('**/api/v1/auth/mfa/enroll', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            secret: 'ABCDEF234567ABCD',
            otpauth_uri: 'otpauth://totp/Servana:owner@salon.co.ke?secret=ABCDEF234567ABCD',
            mfa: mfa({ enrolled: true, enrollment_required: true }),
          },
        }),
      }),
    );
    await page.route('**/api/v1/auth/mfa/confirm', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            recovery_codes: ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
            mfa: mfa({ enrolled: true, confirmed: true, verified: true }),
          },
        }),
      }),
    );

    await page.goto('/');
    await expect(page).toHaveURL(/\/auth\/mfa\/setup/);
    await expect(page.getByText('ABCDEF234567ABCD', { exact: true })).toBeVisible();

    await page.fill('#mfa-code', '123456');
    await page.click('button[type="submit"]');

    await expect(page.getByText('AAAAA-BBBBB')).toBeVisible();
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/$/);
  });

  test('routes a confirmed user to the session challenge and verifies', async ({ page }) => {
    await stubMe(page, mfa({ enrolled: true, confirmed: true, challenge_required: true }));
    await page.route('**/api/v1/auth/mfa/challenge', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { ...BASE_USER, mfa: mfa({ enrolled: true, confirmed: true, verified: true }) } }),
      }),
    );

    await page.goto('/');
    await expect(page).toHaveURL(/\/auth\/mfa\/challenge/);

    await page.fill('#mfa-challenge-code', '123456');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/$/);
  });

  test('axe is clean on the MFA setup and challenge pages', async ({ page }) => {
    await stubMe(page, mfa({ enrolled: true, confirmed: true, challenge_required: true }));
    await page.route('**/api/v1/auth/mfa/enroll', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            secret: 'ABCDEF234567ABCD',
            otpauth_uri: 'otpauth://totp/Servana:owner@salon.co.ke?secret=ABCDEF234567ABCD',
            mfa: mfa({ enrolled: true, enrollment_required: true }),
          },
        }),
      }),
    );

    for (const path of ['/auth/mfa/setup', '/auth/mfa/challenge']) {
      await page.goto(path);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations).toEqual([]);
    }
  });
});
