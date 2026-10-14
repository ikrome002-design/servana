import { existsSync } from 'node:fs';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { choosePopulatedSubject, stubPopulated } from './support/corPopulated';
import { assertAxeClean, assertNoHorizontalOverflow, readyLocator } from './support/releaseAudit';
import { liveContractPages, prepareVisual, type ContractPage } from './support/ui16Visual';

/**
 * COR-UI08-UI12-VIS-001 — reviewed visual baselines for the corrective redesign of the 82 live
 * UI-08 … UI-12 pages.
 *
 * The UI-16 set already guards the five dashboards; this set adds one representative frame per
 * redesigned ARCHETYPE (ledger, lifecycle board, verdict desk, reconciliation sheet, statement,
 * timeline, catalogue, roster, settings, governance detail) with the synthetic populated overlay
 * (support/corPopulated.ts), plus dark and mobile frames of the densest compositions.
 *
 * Two guards travel with the frames:
 *  - the experience SCOPE: the refined experience is applied to exactly the five corrective
 *    accounts and never to Front Office, Personnel or Audit (whose UI-13 … UI-15 presentation the
 *    product owner did not ask to change);
 *  - a NEGATIVE CONTROL proving a perturbed frame fails rather than being silently accepted.
 *
 * Baselines are per platform (`-{platform}` suffix) and are never mass-updated: the review index in
 * docs/frontend/audits/cor-ui08-ui12-vis-001/visual-baseline-index.json records each one.
 */

const LIVE = liveContractPages();
const byKey = (key: string): ContractPage => {
  const page = LIVE.find((p) => p.contractKey === key);
  if (!page) throw new Error(`COR-UI08-UI12-VIS-001 baseline: no live page ${key}`);
  return page;
};

const SCREENSHOT = { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.015 } as const;
const CORRECTIVE = new Set(['super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource', 'merchant_finance']);

/** One frame per redesigned archetype, desktop light. */
const ARCHETYPES = [
  'super_administrator.billing-settings',
  'super_administrator.merchant-detail',
  'super_administrator.feature-flags',
  'merchant_administrator.subscription-invoice-detail',
  'merchant_administrator.compensation',
  'merchant_branch.services',
  'merchant_branch.operations-queue',
  'merchant_human_resource.eligibility',
  'merchant_human_resource.compensation-history',
  'merchant_finance.payments-validation-detail',
  'merchant_finance.cash-up',
  'merchant_finance.disputes',
  'merchant_finance.compensation-liabilities',
];

/** The densest compositions, in dark and at the 360px phone width. */
const DENSE = ['merchant_finance.payments-duplicates', 'merchant_human_resource.availability', 'super_administrator.billing-subscriptions'];

async function attachMissingFrame(page: Page, filename: string, testInfo: TestInfo): Promise<void> {
  if (!existsSync(testInfo.snapshotPath(filename, { kind: 'screenshot' }))) {
    const path = testInfo.outputPath(`review-${filename}`);
    await page.screenshot({ path, animations: 'disabled', caret: 'hide', fullPage: false });
    await testInfo.attach(filename, { path, contentType: 'image/png' });
  }
}

async function open(page: Page, entry: ContractPage, theme: 'light' | 'dark', width: number, height: number): Promise<void> {
  await page.setViewportSize({ width, height });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await prepareVisual(page, entry.screen, theme);
  await stubPopulated(page, entry.screen);
  await page.goto(entry.screen.path);
  await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible({ timeout: 15_000 });
  await page.waitForLoadState('networkidle');
  await choosePopulatedSubject(page, entry.screen);
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
}

test.describe('COR-UI08-UI12-VIS-001 baselines — archetypes', () => {
  for (const key of ARCHETYPES) {
    test(`${key} desktop-light`, async ({ page }, testInfo) => {
      await open(page, byKey(key), 'light', 1440, 900);
      const filename = `${key}--desktop-light.png`;
      await attachMissingFrame(page, filename, testInfo);
      await expect(page).toHaveScreenshot(filename, SCREENSHOT);
    });
  }
});

test.describe('COR-UI08-UI12-VIS-001 baselines — dense compositions', () => {
  for (const key of DENSE) {
    for (const variant of [
      { name: 'desktop-dark', theme: 'dark', width: 1440, height: 900 },
      { name: 'mobile-light', theme: 'light', width: 360, height: 780 },
    ] as const) {
      test(`${key} ${variant.name}`, async ({ page }, testInfo) => {
        await open(page, byKey(key), variant.theme, variant.width, variant.height);
        const filename = `${key}--${variant.name}.png`;
        await attachMissingFrame(page, filename, testInfo);
        await expect(page).toHaveScreenshot(filename, SCREENSHOT);
      });
    }
  }
});

test.describe('COR-UI08-UI12-VIS-001 baselines — overlays', () => {
  test('whole-group decision dialog over the verdict desk, desktop', async ({ page }, testInfo) => {
    await open(page, byKey('merchant_finance.payments-validation-detail'), 'light', 1440, 900);
    await page.getByTestId('validate-open').click();
    const dialog = page.getByRole('dialog', { name: 'Validate this payment group' });
    await expect(dialog).toBeVisible();
    // The scope lives on <html>, so the teleported sheet carries the refined treatment.
    await expect(page.locator('html')).toHaveAttribute('data-sv-experience', 'refined');
    const filename = 'overlay--finance-decision-dialog.png';
    await attachMissingFrame(page, filename, testInfo);
    await expect(page).toHaveScreenshot(filename, SCREENSHOT);
  });
});

test.describe('COR-UI08-UI12-VIS-001 accessibility — populated compositions', () => {
  // The UI-16 sweep audits the default (often empty) fixtures; the redesigned ledgers, boards,
  // definition lists and timelines only render with records, so they are audited here too.
  for (const entry of LIVE.filter((p) => CORRECTIVE.has(p.account))) {
    test(`axe populated: ${entry.contractKey}`, async ({ page }) => {
      for (const variant of [
        { theme: 'light', width: 1440, height: 900 },
        { theme: 'dark', width: 360, height: 800 },
      ] as const) {
        await open(page, entry, variant.theme, variant.width, variant.height);
        await assertAxeClean(page, `${entry.contractKey} populated (${variant.theme}, ${variant.width})`);
      }
    });
  }
});

test.describe('COR-UI08-UI12-VIS-001 experience scope', () => {
  // Every account's dashboard: the five corrective accounts carry the refined experience, the other
  // three never do. A shared-shell regression that leaked the redesign would fail here.
  for (const entry of LIVE.filter((p) => p.contractKey.endsWith('.dashboard'))) {
    test(`${entry.account} experience`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await prepareVisual(page, entry.screen, 'light');
      await page.goto(entry.screen.path);
      await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible({ timeout: 15_000 });
      const html = page.locator('html');
      if (CORRECTIVE.has(entry.account)) await expect(html).toHaveAttribute('data-sv-experience', 'refined');
      else await expect(html).not.toHaveAttribute('data-sv-experience', /.+/);
    });
  }
});

test.describe('COR-UI08-UI12-VIS-001 visual regression negative control', () => {
  test('a perturbed redesigned frame is detected, not silently accepted', async ({ page }, testInfo) => {
    // While baselines are being (re)generated Playwright would WRITE the perturbed frame as the new
    // baseline instead of comparing it — the one mode in which this control must not run.
    test.skip(testInfo.config.updateSnapshots === 'all' || testInfo.config.updateSnapshots === 'changed', 'baseline generation run');
    const key = 'merchant_finance.cash-up';
    await open(page, byKey(key), 'light', 1440, 900);
    // Drop the refined panel radius and recolour the ledger — the kind of change a careless edit to
    // the shared kit would make.
    await page.addStyleTag({ content: '.sv-r-ledger { border-radius: 0 !important; background: rgb(0 128 0) !important; }' });
    await expect(expect(page).toHaveScreenshot(`${key}--desktop-light.png`, { ...SCREENSHOT, timeout: 3_000 })).rejects.toThrow();
  });
});


for (const theme of ['light', 'dark'] as const) {
  test('populated compensation terms remain readable at every required width and 200% equivalent: ' + theme, async ({ page }) => {
    await open(page, byKey('merchant_human_resource.compensation-detail'), theme, 1440, 900);
    const values = page.getByTestId('hr-compensation-detail').locator('dl').first().locator('dd');
    await expect(values).toHaveCount(5);
    for (const width of [360, 767, 768, 1024, 1025, 1280, 1440, 640]) {
      await page.setViewportSize({ width, height: width === 640 ? 400 : 900 });
      await assertNoHorizontalOverflow(page, 'populated compensation terms ' + theme + ' ' + width);
      for (const value of await values.all()) {
        const bounds = await value.evaluate((element) => {
          const box = element.getBoundingClientRect();
          const panel = element.closest('article')!.getBoundingClientRect();
          return { client: element.clientWidth, scroll: element.scrollWidth, left: box.left, right: box.right, panelLeft: panel.left, panelRight: panel.right };
        });
        expect(bounds.client, 'term has a readable content width').toBeGreaterThan(0);
        expect(bounds.scroll, 'term text is not clipped horizontally at ' + width).toBeLessThanOrEqual(bounds.client + 1);
        expect(bounds.left).toBeGreaterThanOrEqual(bounds.panelLeft);
        expect(bounds.right).toBeLessThanOrEqual(bounds.panelRight + 1);
        expect(bounds.right).toBeLessThanOrEqual(width + 1);
      }
    }
  });
}
