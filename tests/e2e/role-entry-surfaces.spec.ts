import { expect, test } from '@playwright/test';
import { ROLES, stubBootstrap } from './support/roleBootstrap';

/*
 | Phase 11 role-entry surfaces (Plan §27.2). Each role lands on its own live
 | landing page with its FAQ + legal footer, and a resumable get-started page.
 | /me is stubbed; the frontend is UX only.
 */

test.describe('role landing pages', () => {
  for (const role of ROLES) {
    test(`${role.identity} lands on its own live landing page`, async ({ page }) => {
      await stubBootstrap(page, role);
      await page.goto(role.path);

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

  test('checklist completion survives reload and dismiss/reopen works', async ({ page }) => {
    await stubBootstrap(page, role);
    await page.goto(`${role.path}/get-started`);

    const firstItem = page.locator('[data-testid="checklist-verify-email"]');
    await expect(firstItem).toBeVisible();
    await firstItem.check();
    await expect(firstItem).toBeChecked();

    await page.reload();
    await expect(page.locator('[data-testid="checklist-verify-email"]')).toBeChecked();

    // Dismiss → hidden; reopen → checklist returns.
    await page.locator('[data-testid="dismiss-get-started"]').click();
    await expect(page.locator('[data-testid="reopen-get-started"]')).toBeVisible();
    await page.reload();
    await expect(page.locator('[data-testid="reopen-get-started"]')).toBeVisible();
    await page.locator('[data-testid="reopen-get-started"]').click();
    await expect(page.locator('[data-testid="checklist-verify-email"]')).toBeChecked();
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
