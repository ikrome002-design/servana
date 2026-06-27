import { expect, test } from '@playwright/test';
import { ROLES, assertNoHorizontalScroll, stubBootstrap } from './support/roleBootstrap';

/*
 | Phase 11 per-feature responsive gate (Plan §28). Every role foundation screen
 | is free of whole-page horizontal overflow and its navigation is operable at
 | 360 / 768 / 1280. (Release-wide audit is Phase 23.)
 */

const WIDTHS = [360, 768, 1280];

for (const role of ROLES) {
  test.describe(`${role.identity} responsive`, () => {
    for (const width of WIDTHS) {
      test(`landing has no horizontal overflow at ${width}px`, async ({ page }) => {
        await stubBootstrap(page, role);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(role.path);
        await expect(page.locator('#main-content')).toBeVisible();
        await assertNoHorizontalScroll(page);
      });

      test(`get-started has no horizontal overflow at ${width}px`, async ({ page }) => {
        await stubBootstrap(page, role);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${role.path}/get-started`);
        await assertNoHorizontalScroll(page);
      });
    }

    test('navigation is operable at mobile and desktop widths', async ({ page }) => {
      await stubBootstrap(page, role);

      await page.setViewportSize({ width: 360, height: 800 });
      await page.goto(role.path);
      // A drawer trigger is available at mobile width for every role.
      await expect(page.locator('[data-testid="nav-drawer-trigger"]')).toBeVisible();

      await page.setViewportSize({ width: 1280, height: 800 });
      await page.goto(role.path);
      if (role.identity === 'super_administrator') {
        await expect(page.locator('[data-testid="header-primary-nav"]')).toBeVisible();
      } else {
        await expect(page.locator('[data-testid="sidebar-primary-nav"]')).toBeVisible();
      }
    });
  });
}
