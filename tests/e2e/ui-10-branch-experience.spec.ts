import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { assertNoHorizontalScroll } from './support/roleBootstrap';
import { watchBrowserHealth } from './support/ui09Merchant';
import {
  GATED_PAGES,
  IMPLEMENTED_PAGES,
  NAVIGATION_GROUPS,
  stubBranch,
  stubBranchApi,
} from './support/ui10Branch';

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-10/screenshots');
mkdirSync(SHOTS, { recursive: true });

async function openBranch(page: Page, path: string, options: Parameters<typeof stubBranch>[1] = {}) {
  const health = watchBrowserHealth(page);
  await stubBranch(page, options);
  await stubBranchApi(page);
  await page.goto(path);
  return health;
}

async function heading(page: Page, expected: string, level = 1): Promise<void> {
  await expect(page.getByRole('heading', { level, name: expected })).toBeVisible();
}

async function shoot(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: join(SHOTS, `${name}.png`), animations: 'disabled' });
}

test.describe('all fifteen implemented Branch pages', () => {
  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} resolves real state at ${entry.path}`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openBranch(page, entry.path);
      await heading(page, entry.h1, entry.headingLevel ?? 1);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      await expect(page.getByTestId('branch-context')).toContainText('Westlands Studio');
      expect(health.apiRequests.some((url) => url.includes(entry.api)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.pageErrors, `${entry.screen} page errors`).toEqual([]);
      expect(health.consoleErrors, `${entry.screen} console errors`).toEqual([]);
      expect(health.failedRequests, `${entry.screen} failed requests`).toEqual([]);
      await shoot(page, `desktop-light-${entry.screen}`);
    });
  }
});

test.describe('three externally gated pages', () => {
  test('are discoverable, inert and truthful in grouped navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openBranch(page, '/dashboard');
    const nav = page.getByTestId('sidebar-primary-nav');
    for (const entry of GATED_PAGES) {
      const item = nav.getByTestId(`nav-stacked-gated-${entry.key}`);
      await expect(item).toBeVisible();
      await expect(item).toHaveAttribute('aria-disabled', 'true');
      await expect(item).toContainText('External Gate W');
      await expect(item).not.toHaveAttribute('href');
    }
    await shoot(page, 'navigation-gated-treatment');
  });

  for (const entry of GATED_PAGES) {
    test(`${entry.screen} has no live route or fake data`, async ({ page }) => {
      const health = await openBranch(page, entry.path);
      await expect(page.getByTestId('public-not-found')).toBeVisible();
      await expect(page.getByText(/coming soon|payment successful|0 notifications/i)).toHaveCount(0);
      expect(health.apiRequests.some((url) => /wallet|notification|reports/.test(url))).toBe(false);
    });
  }
});

test.describe('shell, authority and role boundaries', () => {
  test('uses the eight exact navigation groups and Branch context on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openBranch(page, '/dashboard');
    const nav = page.getByTestId('sidebar-primary-nav');
    for (const group of NAVIGATION_GROUPS) await expect(nav.getByText(group, { exact: true }).first()).toBeVisible();
    await expect(page.getByTestId('header-primary-nav')).toHaveCount(0);
    await expect(page.getByTestId('branch-context')).toContainText('Westlands Studio');
    await expect(page.locator('footer')).toBeVisible();
  });

  test('tablet rail and mobile focus-trapped drawer preserve grouped navigation', async ({ page }) => {
    for (const width of [768, 1024]) {
      await page.setViewportSize({ width, height: 900 });
      await openBranch(page, '/dashboard');
      const rail = page.getByTestId('tablet-navigation-rail');
      await expect(rail).toBeVisible();
      await page.getByTestId('tablet-navigation-toggle').click();
      await expect(rail.getByText('Branch Operations', { exact: true })).toBeVisible();
      await assertNoHorizontalScroll(page);
    }

    await page.setViewportSize({ width: 360, height: 800 });
    await openBranch(page, '/dashboard');
    const opener = page.getByTestId('nav-drawer-trigger').first();
    await opener.click();
    const drawer = page.getByRole('dialog', { name: 'Navigation' });
    await expect(page.getByTestId('nav-drawer-close')).toBeFocused();
    const last = drawer.locator('a[href], button:not([disabled]), [tabindex="0"]').last();
    await last.focus();
    await page.keyboard.press('Tab');
    await expect(page.getByTestId('nav-drawer-close')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(opener).toBeFocused();
  });

  test('wrong account host fails closed', async ({ page }) => {
    await openBranch(page, '/branch/profile', { accountKey: 'merchant_finance' });
    await expect(page.getByTestId('public-not-found')).toBeVisible();
    await expect(page.getByTestId('branch-dashboard')).toHaveCount(0);
  });

  test('missing Branch account fails closed', async ({ page }) => {
    await openBranch(page, '/dashboard', { accountKeys: [] });
    await expect(page).toHaveURL(/\/access-denied$/);
    await expect(page.getByRole('heading', { name: 'You do not have access to this page' })).toBeVisible();
    await expect(page.getByTestId('branch-dashboard')).toHaveCount(0);
  });

  test('financial views expose no Finance, Front Office or receipt-reissue controls', async ({ page }) => {
    for (const path of ['/finance/invoices', '/finance/payments', '/finance/receipts']) {
      await openBranch(page, path);
      await expect(page.getByRole('button', { name: /create|edit|finalize|void|validate|reject|correct|reissue/i })).toHaveCount(0);
    }
  });

  test('queue, appointments and staff expose no takeover controls or contact export', async ({ page }) => {
    for (const path of ['/operations/queue', '/operations/appointments', '/staff']) {
      await openBranch(page, path);
      await expect(page.getByRole('button', { name: /assign|transfer|reschedule|cancel|invite|suspend|export contact/i })).toHaveCount(0);
      await expect(page.locator('main')).not.toContainText('+254700000001');
    }
  });

  test('cash-up is maker-only and never exposes checker actions', async ({ page }) => {
    await openBranch(page, '/cash-up');
    await expect(page.getByRole('button', { name: /approve|reject|request correction|lock/i })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Expected' })).toBeVisible();
    await expect(page.getByRole('spinbutton', { name: 'Counted Cash' })).toBeVisible();
  });
});

test.describe('responsive, theme and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all Branch pages avoid normal horizontal page overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of IMPLEMENTED_PAGES) {
        await openBranch(page, entry.path);
        await heading(page, entry.h1, entry.headingLevel ?? 1);
        await assertNoHorizontalScroll(page);
      }
      await shoot(page, `responsive-${width}`);
    });
  }

  test('survives a 200% zoom-equivalent viewport and fixed footer remains clear', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 450 });
    await openBranch(page, '/finance/invoices');
    await assertNoHorizontalScroll(page);
    const footer = page.locator('footer');
    const lastCard = page.locator('main article').last();
    const [footerBox, cardBox, reserve] = await Promise.all([
      footer.boundingBox(),
      lastCard.boundingBox(),
      page.locator('.sv-footer-reserve').evaluate((root) => ({
        bottom: root.getBoundingClientRect().bottom,
        paddingBottom: Number.parseFloat(getComputedStyle(root).paddingBottom),
      })),
    ]);
    expect(footerBox && reserve.paddingBottom >= footerBox.height).toBe(true);
    expect(footerBox && cardBox && cardBox.y + cardBox.height + footerBox.height <= reserve.bottom).toBe(true);
  });

  test('defaults to light and persists an explicit dark preference', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await openBranch(page, '/dashboard');
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await shoot(page, 'dashboard-dark');
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
  });

  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} has zero serious or critical axe violations`, async ({ page }) => {
      await openBranch(page, entry.path);
      await heading(page, entry.h1, entry.headingLevel ?? 1);
      const results = await new AxeBuilder({ page }).analyze();
      const violations = results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical');
      expect(violations, violations.map((item) => `${item.id} (${item.nodes.length})`).join(', ')).toEqual([]);
    });
  }

  test('dark dashboard and open mobile drawer are axe-clean', async ({ page }) => {
    await openBranch(page, '/dashboard');
    await page.getByTestId('theme-toggle').first().click();
    let results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);

    await page.setViewportSize({ width: 360, height: 800 });
    await page.getByTestId('nav-drawer-trigger').first().click();
    results = await new AxeBuilder({ page }).analyze();
    expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
  });
});

test('writes the UI-10 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  expect(files.length, 'no UI-10 screenshots were captured').toBeGreaterThan(0);
  const byName = new Map(IMPLEMENTED_PAGES.map((page) => [page.screen, page]));
  const captures = files.map((file) => {
    const bytes = readFileSync(join(SHOTS, file));
    const screen = file.replace(/^desktop-light-/, '').replace(/\.png$/, '');
    const entry = byName.get(screen);
    return {
      file: `screenshots/${file}`,
      screen_key: entry?.screen ?? null,
      route: entry?.path ?? null,
      theme: file.includes('dark') ? 'dark' : 'light',
      bytes: statSync(join(SHOTS, file)).size,
      sha256: createHash('sha256').update(bytes).digest('hex'),
    };
  });
  writeFileSync(resolve(SHOTS, '..', 'screenshot-index.json'), `${JSON.stringify({
    schema: 'servana.ui10.screenshot-index.v1', phase: 'UI-10', account: 'merchant_branch',
    host: 'branch.servana.ke', data_provenance: 'synthetic; no real person, credential, token or provider payload',
    status: 'UI-10 implementation evidence; not a UI-16 release-approved visual baseline', captures,
    totals: { captures: files.length, implemented_page_captures: files.filter((file) => file.startsWith('desktop-light-')).length },
  }, null, 2)}\n`);
});
