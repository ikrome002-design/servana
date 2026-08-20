import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { assertNoHorizontalScroll } from './support/roleBootstrap';
import {
  FOREIGN_BRANCH_ID,
  GATED_PAGES,
  IMPLEMENTED_PAGES,
  INVOICE_ID,
  NAVIGATION_GROUPS,
  stubMerchant,
  stubMerchantApi,
  watchBrowserHealth,
} from './support/ui09Merchant';

/**
 * Phase UI-09 Increment 10 — Merchant Administrator browser evidence.
 *
 * The built SPA, real router, account guard, grouped left navigation, Pinia stores and page
 * components are exercised with synthetic API fixtures. Laravel feature tests prove the genuine
 * policies and tenant scopes; this suite proves the owner experience and that gated or forbidden
 * product capabilities never become live controls.
 */

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-09/screenshots');
mkdirSync(SHOTS, { recursive: true });

const notFound = (page: Page) => page.getByTestId('public-not-found');

async function shoot(page: Page, name: string): Promise<void> {
  await writeEvidenceScreenshot(page, join(SHOTS, `${name}.png`), { fullPage: true, animations: 'disabled' });
}

async function openMerchant(page: Page, path: string, options: Parameters<typeof stubMerchant>[1] = {}) {
  const health = watchBrowserHealth(page);
  await stubMerchant(page, options);
  await stubMerchantApi(page);
  await page.goto(path);
  return health;
}

async function expectPageHeading(page: Page, screen: string, expected: string): Promise<void> {
  if (screen === 'dashboard') {
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Welcome, Amina Owner');
    return;
  }
  if (screen === 'get-started') {
    await expect(page.getByRole('heading', { level: 2, name: expected })).toBeVisible();
    return;
  }
  await expect(page.getByRole('heading', { level: 1, name: expected })).toBeVisible();
}

test.describe('All fifteen implemented contract pages', () => {
  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} renders real state at ${entry.path}`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openMerchant(page, entry.path, { setupRequired: entry.screen === 'setup' });
      await expectPageHeading(page, entry.screen, entry.h1);
      await expect(notFound(page)).toHaveCount(0);
      await expect(page).toHaveURL(new RegExp(`${entry.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\?|#|$)`));

      const reads = health.apiRequests.filter((url) => !url.endsWith('/api/v1/me'));
      expect(reads.some((url) => url.includes(entry.api)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.pageErrors, `${entry.screen} page errors`).toEqual([]);
      expect(health.consoleErrors, `${entry.screen} console errors`).toEqual([]);
      expect(health.failedRequests, `${entry.screen} failed requests`).toEqual([]);
      await shoot(page, `desktop-light-${entry.screen}`);
    });
  }

  test('loading failure and empty data remain explicit states', async ({ page }) => {
    await stubMerchant(page);
    await stubMerchantApi(page);
    await page.route(/\/api\/v1\/merchant\/staff-overview/, (route) => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'server_error', message: 'Synthetic failure' } }),
    }));
    await page.goto('/staff');
    await expect(page.getByRole('alert')).toContainText('We couldn’t load the staff lifecycle directory.');
    await shoot(page, 'state-staff-error');

    await page.unroute(/\/api\/v1\/merchant\/staff-overview/);
    await page.route(/\/api\/v1\/merchant\/staff-overview/, (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 25 } }),
    }));
    await page.reload();
    await expect(page.getByText('No merchant users are available.')).toBeVisible();
  });
});

test.describe('Eight Gate-W-disabled contract pages', () => {
  test('remain visible, inert and exact about External Gate W in owner navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openMerchant(page, '/dashboard');
    const nav = page.getByTestId('sidebar-primary-nav');

    for (const entry of GATED_PAGES) {
      const item = nav.getByTestId(`nav-stacked-gated-${entry.key}`);
      await expect(item, `${entry.screen} must stay discoverable`).toBeVisible();
      await expect(item).toHaveAttribute('aria-disabled', 'true');
      expect(await item.evaluate((node) => node.tagName)).not.toBe('A');
      await expect(item).toContainText('External Gate W');
      await expect(item).not.toHaveAttribute('href');
    }

    await shoot(page, 'navigation-gated-treatment');
  });

  for (const entry of GATED_PAGES) {
    test(`${entry.screen} has no live route at ${entry.path}`, async ({ page }) => {
      await openMerchant(page, entry.path);
      await expect(notFound(page)).toBeVisible();
      await expect(page.getByText(/coming soon/i)).toHaveCount(0);
    });
  }

  test('never fabricates zero reporting, payment or notification state', async ({ page }) => {
    await openMerchant(page, '/dashboard');
    const reporting = page.getByTestId('dashboard-reporting-gate');
    const payment = page.getByTestId('dashboard-billing-attention');
    await expect(reporting).toContainText('External Gate W');
    await expect(payment).toContainText('External Gate W');
    await expect(reporting).not.toContainText(/0 reports|0 revenue|all clear/i);
    await expect(payment).not.toContainText(/0 attempts|payment successful/i);
  });
});

test.describe('Merchant owner shell and navigation placement', () => {
  test('desktop has six grouped sections in one persistent left primary navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openMerchant(page, '/dashboard');
    const sidebar = page.getByTestId('sidebar-primary-nav');
    await expect(sidebar).toBeVisible();
    for (const group of NAVIGATION_GROUPS) {
      await expect(sidebar.getByText(group, { exact: true }).first()).toBeVisible();
    }
    await expect(page.getByTestId('header-primary-nav')).toHaveCount(0);
    await expect(page.getByTestId('merchant-context')).toContainText('Glow Studio');
    await expect(page.locator('footer')).toBeVisible();
    await shoot(page, 'shell-desktop-left-navigation');
  });

  test('tablet uses a collapsible labelled rail at both contract boundaries', async ({ page }) => {
    for (const width of [768, 1024]) {
      await page.setViewportSize({ width, height: 900 });
      await openMerchant(page, '/dashboard');
      const rail = page.getByTestId('tablet-navigation-rail');
      await expect(rail).toBeVisible();
      await expect(page.getByTestId('sidebar-primary-nav')).toBeHidden();
      const toggle = page.getByTestId('tablet-navigation-toggle');
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(rail.getByText('Home', { exact: true })).toBeVisible();
      await assertNoHorizontalScroll(page);
    }
    await shoot(page, 'shell-tablet-expanded-rail');
  });

  test('mobile drawer traps focus, closes by Escape and returns focus to its opener', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openMerchant(page, '/dashboard');
    const opener = page.getByTestId('nav-drawer-trigger').first();
    await opener.click();
    const drawer = page.getByRole('dialog', { name: 'Navigation' });
    await expect(drawer).toBeVisible();
    await expect(page.getByTestId('nav-drawer-close')).toBeFocused();

    const first = page.getByTestId('nav-drawer-close');
    const last = drawer.locator('a[href], button:not([disabled]), [tabindex="0"]').last();
    await last.focus();
    await page.keyboard.press('Tab');
    await expect(first).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(last).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(opener).toBeFocused();
    await assertNoHorizontalScroll(page);
    await shoot(page, 'shell-mobile-drawer-closed');
  });

  test('marks the active nested destination without duplicating it in the header', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await openMerchant(page, `/subscription/invoices/${INVOICE_ID}`);
    const invoices = page.getByTestId('sidebar-primary-nav')
      .getByTestId('nav-stacked-link-merchant_administrator.subscription-invoices');
    await expect(invoices).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('header-primary-nav')).toHaveCount(0);
  });
});

test.describe('Authority, tenancy, setup and financial boundaries', () => {
  test('pending setup is the only pre-dashboard operational gate', async ({ page }) => {
    await openMerchant(page, '/dashboard', { setupRequired: true });
    await expect(page).toHaveURL(/\/setup$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Set up your business' })).toBeVisible();

    await openMerchant(page, '/setup');
    await expect(page).toHaveURL(/\/dashboard$/);
  });

  test('wrong account host denies without disclosure', async ({ page }) => {
    await openMerchant(page, '/merchant/profile', { accountKey: 'merchant_finance', accountKeys: ['merchant_administrator'] });
    await expect(notFound(page)).toBeVisible();
    await expect(page.getByTestId('merchant-dashboard')).toHaveCount(0);
  });

  test('the merchant host grants nothing when the user does not hold the account', async ({ page }) => {
    await openMerchant(page, '/dashboard', { accountKeys: [] });
    await expect(page).toHaveURL(/\/access-denied$/);
    await expect(page.getByTestId('merchant-dashboard')).toHaveCount(0);
  });

  test('foreign branch and invoice ULIDs produce non-enumerating unavailable states', async ({ page }) => {
    await stubMerchant(page);
    await stubMerchantApi(page);
    await page.route(new RegExp(`/api/v1/branches/${FOREIGN_BRANCH_ID}$`), (route) => route.fulfill({ status: 404, contentType: 'application/json', body: '{"error":{"code":"not_found"}}' }));
    await page.goto(`/branches/${FOREIGN_BRANCH_ID}`);
    await expect(page.getByText('This branch could not be loaded.')).toBeVisible();
    await expect(page.locator('main')).not.toContainText(FOREIGN_BRANCH_ID);

    const foreignInvoice = '01JQINVOICE000000000000009';
    await page.route(new RegExp(`/api/v1/subscription-invoices/${foreignInvoice}$`), (route) => route.fulfill({ status: 404, contentType: 'application/json', body: '{"error":{"code":"not_found"}}' }));
    await page.goto(`/subscription/invoices/${foreignInvoice}`);
    await expect(page.getByText('We couldn’t load this subscription invoice.')).toBeVisible();
    await expect(page.locator('main')).not.toContainText(foreignInvoice);
  });

  test('staff lifecycle explains session revocation and exposes no phone or client data', async ({ page }) => {
    await openMerchant(page, '/staff');
    await expect(page.getByText('Brian Manager')).toBeVisible();
    await expect(page.locator('main')).not.toContainText('+254700000000');
    await expect(page.getByText(/client data are not included/i)).toBeVisible();
    await page.getByRole('button', { name: 'Suspend access' }).click();
    const dialog = page.getByRole('dialog', { name: 'Confirm access change' });
    await expect(dialog).toContainText('active account-context session');
    await expect(dialog).toContainText('Historical records are preserved');
  });

  test('high-value approval exposes only owner approval and requires fresh step-up on mutation', async ({ page }) => {
    await openMerchant(page, '/compensation/payout-approvals', { stepUpFresh: false });
    await page.route(/\/api\/v1\/merchant\/payout-runs\/[^/]+\/approve-high-value$/, (route) => route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'step_up_required', message: 'A fresh identity check is required.', fields: {}, meta: {} } }),
    }));
    await page.getByRole('button', { name: 'Approve' }).click();
    await page.getByTestId('approve-submit').click();
    await expect(page.getByTestId('approve-step-up')).toBeVisible();
    await expect(page.getByRole('button', { name: /create payout|verify payout|mark paid|record payment/i })).toHaveCount(0);
    await shoot(page, 'state-high-value-step-up');
  });

  test('period reopen offers exceptional approval but no Finance execution', async ({ page }) => {
    await openMerchant(page, '/finance/period-reopen-approvals');
    await expect(page.getByTestId('reopen-approve')).toBeVisible();
    await expect(page.getByRole('button', { name: /execute|lock period|request reopen/i })).toHaveCount(0);
  });

  test('account security is own-scope, Magic Link only, with no password or cross-user control', async ({ page }) => {
    await openMerchant(page, '/account');
    await expect(page.getByText('Magic Link', { exact: true })).toBeVisible();
    await expect(page.getByText('Servana has no passwords.')).toBeVisible();
    await expect(page.getByRole('textbox', { name: /password|other user|email lookup/i })).toHaveCount(0);
  });

  test('no implemented page grants operational takeover or a Wallet/provider mutation', async ({ page }) => {
    const forbidden = [
      /record payment/i, /validate payment/i, /issue receipt/i, /create invoice/i, /cash[- ]?up/i,
      /edit service/i, /set price/i, /assign personnel/i, /configure compensation/i, /contact export/i,
      /pay now/i, /stk/i, /query provider/i, /mark paid/i,
    ];

    for (const entry of IMPLEMENTED_PAGES.filter((candidate) => candidate.screen !== 'setup')) {
      await openMerchant(page, entry.path);
      const labels = (await page.locator('button, a, [role="button"]').allInnerTexts()).map((label) => label.trim()).filter(Boolean);
      for (const label of labels) {
        for (const pattern of forbidden) {
          expect(pattern.test(label), `${entry.screen} renders forbidden control "${label}"`).toBe(false);
        }
      }
    }
  });
});

test.describe('Responsive, theme and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all live pages avoid normal horizontal page scrolling at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of IMPLEMENTED_PAGES) {
        await openMerchant(page, entry.path, { setupRequired: entry.screen === 'setup' });
        await expectPageHeading(page, entry.screen, entry.h1);
        await assertNoHorizontalScroll(page);
      }
      await shoot(page, `responsive-${width}`);
    });
  }

  test('survives the 200% zoom equivalent without page overflow', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await openMerchant(page, '/staff');
    await page.setViewportSize({ width: 640, height: 450 });
    await assertNoHorizontalScroll(page);
    await shoot(page, 'responsive-200-percent-zoom');
  });

  test('defaults to light despite OS dark preference and persists an explicit dark choice', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await openMerchant(page, '/dashboard');
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await shoot(page, 'dashboard-dark');
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
  });

  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} has no serious or critical axe violation in light mode`, async ({ page }) => {
      await openMerchant(page, entry.path, { setupRequired: entry.screen === 'setup' });
      await expectPageHeading(page, entry.screen, entry.h1);
      const results = await new AxeBuilder({ page }).analyze();
      const violations = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
      expect(violations, violations.map((violation) => `${violation.id} (${violation.nodes.length})`).join(', ')).toEqual([]);
    });
  }

  test('representative owner pages and the open drawer are axe-clean in dark mode', async ({ page }) => {
    for (const path of ['/dashboard', '/staff', `/subscription/invoices/${INVOICE_ID}`, '/account']) {
      await openMerchant(page, path);
      await page.getByTestId('theme-toggle').first().click();
      const results = await new AxeBuilder({ page }).analyze();
      const violations = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
      expect(violations, `${path}: ${violations.map((violation) => violation.id).join(', ')}`).toEqual([]);
    }

    await page.setViewportSize({ width: 360, height: 800 });
    await openMerchant(page, '/dashboard');
    await page.getByTestId('nav-drawer-trigger').first().click();
    const drawer = await new AxeBuilder({ page }).analyze();
    expect(drawer.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')).toEqual([]);
  });
});

test('writes the UI-09 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  expect(files.length, 'no UI-09 screenshots were captured').toBeGreaterThan(0);
  const byName = new Map(IMPLEMENTED_PAGES.map((page) => [page.screen, page]));

  const index = {
    schema: 'servana.ui09.screenshot-index.v1',
    phase: 'UI-09',
    account: 'merchant_administrator',
    host: 'servana.ke',
    captured_against: 'built SPA served by Vite preview with synthetic Merchant Administrator API fixtures',
    data_provenance: 'synthetic; no real merchant, person, contact, credential, token or provider payload appears',
    status: 'UI-09 implementation evidence; not a UI-16 release-approved visual baseline',
    captures: files.map((file) => {
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
    }),
    totals: {
      captures: files.length,
      implemented_page_captures: files.filter((file) => file.startsWith('desktop-light-')).length,
      gated_navigation_captures: files.filter((file) => file.includes('gated')).length,
    },
  };

  await writeEvidenceFile(resolve(SHOTS, '..', 'screenshot-index.json'), `${JSON.stringify(index, null, 2)}\n`);
});
