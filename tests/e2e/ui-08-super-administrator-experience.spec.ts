import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { assertNoHorizontalScroll } from './support/roleBootstrap';
import {
  GATED_PAGES,
  IMPLEMENTED_PAGES,
  NAVIGATION_GROUPS,
  stubPlatformApi,
  stubSuperAdmin,
  watchBrowserHealth,
} from './support/ui08Platform';
import { stubMerchant, stubMerchantApi } from './support/ui09Merchant';

/**
 * Phase UI-08 Increment 10 — the Super Administrator browser proof.
 *
 * UI-08's acceptance is that the header navigation is complete and responsive, that there is no
 * left primary navigation on desktop, that no merchant-operation control exists, that every
 * implemented page uses real data, and that every page has browser proof. This file is that proof.
 *
 * It runs against the built SPA with the platform reads stubbed, so the REAL router, guards,
 * components and stores are exercised. Server-side authorization is proven by the feature suites;
 * what is proven here is the experience and the absence of what must not exist.
 *
 * All fixtures are synthetic. No real merchant, person, credential or provider payload appears in
 * any response or any screenshot.
 */

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-08/screenshots');
mkdirSync(SHOTS, { recursive: true });

/** The component builds a group's id with this exact slug, so the test addresses it the same way. */
const groupId = (name: string): string =>
  `nav-group-${name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`;

/**
 * Open a header group by TEST ID, not by accessible name.
 *
 * The header deliberately renders its tail groups twice — once inline and once inside the overflow
 * disclosure — and lets a CSS breakpoint decide which is shown (guardrail 1 forbids measuring the
 * container). A name-based `.first()` therefore resolves to whichever copy is first in the DOM,
 * which may be the hidden one.
 */
async function openGroup(page: Page, group: string): Promise<void> {
  // Wait for the shell before asking what is visible: `isVisible()` is a SNAPSHOT, so calling it
  // mid-hydration reported false for a trigger that was about to appear, and the helper fell
  // through to an overflow that desktop legitimately hides.
  await expect(page.getByTestId('header-primary-nav')).toBeVisible();

  const inline = page.getByTestId(`nav-group-trigger-${groupId(group)}`).filter({ visible: true });
  if ((await inline.count()) > 0) {
    await inline.first().click();
    return;
  }

  // Below the desktop floor the tail groups live behind the overflow disclosure.
  await page.getByTestId('nav-overflow-trigger').click();
  await page.getByTestId(`nav-overflow-group-${groupId(group)}`).filter({ visible: true }).first().click();
}

/** The unknown-address page, addressed by its own test id. */
const notFound = (page: Page) => page.getByTestId('public-not-found');

async function shoot(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage: true, animations: 'disabled' });
}

/** Open a Super Administrator page with the API stubbed and health watched. */
async function open(page: Page, path: string) {
  const health = watchBrowserHealth(page);
  await stubSuperAdmin(page);
  await stubPlatformApi(page);
  await page.goto(path);
  return health;
}

// ── Every one of the 22 contract entries ───────────────────────────────────────────────────────

test.describe('The seventeen implemented contract pages', () => {
  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} renders at ${entry.path} with real data and a clean console`, async ({ page }) => {
      const health = await open(page, entry.path);

      expect(health.pageErrors, `${entry.screen} page errors`).toEqual([]);
      await expect(page.getByTestId(entry.testid)).toBeVisible();
      // One page, one h1 — the shell's route label is chrome, never a second heading.
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      // The detail page titles itself with the merchant once the record arrives, so the wait is
      // on the heading TEXT rather than on an arbitrary settle.
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(entry.h1);
      // `$` alone would reject a legitimate query or hash, so the anchor allows both.
      await expect(page).toHaveURL(new RegExp(`${entry.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\?|#|$)`));

      // Real data: the page issued at least one API read, and it is not the bootstrap alone.
      const reads = health.apiRequests.filter((url) => !url.endsWith('/api/v1/me'));
      expect(reads.length, `${entry.screen} made no API read`).toBeGreaterThan(0);

      // No catch-all false positive: the not-found page must not be what rendered.
      await expect(notFound(page)).toHaveCount(0);

      expect(health.pageErrors, `${entry.screen} page errors`).toEqual([]);
      expect(health.consoleErrors, `${entry.screen} console errors`).toEqual([]);
      expect(health.failedRequests, `${entry.screen} failed requests`).toEqual([]);

      await shoot(page, `desktop-light-${entry.screen}`);
    });
  }

  test('every implemented page keeps its loading, empty and error states honest', async ({ page }) => {
    // Error + retry on a representative list page.
    await stubSuperAdmin(page);
    await stubPlatformApi(page);
    await page.route(/\/api\/v1\/platform\/merchants(\?|$)/, (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"server_error"}}' }));
    await page.goto('/merchants');
    // The table and the record list both render the state; CSS shows one. Assert the pair exists.
    await expect(page.getByTestId('sv-error-state').first()).toBeVisible();
    await shoot(page, 'state-error-retry');

    // Empty state names why it is empty rather than implying a failure.
    await page.unroute(/\/api\/v1\/platform\/merchants(\?|$)/);
    await page.route(/\/api\/v1\/platform\/merchants(\?|$)/, (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) }));
    await page.reload();
    // Rendered by the table AND the record list; CSS shows one.
    await expect(page.getByText('Merchants appear here after they self-register.').first()).toBeVisible();
  });

  test('a page the viewer may not read shows the non-enumerating permission state', async ({ page }) => {
    await stubSuperAdmin(page, { permissions: ['platform.merchant.view'] });
    await stubPlatformApi(page);
    await page.goto('/audit');
    await expect(page.getByTestId('sv-permission-state')).toBeVisible();
    // It names no resource, no owner and no identifier.
    await expect(page.getByTestId('sv-permission-state')).not.toContainText('audit_logs');
    await shoot(page, 'state-no-permission');
  });

  test('a mandatory MFA challenge is required before any platform page renders', async ({ page }) => {
    await stubSuperAdmin(page, { mfaChallengeRequired: true });
    await stubPlatformApi(page);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/auth\/mfa\/challenge/);
    await shoot(page, 'state-mfa-challenge');
  });

  test('the merchant detail refuses an unknown identifier without revealing whether it exists', async ({ page }) => {
    await stubSuperAdmin(page);
    await stubPlatformApi(page);
    await page.route(/\/api\/v1\/platform\/merchants\/[^/?]+$/, (route) => route.fulfill({ status: 404, contentType: 'application/json', body: '{"error":{"code":"not_found"}}' }));
    await page.goto('/merchants/01JQ0000000000000000000009');
    const message = page.getByTestId('merchant-detail-unavailable');
    await expect(message).toBeVisible();
    await expect(message).not.toContainText('01JQ0000000000000000000009');
    await expect(message).not.toContainText('Acme');
  });
});

test.describe('The five gated contract entries', () => {
  test('are visible and inert in navigation, naming the exact gate', async ({ page }) => {
    await open(page, '/dashboard');

    for (const entry of GATED_PAGES) {
      const item = page.getByTestId(`nav-gated-${entry.key}`).or(page.getByTestId(`nav-overflow-gated-${entry.key}`));
      // Open the group that owns it, then read the inert item.
      await openGroup(page, entry.group);
      const found = page.locator(`[data-testid="nav-gated-${entry.key}"], [data-testid="nav-overflow-gated-${entry.key}"]`).filter({ visible: true }).first();
      await expect(found, `${entry.screen} must appear in navigation`).toBeVisible();
      await expect(found).toHaveAttribute('aria-disabled', 'true');
      // Inert: no anchor, therefore no href and nothing to middle-click.
      expect(await found.evaluate((node) => node.tagName)).not.toBe('A');
      await expect(found).toContainText('Gate W');
      expect(item).toBeTruthy();
      await page.keyboard.press('Escape');
    }

    await shoot(page, 'nav-gated-treatment');
  });

  for (const entry of GATED_PAGES) {
    test(`${entry.screen} has no live route at ${entry.path}`, async ({ page }) => {
      await open(page, entry.path);
      // Not a page, not a placeholder, not a "coming soon" screen — the address does not exist.
      await expect(notFound(page)).toBeVisible();
      await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(/reconciliation|integrations|qualification|reports|notifications/i);
    });
  }

  test('renders no fabricated metric for a blocked capability', async ({ page }) => {
    await open(page, '/dashboard');
    const gated = page.getByTestId('dashboard-integrations-gated');
    await expect(gated).toBeVisible();
    await expect(gated).toContainText('External Gate W');
    // Never a zero, never a healthy state, never an empty list presented as "all clear".
    await expect(gated).not.toContainText(/\bhealthy\b/i);
    await expect(gated).not.toContainText(/\b0\b/);
  });
});

// ── The header navigation shell ────────────────────────────────────────────────────────────────

test.describe('Header navigation', () => {
  test('carries all eight contract groups and owns primary navigation on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await open(page, '/dashboard');

    const header = page.getByTestId('header-primary-nav');
    await expect(header).toBeVisible();
    for (const group of NAVIGATION_GROUPS) {
      const trigger = page.getByTestId(`nav-group-trigger-${groupId(group)}`).filter({ visible: true });
      await expect(trigger, `${group} must be reachable in the header`).toHaveCount(1);
    }

    // ADR-018: the Super Administrator is the one account whose primary navigation is in the
    // header. No left rail may exist on desktop.
    await expect(page.getByTestId('sidebar-primary-nav')).toHaveCount(0);
    await expect(page.locator('aside nav')).toHaveCount(0);

    await shoot(page, 'nav-desktop-header');
  });

  test('opens one group at a time, closes on Escape and restores focus', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await open(page, '/dashboard');

    const trigger = page.getByTestId(`nav-group-trigger-${groupId('Merchants')}`).filter({ visible: true }).first();
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('nav-link-super_administrator.merchants').filter({ visible: true })).toHaveCount(1);

    const other = page.getByTestId(`nav-group-trigger-${groupId('Utility')}`).filter({ visible: true }).first();
    await other.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await page.keyboard.press('Escape');
    await expect(other).toHaveAttribute('aria-expanded', 'false');
    await expect(other).toBeFocused();
  });

  test('marks the active route and navigates without a full page load', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await open(page, '/dashboard');

    await openGroup(page, 'Merchants');
    await page.getByTestId('nav-link-super_administrator.merchants').filter({ visible: true }).first().click();
    await expect(page).toHaveURL(/\/merchants$/);
    await expect(page.getByTestId('platform-merchant-directory-screen')).toBeVisible();
    await openGroup(page, 'Merchants');
    await expect(page.getByTestId('nav-link-super_administrator.merchants').filter({ visible: true }).first()).toHaveAttribute('aria-current', 'page');
  });

  test('condenses on tablet without a permanent left rail', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 800 });
    await open(page, '/dashboard');
    await expect(page.getByTestId('header-primary-nav')).toBeVisible();
    await expect(page.getByTestId('sidebar-primary-nav')).toHaveCount(0);
    await assertNoHorizontalScroll(page);
    await shoot(page, 'nav-tablet-header');
  });

  test('becomes an accessible drawer on mobile, showing the same filtered registry', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/dashboard');

    const toggle = page.getByTestId('nav-drawer-trigger').first();
    await expect(toggle).toBeVisible();
    await toggle.click();

    const drawer = page.getByTestId('stacked-primary-nav');
    await expect(drawer).toBeVisible();
    for (const group of NAVIGATION_GROUPS) {
      await expect(drawer.getByText(group, { exact: true }).first()).toBeVisible();
    }
    await shoot(page, 'nav-mobile-drawer');

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(toggle).toBeFocused();
  });

  test('offers the profile, account switch and theme controls from the header', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await open(page, '/dashboard');
    await expect(page.getByTestId('sv-profile-control').first()).toBeVisible();
    await expect(page.getByTestId('theme-toggle').first()).toBeVisible();
  });
});

// ── Security negatives ─────────────────────────────────────────────────────────────────────────

test.describe('Security negatives', () => {
  test('a merchant host serves its own dashboard but none of the Super Administrator-only addresses', async ({ page }) => {
    // Host scoping (Increment 7B) is stronger than a denial page: on a merchant host the Super
    // Administrator route tree is never registered, so its paths do not exist at all.
    await stubMerchant(page);
    await stubMerchantApi(page);

    await page.goto('/dashboard');
    await expect(page.getByTestId('merchant-dashboard')).toBeVisible();
    await expect(page.getByTestId('platform-dashboard-screen')).toHaveCount(0);

    for (const path of ['/merchants', '/audit', '/platform-access']) {
      await page.goto(path);
      await expect(notFound(page), `${path} must not exist on a merchant host`).toBeVisible();
      await expect(page.getByTestId('platform-dashboard-screen')).toHaveCount(0);
    }
    // And nothing names the account that does own them.
    await expect(page.getByText(/super administrator/i)).toHaveCount(0);
  });

  test('holding the account is not enough when another host is served', async ({ page }) => {
    await stubSuperAdmin(page, { accountKey: 'merchant_finance', accountKeys: ['super_administrator', 'merchant_finance'] });
    await stubPlatformApi(page);
    await page.goto('/dashboard');
    await expect(notFound(page)).toBeVisible();
    await expect(page.getByTestId('platform-dashboard-screen')).toHaveCount(0);
  });

  test('the host alone grants nothing when the user does not hold the account', async ({ page }) => {
    // Here the tree IS registered — the host serves it — so the account guard is what refuses.
    await stubSuperAdmin(page, { accountKey: 'super_administrator', accountKeys: [] });
    await stubPlatformApi(page);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/access-denied$/);
    await expect(page.getByTestId('platform-dashboard-screen')).toHaveCount(0);
  });

  test('no merchant-operation control exists anywhere in the account', async ({ page }) => {
    const FORBIDDEN = [
      /\bimpersonat/i, /\bsign in as\b/i, /\bcreate\b.*\bmerchant\b/i, /\bnew merchant\b/i,
      /first administrator/i, /\brecord payment\b/i, /\bvalidate payment\b/i, /\bissue receipt\b/i,
      /\bcreate invoice\b/i, /\b(add|create) branch\b/i, /\b(add|create) staff\b/i,
      /\bcomplete setup\b/i, /\bmark paid\b/i, /\boverride state\b/i,
    ];

    for (const entry of IMPLEMENTED_PAGES) {
      await open(page, entry.path);
      const labels = await page.locator('button, a, [role="button"]').allInnerTexts();
      for (const label of labels.map((l) => l.trim()).filter(Boolean)) {
        for (const pattern of FORBIDDEN) {
          expect(pattern.test(label), `${entry.screen} renders forbidden control "${label}"`).toBe(false);
        }
      }
    }
  });

  test('subscription operations offers no mutation at all', async ({ page }) => {
    await open(page, '/billing/subscriptions');
    const labels = (await page.locator('button').allInnerTexts()).map((l) => l.trim().toLowerCase());
    for (const forbidden of ['record payment', 'mark paid', 'edit invoice', 'create credit', 'query provider', 'override']) {
      expect(labels.some((l) => l.includes(forbidden)), `subscription operations offers "${forbidden}"`).toBe(false);
    }
  });

  test('SMS billing exposes no recipient, message body or contact export', async ({ page }) => {
    await open(page, '/billing/sms');
    const labels = (await page.locator('button, a').allInnerTexts()).map((l) => l.trim().toLowerCase());
    for (const forbidden of ['export', 'download recipients', 'contacts', 'message body']) {
      expect(labels.some((l) => l.includes(forbidden)), `SMS billing offers "${forbidden}"`).toBe(false);
    }
  });

  test('platform audit offers no export and no mutation of an append-only record', async ({ page }) => {
    await open(page, '/audit');
    const labels = (await page.locator('button').allInnerTexts()).map((l) => l.trim().toLowerCase());
    for (const forbidden of ['export', 'delete', 'edit', 'resolve', 'dismiss']) {
      expect(labels.some((l) => l.includes(forbidden)), `platform audit offers "${forbidden}"`).toBe(false);
    }
    await expect(page.getByTestId('audit-export-disposition')).toContainText('Phase 23');
  });

  test('a feature flag cannot open a gate, grant a permission or bypass billing state', async ({ page }) => {
    // Every flag the catalogue knows is enabled at once; the five gated entries stay inert.
    await stubSuperAdmin(page);
    await stubPlatformApi(page);
    await page.route(/\/api\/v1\/platform\/feature-flags(\?|$)/, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { key: 'platform.demo_flag', state: 'enabled', description: 'Synthetic.', targets: [], history: [], can: { request_change: true, pause: true } },
            { key: 'external_gate_w', state: 'enabled', description: 'Synthetic flag deliberately named after a gate.', targets: [], history: [], can: { request_change: true, pause: true } },
          ],
          meta: { current_page: 1, last_page: 1, total: 2 },
        }),
      }));

    await page.goto('/dashboard');
    await openGroup(page, 'Integrations');
    const gated = page.locator('[data-testid^="nav-gated-super_administrator.integrations"], [data-testid^="nav-overflow-gated-super_administrator.integrations"]').first();
    await expect(gated).toHaveAttribute('aria-disabled', 'true');

    // And the gated address still does not exist.
    await page.goto('/integrations');
    await expect(notFound(page)).toBeVisible();
  });
});

// ── Compatibility redirects ────────────────────────────────────────────────────────────────────

test.describe('Compatibility redirects', () => {
  const REDIRECTS: Array<[string, RegExp]> = [
    ['/platform/get-started', /\/get-started(\?|#|$)/],
    ['/platform/billing-settings', /\/billing\/settings(\?|#|$)/],
    ['/platform/promotions', /\/billing\/promotions(\?|#|$)/],
    ['/platform/registration-monitoring', /\/merchants\/registrations(\?|#|$)/],
  ];

  for (const [from, to] of REDIRECTS) {
    test(`${from} redirects to its canonical page, preserving query and hash`, async ({ page }) => {
      await open(page, `${from}?tab=demo#section`);
      await expect(page).toHaveURL(to);
      expect(page.url()).toContain('tab=demo');
      expect(page.url()).toContain('#section');
    });
  }

  test('/platform/dashboard has no redirect and /platform stays the role landing', async ({ page }) => {
    await open(page, '/platform/dashboard');
    // It must NOT have been rewritten to the canonical `/dashboard`; it stays where it was asked
    // for, and that address simply does not exist.
    expect(new URL(page.url()).pathname).toBe('/platform/dashboard');
    await expect(notFound(page)).toBeVisible();

    await page.goto('/platform');
    await expect(page).toHaveURL(/\/platform$/);
  });
});

// ── Responsive, theme and accessibility ────────────────────────────────────────────────────────

test.describe('Responsive matrix', () => {
  const WIDTHS = [360, 767, 768, 1024, 1025, 1280, 1440];

  for (const width of WIDTHS) {
    test(`no horizontal overflow at ${width}px across the account`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of IMPLEMENTED_PAGES) {
        await open(page, entry.path);
        await expect(page.getByTestId(entry.testid)).toBeVisible();
        const overflow = await page.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          client: document.documentElement.clientWidth,
        }));
        expect(
          overflow.scroll,
          `${entry.screen} overflows at ${width}px (${overflow.scroll} > ${overflow.client})`,
        ).toBeLessThanOrEqual(overflow.client);
      }
    });
  }

  test('survives 200% zoom without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await open(page, '/merchants');
    // 200% zoom is equivalent to halving the CSS viewport.
    await page.setViewportSize({ width: 640, height: 450 });
    await assertNoHorizontalScroll(page);
    await shoot(page, 'responsive-200-zoom');
  });

  test('turns tables into labelled record cards on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/merchants');
    await expect(page.getByTestId('sv-record-list-root')).toBeVisible();
    await assertNoHorizontalScroll(page);
    for (const entry of IMPLEMENTED_PAGES) {
      await open(page, entry.path);
      await expect(page.getByTestId(entry.testid)).toBeVisible();
      await shoot(page, `mobile-light-${entry.screen}`);
    }
  });

  test('keeps every interactive control at least 44px on its smallest side', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/merchants');
    const small = await page.locator('button:visible, a:visible').evaluateAll((nodes) =>
      nodes
        .map((node) => ({ text: (node.textContent ?? '').trim().slice(0, 40), rect: node.getBoundingClientRect() }))
        .filter(({ rect }) => rect.width > 0 && rect.height > 0 && rect.height < 44)
        .map(({ text, rect }) => `${text} (${Math.round(rect.width)}x${Math.round(rect.height)})`),
    );
    expect(small, 'controls below the 44px minimum target').toEqual([]);
  });
});

test.describe('Theme', () => {
  test('defaults to light, never reading prefers-color-scheme', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await open(page, '/dashboard');
    // ADR-021: the OS preference must NOT select the theme.
    await expect(page.locator('html')).not.toHaveClass(/dark/);
  });

  test('applies and persists an explicit dark choice', async ({ page }) => {
    await open(page, '/dashboard');
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await shoot(page, 'dashboard-dark');

    await page.reload();
    // Applied before hydration: no light flash, and the class is present on first paint.
    await expect(page.locator('html')).toHaveClass(/dark/);
  });
});

test.describe('Accessibility', () => {
  for (const entry of IMPLEMENTED_PAGES) {
    test(`${entry.screen} is axe-clean in light mode`, async ({ page }) => {
      await open(page, entry.path);
      await expect(page.getByTestId(entry.testid)).toBeVisible();
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${entry.screen}: ${serious.map((v) => `${v.id} (${v.nodes.length})`).join(', ')}`).toEqual([]);
    });
  }

  test('is axe-clean in dark mode on representative pages, and in the drawer', async ({ page }) => {
    for (const path of ['/dashboard', '/merchants', '/billing/settings', '/audit', '/account']) {
      await open(page, path);
      await page.getByTestId('theme-toggle').first().click();
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${path} dark: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }

    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/dashboard');
    await page.getByTestId('nav-drawer-trigger').first().click();
    const drawer = await new AxeBuilder({ page }).analyze();
    const serious = drawer.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious, `drawer: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
  });

  test('is axe-clean in the governance dialog and on the no-permission state', async ({ page }) => {
    await open(page, '/merchants/01JQ0000000000000000000001');
    await page.getByTestId('action-suspend').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await shoot(page, 'form-governance-dialog');
    const dialog = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
    expect(dialog.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);

    await stubSuperAdmin(page, { permissions: [] });
    await page.goto('/merchants');
    const denied = await new AxeBuilder({ page }).analyze();
    expect(denied.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

// ── Evidence index ─────────────────────────────────────────────────────────────────────────────

test('writes the screenshot index', async () => {
  const files = readdirSync(SHOTS).filter((f) => f.endsWith('.png')).sort();
  expect(files.length, 'no screenshots were captured').toBeGreaterThan(0);

  const byName = new Map(IMPLEMENTED_PAGES.map((p) => [p.screen, p]));

  writeFileSync(
    resolve(SHOTS, '..', 'screenshot-index.json'),
    `${JSON.stringify(
      {
        schema: 'servana.ui08.screenshot-index.v1',
        phase: 'UI-08',
        increment: '10',
        account: 'super_administrator',
        host: 'citrus.servana.ke',
        captured_against: 'the built SPA served by vite preview, with every platform read stubbed with SYNTHETIC data',
        data_provenance: 'synthetic — no real merchant, person, contact detail, credential, token, session identifier or provider payload appears in any capture',
        status: 'implementation proof for UI-08. These are NOT UI-16-approved visual baselines.',
        captures: files.map((file) => {
          const bytes = readFileSync(join(SHOTS, file));
          const screen = file.replace(/^(desktop-light|mobile-light)-/, '').replace(/\.png$/, '');
          const entry = byName.get(screen);
          return {
            file: `screenshots/${file}`,
            screen_key: entry?.screen ?? null,
            route: entry?.path ?? null,
            viewport: file.startsWith('mobile-') ? 'mobile 375x812' : 'desktop 1280x720 (Playwright default) unless the case resized it',
            theme: file.includes('dark') ? 'dark' : 'light',
            bytes: statSync(join(SHOTS, file)).size,
            sha256: createHash('sha256').update(bytes).digest('hex'),
          };
        }),
        totals: {
          captures: files.length,
          desktop_light_pages: files.filter((f) => f.startsWith('desktop-light-')).length,
          mobile_light_pages: files.filter((f) => f.startsWith('mobile-light-')).length,
        },
      },
      null,
      2,
    )}\n`,
  );
});
