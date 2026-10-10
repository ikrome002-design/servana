import { expect, test } from '@playwright/test';
import { ROLES, stubBootstrap } from './support/roleBootstrap';
import { stubMerchantApi } from './support/ui09Merchant';

/*
 | Phase 11 role-entry surfaces (Plan §27.2), reconciled with UI-13's exact Front Office and UI-14's
 | exact Personnel route registers. Six accounts retain their authenticated landing; Front Office
 | and Personnel enter their canonical dashboards. /me is stubbed; the frontend is UX only.
 */

test.describe('role landing pages', () => {
  for (const role of ROLES) {
    test(`${role.identity} enters its own live account surface`, async ({ page }) => {
      await stubBootstrap(page, role);
      await page.goto(role.path);

      if (role.identity === 'merchant_front_office') {
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByTestId('front-office-dashboard')).toBeVisible();
        await expect(page.getByText('Today’s service desk')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Get Started', exact: true })).toBeVisible();
        return;
      }

      // UI-14: the retired `/personnel` landing redirects to the canonical private workday dashboard
      // on the same host. The workspace API is not stubbed here, so the page's own error boundary
      // shows — the entry surface (shell, dashboard region, Get Started) is what is asserted.
      if (role.identity === 'merchant_personnel') {
        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByTestId('personnel-dashboard')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Get Started', exact: true })).toBeVisible();
        return;
      }

      // Role-true entry surface: role label, FAQ, and legal footer.
      await expect(page.getByText(role.label, { exact: false }).first()).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Frequently asked questions' })).toBeVisible();
      await expect(
        page.getByRole('link', { name: 'Terms of Service' }).first(),
      ).toBeVisible();

      // Get-started is reachable from the landing.
      await expect(page.getByRole('link', { name: 'Open get-started' })).toBeVisible();
    });
  }
});

test.describe('get-started persistence', () => {
  const role = ROLES.find((r) => r.identity === 'merchant_administrator')!;

  test('server-observed completion survives reload and dismiss/reopen works', async ({ page }) => {
    await stubBootstrap(page, role);
    await stubMerchantApi(page);
    await page.goto(`${role.path}/get-started`);

    const firstItem = page.locator('[data-testid="checklist-verify-email"]');
    await expect(firstItem).toBeVisible();
    await expect(firstItem).toBeChecked();
    await expect(firstItem).toBeDisabled();

    await page.reload();
    await expect(page.locator('[data-testid="checklist-verify-email"]')).toBeChecked();

    // Dismiss → hidden; reopen → checklist returns.
    await page.locator('[data-testid="dismiss-get-started"]').click();
    await expect(page.locator('[data-testid="reopen-get-started"]')).toBeVisible();
    await page.reload();
    await expect(page.locator('[data-testid="reopen-get-started"]')).toBeVisible();
    await page.locator('[data-testid="reopen-get-started"]').click();
    await expect(page.locator('[data-testid="checklist-verify-email"]')).toBeChecked();
    await expect(page.locator('[data-testid="checklist-verify-email"]')).toBeDisabled();
  });
});

test.describe('legal acknowledgement', () => {
  const role = ROLES.find((r) => r.identity === 'merchant_finance')!;

  test('mandatory acknowledgement cannot be bypassed', async ({ page }) => {
    await stubBootstrap(page, role);
    await page.goto(`${role.path}/get-started`);

    await page.locator('[data-testid="open-acknowledgement"]').click();
    const confirm = page.locator('[data-testid="confirm-acknowledgement"]');
    await expect(confirm).toBeDisabled();

    await page.locator('[data-testid="accept-terms-of-service"]').check();
    await page.locator('[data-testid="accept-privacy-policy"]').check();
    await page.locator('[data-testid="accept-data-policy"]').check();
    await expect(confirm).toBeEnabled();
    await confirm.click();

    await expect(page.locator('[data-testid="open-acknowledgement"]')).toHaveText(/Acknowledged/);
  });
});
