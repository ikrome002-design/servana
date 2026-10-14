import { expect, test, type Page } from '@playwright/test';
import { readyLocator, type AuditScreen } from './support/releaseAudit';
import { liveContractPages, prepareVisual, type ContractPage } from './support/ui16Visual';

/**
 * Phase UI-16 — reviewed visual baselines (UI/UX plan §25 UI-16 "Visual baselines reviewed", §21).
 *
 * A curated, deterministic set — not every page in every state — chosen so each archetype the
 * UI-16 work changed has a guarded frame: the eight account dashboards (light, dark, mobile), the
 * five older accounts' representative list/form pages, the account identity page, the Super
 * Administrator header strip, and the eight public landing heroes.
 *
 * Determinism: the release-audit harness pins the Nairobi clock and every identifier; fonts are
 * self-hosted and awaited; animations and the caret are disabled. Baselines are per platform
 * (`-{platform}` suffix): the Windows set is reviewed locally, the Linux set is produced in the
 * pinned `mcr.microsoft.com/playwright` image that matches CI's Chromium.
 *
 * Baselines are NEVER mass-updated: `docs/frontend/audits/ui-16/visual-baseline-index.json`
 * records each frame's hash, provenance and reviewer decision, and the negative control below
 * proves a perturbed frame fails.
 */

const LIVE = liveContractPages();
const byKey = (key: string): ContractPage => {
  const page = LIVE.find((p) => p.contractKey === key);
  if (!page) throw new Error(`UI-16 baseline: no live page ${key}`);
  return page;
};

const DASHBOARDS = [
  'super_administrator.dashboard', 'merchant_administrator.dashboard', 'merchant_branch.dashboard',
  'merchant_human_resource.dashboard', 'merchant_finance.dashboard', 'merchant_front_office.dashboard',
  'merchant_personnel.dashboard', 'merchant_audit.dashboard',
];

const OLDER_PRIORITY = [
  'super_administrator.billing-promotions', 'super_administrator.merchants', 'merchant_administrator.branches',
  'merchant_administrator.get-started', 'merchant_branch.services', 'merchant_human_resource.staff',
  'merchant_finance.payments-validations', 'merchant_finance.audit', 'merchant_finance.settings',
];

const ACCOUNTS = [
  'merchant_administrator', 'super_administrator', 'merchant_branch', 'merchant_finance',
  'merchant_human_resource', 'merchant_front_office', 'merchant_personnel', 'merchant_audit',
] as const;

const SCREENSHOT = { animations: 'disabled', caret: 'hide', maxDiffPixelRatio: 0.015 } as const;

async function settle(page: Page, screen: AuditScreen): Promise<void> {
  await expect(page.locator(readyLocator(screen)).first()).toBeVisible({ timeout: 15_000 });
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
}

async function openForBaseline(page: Page, entry: ContractPage, theme: 'light' | 'dark', width: number, height: number): Promise<void> {
  await page.setViewportSize({ width, height });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await prepareVisual(page, entry.screen, theme);
  await page.goto(entry.screen.path);
  await settle(page, entry.screen);
}

test.describe('UI-16 visual baselines — dashboards', () => {
  for (const key of DASHBOARDS) {
    for (const variant of [
      { name: 'desktop-light', theme: 'light', width: 1440, height: 900 },
      { name: 'desktop-dark', theme: 'dark', width: 1440, height: 900 },
      { name: 'mobile-light', theme: 'light', width: 360, height: 780 },
    ] as const) {
      test(`${key} ${variant.name}`, async ({ page }) => {
        await openForBaseline(page, byKey(key), variant.theme, variant.width, variant.height);
        await expect(page).toHaveScreenshot(`${key}--${variant.name}.png`, SCREENSHOT);
      });
    }
  }
});

test.describe('UI-16 visual baselines — older-account priority pages', () => {
  for (const key of OLDER_PRIORITY) {
    for (const variant of [
      { name: 'desktop-light', theme: 'light', width: 1440, height: 900 },
      { name: 'tablet-dark', theme: 'dark', width: 768, height: 1024 },
    ] as const) {
      test(`${key} ${variant.name}`, async ({ page }) => {
        await openForBaseline(page, byKey(key), variant.theme, variant.width, variant.height);
        await expect(page).toHaveScreenshot(`${key}--${variant.name}.png`, SCREENSHOT);
      });
    }
  }
});

test.describe('UI-16 visual baselines — shell archetypes', () => {
  test('Super Administrator header strip with an open group, desktop', async ({ page }) => {
    await openForBaseline(page, byKey('super_administrator.dashboard'), 'light', 1440, 900);
    await page.getByTestId('nav-group-trigger-nav-group-billing-commercial').click();
    // The open disclosure panel extends below the header box, so the frame is the top of the page.
    await expect(page).toHaveScreenshot('shell--super-administrator-header-open.png', { ...SCREENSHOT, clip: { x: 0, y: 0, width: 1440, height: 480 } });
  });

  test('left navigation with compact gated entries, desktop', async ({ page }) => {
    await openForBaseline(page, byKey('merchant_administrator.dashboard'), 'light', 1440, 900);
    await expect(page.getByTestId('sidebar-primary-nav')).toHaveScreenshot('shell--merchant-administrator-sidebar.png', SCREENSHOT);
  });

  test('mobile navigation drawer, dark', async ({ page }) => {
    await openForBaseline(page, byKey('merchant_finance.dashboard'), 'dark', 360, 780);
    await page.getByTestId('nav-drawer-trigger').click();
    await expect(page.getByRole('dialog', { name: 'Navigation' })).toHaveScreenshot('shell--finance-drawer-dark.png', SCREENSHOT);
  });
});

test.describe('UI-16 visual baselines — public landing heroes', () => {
  for (const account of ACCOUNTS) {
    test(`landing hero ${account}`, async ({ page }) => {
      const screen: AuditScreen = { key: 'home', route: 'home', path: '/', role: 'public', state: 'static', bootstrap: { hostAccountKey: account } };
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await prepareVisual(page, screen, 'light');
      await page.goto('/');
      await settle(page, screen);
      // Approved landing images are decoded before the frame is taken.
      await page.evaluate(async () => {
        await Promise.all(Array.from(document.images).filter((img) => img.loading !== 'lazy').map((img) => img.decode().catch(() => undefined)));
      });
      await expect(page).toHaveScreenshot(`landing--${account}.png`, SCREENSHOT);
    });
  }
});

test.describe('UI-16 visual regression negative control', () => {
  test('a perturbed frame is detected, not silently accepted', async ({ page }, testInfo) => {
    // While baselines are being (re)generated, Playwright would WRITE the perturbed frame as the new
    // baseline instead of comparing it — the one mode in which this control must not run.
    test.skip(testInfo.config.updateSnapshots === 'all' || testInfo.config.updateSnapshots === 'changed', 'baseline generation run');
    const key = 'merchant_finance.dashboard';
    await openForBaseline(page, byKey(key), 'light', 1440, 900);
    // Recolour the command surface — a change of the kind a careless global CSS edit would make.
    // COR-UI08-UI12-VIS-001: the Finance dashboard's command surface is now the refined feature panel
    // (`.sv-r-feature`); the UI-16 `.sv-operational-hero` no longer renders on this page, so the old
    // selector would perturb nothing and the control would fail for the wrong reason.
    await page.addStyleTag({ content: '.sv-r-feature { background: rgb(0 128 0) !important; }' });
    await expect(expect(page).toHaveScreenshot(`${key}--desktop-light.png`, { ...SCREENSHOT, timeout: 3_000 })).rejects.toThrow();
  });
});
