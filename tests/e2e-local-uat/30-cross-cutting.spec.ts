/**
 * LOCAL-UAT-001 §7D — cross-cutting browser checks on the REAL local stack, for all eight accounts:
 *
 *   - the public landing `/` is the first page and opens in LIGHT mode regardless of the operating
 *     system preference (ADR-021: prefers-color-scheme never selects the theme);
 *   - no horizontal overflow and a visible footer at 360/767/768/1024/1025/1280/1440 px and at 640 px
 *     (a 1280 px window at 200% zoom), on the landing page and the authenticated dashboard;
 *   - dark mode is an explicit, persisted choice (survives a reload for the signed-in user) and is
 *     returned to light afterwards so the automation identity is left as it was;
 *   - axe: zero serious/critical WCAG 2.0/2.1 A/AA findings on `/`, `/login` and `/dashboard`, in
 *     light and in dark.
 *
 * Manual screen-reader review (NVDA/VoiceOver) is NOT covered here and stays with the owner/reviewer.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import { ACCOUNT_KEYS, ACCOUNTS, appendResult, origin, statePath } from './support/localUat';

const WIDTHS = [360, 767, 768, 1024, 1025, 1280, 1440, 640];

async function layoutAt(page: Page, width: number): Promise<{ overflow: number; footerVisible: boolean; headingVisible: boolean }> {
  await page.setViewportSize({ width, height: 900 });
  await page.waitForTimeout(150);

  return page.evaluate(() => {
    const footer = document.querySelector('footer');
    const rect = footer?.getBoundingClientRect();
    const heading = document.querySelector('h1');

    return {
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      footerVisible: !!rect && rect.height > 0 && rect.top < window.innerHeight,
      headingVisible: !!heading && heading.getBoundingClientRect().width > 0,
    };
  });
}

async function axeSeriousCritical(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id} (${v.nodes.length})`);
}

for (const account of ACCOUNT_KEYS) {
  test(`${account}: responsive, theme and accessibility on ${ACCOUNTS[account].host}`, async ({ browser }) => {
    test.setTimeout(240_000);
    const base = origin(account);
    const record: Record<string, unknown> = { account };

    // Public landing first, anonymous, with the OS asking for dark: the page must still open light.
    const anonymous = await browser.newContext({ colorScheme: 'dark' });
    const pub = await anonymous.newPage();
    await pub.goto(`${base}/`);
    await pub.waitForLoadState('networkidle');
    record['landing_default_light'] = !(await pub.evaluate(() => document.documentElement.classList.contains('dark')));
    expect(record['landing_default_light'], 'light is the default; prefers-color-scheme never selects the theme').toBe(true);

    const landingLayout: Record<number, unknown> = {};
    for (const width of WIDTHS) {
      const l = await layoutAt(pub, width);
      landingLayout[width] = l;
      expect(l.overflow, `landing horizontal overflow at ${width}px`).toBeLessThanOrEqual(1);
      expect(l.headingVisible, `landing heading at ${width}px`).toBe(true);
    }
    record['landing_layout'] = landingLayout;

    await pub.setViewportSize({ width: 1280, height: 900 });
    record['axe_landing_light'] = await axeSeriousCritical(pub);
    await pub.goto(`${base}/login`);
    await pub.waitForLoadState('networkidle');
    record['axe_login_light'] = await axeSeriousCritical(pub);
    await anonymous.close();

    // Authenticated dashboard.
    const context = await browser.newContext({ storageState: statePath(account) });
    const page = await context.newPage();
    await page.goto(`${base}/dashboard`);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/dashboard$/);

    const dashboardLayout: Record<number, unknown> = {};
    for (const width of WIDTHS) {
      const l = await layoutAt(page, width);
      dashboardLayout[width] = l;
      expect(l.overflow, `dashboard horizontal overflow at ${width}px`).toBeLessThanOrEqual(1);
      expect(l.footerVisible, `dashboard fixed footer at ${width}px`).toBe(true);
      expect(l.headingVisible, `dashboard heading at ${width}px`).toBe(true);
    }
    record['dashboard_layout'] = dashboardLayout;

    await page.setViewportSize({ width: 1280, height: 900 });
    record['axe_dashboard_light'] = await axeSeriousCritical(page);

    // Explicit dark mode, persisted to the user record: it survives a reload.
    const toggle = page.getByTestId('theme-toggle').first();
    await toggle.click();
    await expect.poll(() => page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);
    await page.waitForLoadState('networkidle');
    await page.reload();
    await page.waitForLoadState('networkidle');
    record['dark_persists_after_reload'] = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    record['axe_dashboard_dark'] = await axeSeriousCritical(page);

    // Return the automation identity to light.
    await page.getByTestId('theme-toggle').first().click();
    await expect.poll(() => page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(false);
    await page.waitForLoadState('networkidle');
    await context.close();

    const axe = ['axe_landing_light', 'axe_login_light', 'axe_dashboard_light', 'axe_dashboard_dark'].flatMap((k) => record[k] as string[]);
    record['result'] = record['dark_persists_after_reload'] === true && axe.length === 0 ? 'pass' : 'fail';
    appendResult('cross-cutting', record);

    expect(record['dark_persists_after_reload'], 'dark mode persists for the signed-in user').toBe(true);
    expect(axe, 'axe serious/critical findings').toEqual([]);
  });
}
