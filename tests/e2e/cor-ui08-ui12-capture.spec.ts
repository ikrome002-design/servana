import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { choosePopulatedSubject, stubPopulated } from './support/corPopulated';
import { readyLocator } from './support/releaseAudit';
import { liveContractPages, prepareVisual } from './support/ui16Visual';

/**
 * COR-UI08-UI12-VIS-001 — evidence capture for the corrective visual redesign.
 *
 * This is an evidence WRITER, not a gate: it only runs when `COR_CAPTURE` names the capture set
 * (`before` or `after`), so the ordinary suite skips it. The regression gate for the redesign is
 * `cor-ui08-ui12-visual-regression.spec.ts`.
 *
 * It drives the REAL built frontend through the same deterministic UI-16 harness (pinned Nairobi
 * clock, fixed identifiers, synthetic fixtures), so a before frame and its after frame differ only
 * in the source under test.
 */

const SET = process.env['COR_CAPTURE'];
const ACCOUNTS = new Set(['super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource', 'merchant_finance']);
const ONLY = process.env['COR_ONLY']?.split(',').filter(Boolean);
/** Optional comma-separated variant filter (e.g. `desktop-light`) for a quick partial pass. */
const ONLY_VARIANTS = process.env['COR_VARIANTS']?.split(',').filter(Boolean);
const OUT = join('docs', 'frontend', 'audits', 'cor-ui08-ui12-vis-001', 'screenshots');

const VARIANTS = {
  before: [{ name: 'desktop-light', theme: 'light', width: 1440, height: 900 }],
  after: [
    { name: 'desktop-light', theme: 'light', width: 1440, height: 900 },
    { name: 'desktop-dark', theme: 'dark', width: 1440, height: 900 },
    { name: 'mobile-light', theme: 'light', width: 360, height: 800 },
  ],
  // The redesigned compositions with the synthetic populated overlay (support/corPopulated.ts), for
  // the pages whose deterministic fixtures render an empty state.
  populated: [
    { name: 'desktop-light', theme: 'light', width: 1440, height: 900 },
    { name: 'desktop-dark', theme: 'dark', width: 1440, height: 900 },
    { name: 'mobile-light', theme: 'light', width: 360, height: 800 },
  ],
} as const;

const PAGES = liveContractPages()
  .filter((p) => ACCOUNTS.has(p.account))
  .filter((p) => !ONLY || ONLY.some((o) => p.contractKey.startsWith(o)));

test.describe('COR-UI08-UI12-VIS-001 evidence capture', () => {
  test.skip(SET !== 'before' && SET !== 'after' && SET !== 'populated', 'evidence capture runs only when COR_CAPTURE is set');

  test('the corrective scope is exactly the 82 live UI-08 … UI-12 pages', () => {
    test.skip(ONLY !== undefined, 'partial capture');
    expect(PAGES).toHaveLength(82);
  });

  for (const entry of PAGES) {
    test(entry.contractKey, async ({ page }) => {
      const variants = VARIANTS[(SET ?? 'before') as keyof typeof VARIANTS]
        .filter((v) => !ONLY_VARIANTS || ONLY_VARIANTS.includes(v.name));
      const index: Array<Record<string, unknown>> = [];
      for (const v of variants) {
        await page.setViewportSize({ width: v.width, height: v.height });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await prepareVisual(page, entry.screen, v.theme);
        if (SET === 'populated') await stubPopulated(page, entry.screen);
        await page.goto(entry.screen.path);
        await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible({ timeout: 15_000 });
        await page.waitForLoadState('networkidle');
        if (SET === 'populated') {
          await choosePopulatedSubject(page, entry.screen);
          await page.waitForLoadState('networkidle');
        }
        await page.evaluate(() => document.fonts.ready);
        const dir = join(OUT, SET!, v.name);
        mkdirSync(dir, { recursive: true });
        const file = join(dir, `${entry.contractKey}.png`);
        // A full-page screenshot paints the fixed footer at the first viewport's bottom edge, over
        // the content. Growing the viewport to the document height renders the page as a reader
        // scrolling to its end would see it, with the footer at the true bottom.
        const fullHeight = await page.evaluate(() => document.documentElement.scrollHeight);
        await page.setViewportSize({ width: v.width, height: Math.min(Math.max(v.height, fullHeight), 6000) });
        await page.waitForTimeout(150);
        // Whether the page rendered its genuine empty state with the deterministic fixtures — recorded
        // so the review can tell an empty composition from a populated one.
        const emptyState = await page.locator('.sv-state--empty').count() > 0;
        const png = await page.screenshot({ animations: 'disabled', caret: 'hide' });
        writeFileSync(file, png);
        index.push({
          contract_key: entry.contractKey,
          variant: v.name,
          viewport: `${v.width}x${v.height}`,
          theme: v.theme,
          empty_state: emptyState,
          route: entry.routePath,
          path: file.replaceAll('\\', '/'),
          sha256: createHash('sha256').update(readFileSync(file)).digest('hex'),
        });
      }
      const ledger = join(OUT, SET!, 'index.jsonl');
      writeFileSync(ledger, index.map((r) => JSON.stringify(r)).join('\n') + '\n', { flag: 'a' });
    });
  }
});
