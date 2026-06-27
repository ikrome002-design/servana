import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ROLES, stubBootstrap } from './support/roleBootstrap';

/*
 | Phase 11 per-feature accessibility gate (Plan §30). No serious/critical axe
 | violations on any role landing or get-started page, in light and dark themes.
 | (Whole-product audit is Phase 23.)
 */

async function axeClean(page: import('@playwright/test').Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  const serious = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  expect(serious, JSON.stringify(serious.map((v) => v.id))).toEqual([]);
}

for (const role of ROLES) {
  test.describe(`${role.identity} accessibility`, () => {
    for (const theme of ['light', 'dark'] as const) {
      test(`landing is axe-clean (${theme})`, async ({ page }) => {
        await stubBootstrap(page, role);
        await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);
        await page.goto(role.path);
        await expect(page.locator('#main-content')).toBeVisible();
        await axeClean(page);
      });

      test(`get-started is axe-clean (${theme})`, async ({ page }) => {
        await stubBootstrap(page, role);
        await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);
        await page.goto(`${role.path}/get-started`);
        await axeClean(page);
      });
    }
  });
}
