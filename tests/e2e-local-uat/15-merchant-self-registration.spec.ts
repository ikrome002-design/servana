/**
 * LOCAL-UAT-001 §7C (Merchant Administrator) — a brand-new business onboards itself in the browser:
 * public registration on servana.test -> verification email in Mailpit -> mandatory MFA enrolment on
 * the real enrolment screen -> the first-time `/setup` gate -> setup completed -> dashboard.
 *
 * Each run registers ONE new, clearly named local business (`UAT Registration <stamp>`); nothing
 * existing is modified.
 */
import { expect, test } from '@playwright/test';

import { api, appendResult, get, latestMessageId, origin, totpForSecret, waitForSignInLink, watchPage } from './support/localUat';

test('a new merchant registers, verifies, enrols MFA and completes first-time setup', async ({ page }) => {
  const health = watchPage(page);
  const stamp = Date.now().toString(36);
  const email = `uat.registration.${stamp}@register.servana.test`;
  const businessName = `UAT Registration ${stamp}`;

  const before = await latestMessageId(page.request, email);
  await page.goto(`${origin('merchant_administrator')}/register`);
  await page.getByLabel('Your name').fill('UAT Registration Owner');
  await page.getByLabel('Business name').fill(businessName);
  await page.getByLabel('Email address').fill(email);
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();

  const link = await waitForSignInLink(page.request, email, before);
  expect(new URL(link).hostname, 'registration binds the link to the Merchant Administrator host').toBe('servana.test');
  await page.goto(link);

  // Mandatory MFA for a Merchant Administrator: the genuine enrolment screen.
  await page.waitForURL(/\/auth\/mfa\/setup/);
  const secret = (await page.getByText('Scan or enter this setup key').locator('xpath=following-sibling::p[1]').innerText()).trim();
  expect(secret.length).toBeGreaterThan(15);
  await page.getByLabel('6-digit code').fill(totpForSecret(secret));
  await page.getByRole('button', { name: 'Confirm and continue' }).click();
  await page.getByLabel('I have saved my recovery codes').check();
  await page.getByRole('button', { name: 'Continue' }).click();

  // The first-time setup gate.
  await page.waitForURL((url) => url.pathname === '/setup');
  await expect(page.locator('h1').first()).toBeVisible();
  const setupHeading = (await page.locator('h1').first().innerText()).trim();

  const options = await api(page, 'merchant_administrator', 'GET', '/api/v1/merchant-registration/first-time-setup');
  expect(options.status).toBe(200);
  const plans = (get(options.body, 'data.options.subscription_plans') ?? []) as { id: string; prices?: { id: string }[] }[];
  const plan = plans.find((p) => (p.prices ?? []).length > 0);
  expect(plan, `a plan with a price is offered (${JSON.stringify(options.body).slice(0, 400)})`).toBeDefined();

  const setup = await api(page, 'merchant_administrator', 'POST', '/api/v1/merchant-registration/first-time-setup', {
    service_fee_tier: 'customer_centric',
    subscription_plan_ulid: plan?.id,
    subscription_plan_price_ulid: plan?.prices?.[0]?.id,
    business_category: 'Salon',
    contact_phone: '+254700000099',
    timezone: 'Africa/Nairobi',
    branch: { name: 'Main', code: 'MAIN', town: 'Nairobi' },
    branch_manager_email: `uat.registration.${stamp}.branch@register.servana.test`,
    hr_email: `uat.registration.${stamp}.hr@register.servana.test`,
  });
  expect(setup.status, JSON.stringify(setup.body).slice(0, 400)).toBeLessThan(300);

  await page.goto(`${origin('merchant_administrator')}/dashboard`);
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.locator('h1').first()).toBeVisible();
  expect(health.errors).toEqual([]);

  appendResult('self-registration', {
    business: businessName,
    setup_heading: setupHeading,
    setup_status: setup.status,
    landed_after_setup: new URL(page.url()).pathname,
    result: 'pass',
  });
});
