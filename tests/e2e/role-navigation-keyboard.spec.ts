import { expect, test } from '@playwright/test';
import { ROLES, stubBootstrap } from './support/roleBootstrap';

/*
 | Phase 11 navigation placement + keyboard operability (Plan §26, §30; mandatory
 | navigation-placement rule). Super Administrator uses header navigation; every
 | merchant role uses a sidebar (desktop) + accessible drawer (mobile) with focus
 | return on close.
 */

test.describe('navigation placement', () => {
  test('Super Administrator primary navigation is in the header (no sidebar)', async ({ page }) => {
    const sa = ROLES.find((r) => r.identity === 'super_administrator')!;
    await stubBootstrap(page, sa);
    await page.goto(sa.path);
    await expect(page.locator('[data-testid="header-primary-nav"]')).toBeVisible();
    await expect(page.locator('[data-testid="sidebar-primary-nav"]')).toHaveCount(0);
  });

  test('merchant role keeps primary navigation out of the header', async ({ page }) => {
    const finance = ROLES.find((r) => r.identity === 'merchant_finance')!;
    await stubBootstrap(page, finance);
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(finance.path);
    await expect(page.locator('[data-testid="header-primary-nav"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="sidebar-primary-nav"]')).toBeVisible();
  });
});

test.describe('keyboard operability', () => {
  test('skip link is keyboard-focusable and reveals on focus', async ({ page }) => {
    const finance = ROLES.find((r) => r.identity === 'merchant_finance')!;
    await stubBootstrap(page, finance);
    await page.goto(finance.path);
    const skip = page.getByRole('link', { name: 'Skip to main content' });
    await skip.focus();
    await expect(skip).toBeFocused();
    // Skip link is visually revealed when focused (not-sr-only).
    await expect(skip).toBeVisible();
  });

  test('mobile drawer opens, is keyboard operable, and returns focus on close', async ({ page }) => {
    const finance = ROLES.find((r) => r.identity === 'merchant_finance')!;
    await stubBootstrap(page, finance);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto(finance.path);

    const trigger = page.locator('[data-testid="nav-drawer-trigger"]');
    await expect(trigger).toBeVisible();
    await trigger.click();
    await expect(page.locator('#role-nav-drawer')).toBeVisible();

    // Esc closes and focus returns to the trigger.
    await page.keyboard.press('Escape');
    await expect(page.locator('#role-nav-drawer')).toHaveCount(0);
    const focused = await page.evaluate(
      () => document.activeElement?.getAttribute('data-testid') ?? '',
    );
    expect(focused).toBe('nav-drawer-trigger');
  });
});
