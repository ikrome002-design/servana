import { existsSync } from 'node:fs';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { contractRows, liveContractPages, prepareVisual } from './support/ui16Visual';
import { assertAxeClean, assertNoHorizontalOverflow, readyLocator, type AuditScreen } from './support/releaseAudit';

// UI/UX plan §21.7: the dashboard baselines do not cover legal, form, state-family
// or responsive record-card surfaces. These are additional frames, never replacements.
const screenshot = { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.015 } as const;

// With update-snapshots=none, Playwright rejects a missing baseline before writing an actual PNG.
// Preserve a review capture without turning it into an expectation or changing the failed assertion.
async function attachMissingFrame(target: Page | Locator, filename: string, testInfo: TestInfo): Promise<void> {
  if (!existsSync(testInfo.snapshotPath(filename, { kind: 'screenshot' }))) {
    const path = testInfo.outputPath(`review-${filename}`);
    await target.screenshot({ path, animations: 'disabled', caret: 'hide' });
    await testInfo.attach(filename, {
      path,
      contentType: 'image/png',
    });
  }
}

for (const theme of ['light', 'dark'] as const) {
  for (const surface of ['legal', 'form', 'states'] as const) {
    test(`${surface} ${theme}`, async ({ page }, testInfo) => {
      const screen: AuditScreen = {
        key: 'home', route: 'home', path: '/', role: 'public', state: 'static',
        bootstrap: { hostAccountKey: 'merchant_finance' },
      };
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await prepareVisual(page, screen, theme);
      const path = surface === 'legal' ? '/legal/privacy-policy'
        : surface === 'form' ? '/login' : '/dev/design-system';
      await page.goto(path);
      await page.evaluate(() => document.fonts.ready);
      await expect(page.locator('html')).toHaveClass(theme === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/);
      if (surface === 'states') {
        const states = page.getByTestId('visual-state-family');
        await expect(states.getByTestId('sv-locked-state')).toBeVisible();
        await expect(states.getByTestId('sv-permission-state')).toBeVisible();
        await expect(states.getByRole('status', { name: 'Loading…' })).toBeVisible();
        await expect(states.getByRole('alert')).toBeVisible();
        await expect(states.getByText('No records found.')).toBeVisible();
        const filename = `states--${theme}.png`;
        await attachMissingFrame(states, filename, testInfo);
        await expect(states).toHaveScreenshot(filename, screenshot);
      } else {
        await expect(page.getByRole('heading', { name: surface === 'legal' ? 'Privacy Policy' : 'Sign in to Servana', exact: true })).toBeVisible();
        if (surface === 'form') await expect(page.getByRole('textbox')).toBeVisible();
        await expect(page.getByTestId('sv-fixed-footer')).toBeVisible();
        const filename = `${surface}--${theme}.png`;
        await attachMissingFrame(page, filename, testInfo);
        await expect(page).toHaveScreenshot(filename, screenshot);
      }
    });
  }

  test(`responsive record cards ${theme}`, async ({ page }, testInfo) => {
    const entry = liveContractPages().find((p) => p.contractKey === 'merchant_administrator.branches')!;
    await page.setViewportSize({ width: 360, height: 780 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await prepareVisual(page, entry.screen, theme);
    await page.goto(entry.screen.path);
    await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
    const card = page.locator('article').first();
    await expect(card).toBeVisible();
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
    const cardBottom = await card.evaluate((element) => element.getBoundingClientRect().bottom);
    const footerTop = await page.getByTestId('sv-fixed-footer').evaluate((element) => element.getBoundingClientRect().top);
    expect(cardBottom).toBeLessThanOrEqual(footerTop);
    const filename = `record-card-detail--${theme}.png`;
    await attachMissingFrame(card, filename, testInfo);
    await expect(card).toHaveScreenshot(filename, screenshot);
  });
}

for (const account of [...new Set(contractRows().map((row) => row.account))]) {
  for (const theme of ['light', 'dark'] as const) {
    test(`authentication footer ${account} ${theme}`, async ({ page }) => {
      await prepareVisual(page, {
        key: 'home', route: 'home', path: '/', role: 'public', state: 'static',
        bootstrap: { hostAccountKey: account },
      }, theme);
      await page.goto('/login');
      await expect(page.getByRole('link', { name: 'Create a business account' })).toHaveCount(account === 'merchant_administrator' ? 1 : 0);
      const footer = page.getByTestId('sv-fixed-footer');
      await expect(footer).toBeVisible();
      await expect(footer.getByTestId('sv-footer-privacy-policy')).toHaveAttribute('href', '/legal/privacy-policy');
      await assertAxeClean(page, `authentication ${account} ${theme}`);
      for (const width of [360, 767, 768, 1024, 1025, 1280, 1440, 640]) {
        await page.setViewportSize({ width, height: width === 640 ? 400 : 900 });
        await assertNoHorizontalOverflow(page, `authentication ${account} ${theme} ${width}`);
        const layout = await page.evaluate(() => {
          const footer = document.querySelector('.sv-fixed-footer')!;
          const reserve = document.querySelector('.sv-footer-reserve')!;
          window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' });
          return {
            reserve: parseFloat(getComputedStyle(reserve).paddingBottom),
            footer: footer.getBoundingClientRect().height,
            actionBottom: document.querySelector('button[type="submit"]')!.getBoundingClientRect().bottom,
            footerTop: footer.getBoundingClientRect().top,
          };
        });
        expect(layout.reserve + 0.5).toBeGreaterThanOrEqual(layout.footer);
        expect(layout.actionBottom).toBeLessThanOrEqual(layout.footerTop);
      }
    });
  }
}
