import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { IDS, prepare, type AuditScreen } from './support/releaseAudit';
import { assertNoHorizontalScroll } from './support/roleBootstrap';

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-13/screenshots');
mkdirSync(SHOTS, { recursive: true });

const PAGES = [
  { screen: 'dashboard', route: 'front-office.dashboard', path: '/dashboard', ready: '[data-testid="front-office-dashboard"]', api: '/front-office/workspace' },
  { screen: 'get-started', route: 'front-office.get-started', path: '/get-started', ready: 'main h1, main h2', api: '/front-office/workspace' },
  { screen: 'operational-search', route: 'search', path: '/search', ready: '[data-testid="operational-search"]', api: null },
  { screen: 'clients', route: 'front-office.clients', path: '/clients', ready: 'main h1', api: '/clients' },
  { screen: 'clients-create', route: 'front-office.clients-create', path: '/clients/create', ready: 'main h1', api: null },
  { screen: 'client-detail', route: 'front-office.client-detail', path: `/clients/${IDS.client}`, ready: 'main h1', api: `/clients/${IDS.client}` },
  { screen: 'appointments', route: 'front-office.appointments', path: '/appointments', ready: 'main h1', api: '/appointments' },
  { screen: 'walk-ins', route: 'front-office.walk-ins', path: '/walk-ins', ready: 'main h1', api: null },
  { screen: 'queue', route: 'front-office.queue', path: '/queue', ready: 'main h1', api: '/queue-entries' },
  { screen: 'queue-transfer', route: 'front-office.queue-transfer', path: `/queue/${IDS.queueEntry}/transfer`, ready: '[data-testid="front-office-queue-transfer"]', api: `/queue-entries/${IDS.queueEntry}` },
  { screen: 'sessions', route: 'front-office.sessions', path: '/sessions', ready: 'main h1', api: '/service-sessions' },
  { screen: 'invoices', route: 'front-office.invoices', path: '/invoices', ready: 'main h1', api: '/invoices' },
  { screen: 'invoices-create', route: 'front-office.invoices-create', path: '/invoices/create', ready: 'main h1', api: null },
  { screen: 'invoice-payment-create', route: 'front-office.invoice-payment-create', path: `/invoices/${IDS.invoice}/payments/create`, ready: 'main h1', api: `/invoices/${IDS.invoice}` },
  { screen: 'payments-status', route: 'front-office.payments-status', path: '/payments/status', ready: '[data-testid="front-office-payment-status"]', api: '/front-office/payment-status' },
  { screen: 'activity', route: 'front-office.activity', path: '/activity', ready: '[data-testid="front-office-activity"]', api: '/front-office/activity' },
  { screen: 'account', route: 'front-office.account', path: '/account', ready: 'main h1', api: '/auth/sessions' },
] as const;

const GATED = [
  {
    key: 'merchant_front_office.subscription-payment',
    path: '/subscription/payment',
    label: 'Subscription Payment and Recovery',
    gate: 'External Gate W',
    network: /subscription|wallet|payment-attempt/i,
  },
  {
    key: 'merchant_front_office.notifications',
    path: '/notifications',
    label: 'Notifications',
    gate: 'Phase 21N',
    network: /notifications/i,
  },
] as const;

const FRONT_OFFICE_PERMISSIONS = [
  'front_office.search',
  'client.view', 'client.create', 'client.update', 'client.consent_manage',
  'appointment.view', 'appointment.create', 'appointment.assign', 'appointment.transfer',
  'appointment.reschedule', 'appointment.check_in', 'appointment.cancel', 'appointment.no_show',
  'queue.view', 'queue.create', 'queue.assign', 'queue.call', 'queue.start', 'queue.complete',
  'queue.transfer', 'queue.cancel', 'queue.no_show', 'queue.reorder',
  'service_session.view', 'service_session.start', 'service_session.complete', 'service_session.cancel',
  'invoice.view', 'invoice.create', 'invoice.update_draft', 'invoice.finalize',
  'customer_payment.record', 'receipt.view', 'receipt.download',
];

const auditScreen = (entry: typeof PAGES[number]): AuditScreen => ({
  key: entry.screen,
  route: entry.route,
  path: entry.path,
  role: 'merchant_front_office',
  state: 'populated',
  ready: entry.ready,
  bootstrap: { permissions: FRONT_OFFICE_PERMISSIONS },
});

async function waitForPreview(page: Page): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    try {
      const response = await page.request.get('/', { timeout: 1_000 });
      if (response.ok()) return;
      lastError = new Error(`Preview readiness returned HTTP ${response.status()}`);
    } catch (error: unknown) {
      lastError = error;
    }
    await page.waitForTimeout(250);
  }
  throw lastError;
}

async function openFrontOffice(page: Page, entry: typeof PAGES[number]) {
  const errors: string[] = [];
  const failed: string[] = [];
  const requests: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('requestfailed', (request) => failed.push(request.url()));
  page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
  await prepare(page, auditScreen(entry));
  await waitForPreview(page);
  await page.goto(entry.path);
  await expect(page.locator(entry.ready).first()).toBeVisible();
  return { errors, failed, requests };
}

async function settleVisualEvidence(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(Array.from(document.images, (image) => {
      if (image.complete) return Promise.resolve();
      return new Promise<void>((resolveImage) => {
        image.addEventListener('load', () => resolveImage(), { once: true });
        image.addEventListener('error', () => resolveImage(), { once: true });
      });
    }));
  });
  await page.waitForTimeout(50);
  await page.evaluate(async () => {
    window.scrollTo(0, 0);
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    document.querySelectorAll<HTMLElement>('main, [data-testid="sidebar-primary-nav"]')
      .forEach((element) => element.scrollTo(0, 0));
    await new Promise<void>((resolveFrame) => requestAnimationFrame(() => requestAnimationFrame(() => resolveFrame())));
  });
}

test.describe('all seventeen implemented Front Office pages', () => {
  for (const entry of PAGES) {
    test(`${entry.screen} resolves its canonical office route and assigned-branch state`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openFrontOffice(page, entry);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      await expect(page.getByTestId('branch-context')).toContainText('Westlands Branch');
      if (entry.api) {
        expect(health.requests.some((url) => url.includes(entry.api!)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      }
      expect(health.errors, `${entry.screen} browser errors`).toEqual([]);
      expect(health.failed, `${entry.screen} failed requests`).toEqual([]);
      await settleVisualEvidence(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }
});

test.describe('two externally gated Front Office pages', () => {
  test('remain discoverable, disabled and dependency-specific in navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openFrontOffice(page, PAGES[0]);
    const nav = page.getByTestId('sidebar-primary-nav');
    await expect(nav.locator('section')).toHaveCount(8);
    await expect(nav.locator('section > p')).toHaveText([
      'Home',
      'Quick Access',
      'Clients',
      'Appointments & Walk-Ins',
      'Queue & Service',
      'Billing Client',
      'Billing Banner',
      'Utility',
    ]);
    for (const entry of GATED) {
      const item = nav.getByTestId(`nav-stacked-gated-${entry.key}`);
      await item.scrollIntoViewIfNeeded();
      await expect(item).toBeVisible();
      await expect(item).toHaveAttribute('aria-disabled', 'true');
      await expect(item).toContainText(entry.gate);
      await expect(item).not.toHaveAttribute('href');
    }
  });

  for (const entry of GATED) {
    test(`${entry.label} has no live route, component or network runtime`, async ({ page }) => {
      const requests: string[] = [];
      page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
      await prepare(page, auditScreen(PAGES[0]));
      await page.goto(entry.path);
      await expect(page.getByTestId('public-not-found')).toBeVisible();
      await expect(page.getByText(/coming soon|0 notifications|provider status|payment successful/i)).toHaveCount(0);
      expect(requests.some((url) => entry.network.test(url))).toBe(false);
      await settleVisualEvidence(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `gated-${entry.key.split('.').at(-1)}.png`), { animations: 'disabled' });
    });
  }
});

test.describe('Front Office authority and direct-link boundaries', () => {
  test('wrong account fails closed', async ({ page }) => {
    const dashboard = auditScreen(PAGES[0]);
    await prepare(page, { ...dashboard, role: 'merchant_finance', bootstrap: { permissions: ['invoice.view'] } });
    await page.goto('/dashboard');
    await expect(page.getByTestId('front-office-dashboard')).toHaveCount(0);
  });

  test('does not expose checker, Finance, HR, Branch or staff-access controls', async ({ page }) => {
    const forbidden = /validate payment|reject payment|duplicate override|issue receipt|reissue receipt|refund|dispute|approve cash-up|period lock|reopen period|service catalogue|manage eligibility|invite staff|manage staff access/i;
    for (const entry of [PAGES[0], PAGES[9], PAGES[13], PAGES[14], PAGES[16]]) {
      await openFrontOffice(page, entry);
      await expect(page.getByRole('button', { name: forbidden })).toHaveCount(0);
      await expect(page.getByRole('link', { name: forbidden })).toHaveCount(0);
    }
  });

  test('payment status distinguishes recorded evidence from Finance validation and automatic receipt readiness', async ({ page }) => {
    await openFrontOffice(page, PAGES[14]);
    await expect(page.locator('main')).toContainText('Awaiting Finance');
    await expect(page.locator('main')).toContainText('Not available yet');
    await expect(page.locator('main')).toContainText('No manual issue control exists.');
  });

  for (const entry of [PAGES[5], PAGES[9], PAGES[13]]) {
    test(`${entry.screen} works by direct parameterized deep link`, async ({ page }) => {
      const health = await openFrontOffice(page, entry);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      expect(health.errors).toEqual([]);
      expect(health.failed).toEqual([]);
    });
  }
});

test.describe('loading, empty and error direction', () => {
  test('dashboard exposes a recovery action after a server error', async ({ page }) => {
    await prepare(page, auditScreen(PAGES[0]), {
      fixtures: [{ match: /^\/front-office\/workspace$/, body: { error: { code: 'server_error', message: 'Unavailable' } }, status: 503 }],
    });
    await page.goto('/dashboard');
    await expect(page.getByText('Unable to load the branch workspace.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  });

  test('payment status empty state remains truthful and directional', async ({ page }) => {
    await prepare(page, auditScreen(PAGES[14]), {
      fixtures: [
        { match: /^\/front-office\/workspace$/, body: { data: { overview: null } } },
        { match: /^\/front-office\/payment-status$/, body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } } },
      ],
    });
    await page.goto('/payments/status');
    await expect(page.getByText('No recorded payment groups match this filter.')).toBeVisible();
  });
});

test.describe('responsive, theme, motion, keyboard and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all implemented pages avoid horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of PAGES) {
        await openFrontOffice(page, entry);
        await assertNoHorizontalScroll(page);
      }
      await page.goto('/dashboard');
      await settleVisualEvidence(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `responsive-dashboard-${width}.png`), { animations: 'disabled' });
    });
  }

  test('captures representative mobile transformations without clipped controls', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    for (const entry of [PAGES[0], PAGES[4], PAGES[7], PAGES[8], PAGES[13]]) {
      await openFrontOffice(page, entry);
      await assertNoHorizontalScroll(page);
      await settleVisualEvidence(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `mobile-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
  });

  test('defaults light, persists dark and remains usable at 200% equivalent', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 450 });
    await openFrontOffice(page, PAGES[0]);
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await assertNoHorizontalScroll(page);
    const [footer, reserve] = await Promise.all([
      page.getByTestId('sv-fixed-footer').boundingBox(),
      page.locator('.sv-footer-reserve').evaluate((root) => Number.parseFloat(getComputedStyle(root).paddingBottom)),
    ]);
    expect(footer && reserve >= footer.height).toBe(true);
    await settleVisualEvidence(page);
    await writeEvidenceScreenshot(page, join(SHOTS, 'dashboard-dark-200-percent-equivalent.png'), { animations: 'disabled', fullPage: true });
  });

  test('all implemented pages preserve intentional dark surfaces and accessible contrast', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await openFrontOffice(page, PAGES[0]);
    await page.getByTestId('theme-toggle').first().click();

    for (const entry of PAGES) {
      await openFrontOffice(page, entry);
      await expect(page.locator('html')).toHaveClass(/dark/);
      await assertNoHorizontalScroll(page);
      const results = await new AxeBuilder({ page }).analyze();
      expect(
        results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical'),
        `${entry.screen} dark-theme accessibility`,
      ).toEqual([]);
      await settleVisualEvidence(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-dark-${entry.screen}.png`), { animations: 'disabled' });
    }
  });

  test('honours reduced motion in the operational dashboard', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openFrontOffice(page, PAGES[0]);
    expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
    const transitionSeconds = await page.locator('[data-testid="front-office-dashboard"] article').first()
      .evaluate((node) => Number.parseFloat(getComputedStyle(node).transitionDuration));
    expect(transitionSeconds).toBeLessThanOrEqual(0.00001);
  });

  test('mobile drawer traps focus, closes with Escape and restores its trigger', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await openFrontOffice(page, PAGES[0]);
    const trigger = page.getByTestId('nav-drawer-trigger').first();
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('nav-drawer-close')).toBeVisible();
    await settleVisualEvidence(page);
    await writeEvidenceScreenshot(page, join(SHOTS, 'mobile-navigation.png'), { animations: 'disabled' });
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('nav-drawer-close')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('interactive controls on every implemented page meet the 44px target floor', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    for (const entry of PAGES) {
      await openFrontOffice(page, entry);
      const undersized = await page.locator('main a, main button, main input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), main select, main textarea')
        .evaluateAll((nodes) => nodes
          .filter((node) => {
            const style = getComputedStyle(node);
            return style.display !== 'none' && style.visibility !== 'hidden' && !node.hasAttribute('disabled');
          })
          .map((node) => ({
            label: node.getAttribute('aria-label') ?? node.textContent?.trim() ?? node.tagName,
            height: Math.round(node.getBoundingClientRect().height),
          }))
          .filter((control) => control.height < 44));
      expect(undersized, `${entry.screen} has undersized interactive controls`).toEqual([]);
    }
  });

  for (const entry of PAGES) {
    test(`${entry.screen} has zero serious or critical axe violations`, async ({ page }) => {
      await openFrontOffice(page, entry);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
    });
  }
});

test('writes the deterministic UI-13 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  const byScreen = new Map(PAGES.map((entry) => [entry.screen, entry]));
  const captures = files.map((file) => {
    const bytes = readFileSync(join(SHOTS, file));
    const key = file.replace(/^desktop-(?:light|dark)-/, '').replace(/^mobile-/, '').replace(/\.png$/, '');
    const entry = byScreen.get(key as typeof PAGES[number]['screen']);
    return {
      file: `screenshots/${file}`,
      screen_key: entry?.screen ?? null,
      route: entry?.path ?? null,
      theme: file.includes('dark') ? 'dark' : 'light',
      bytes: statSync(join(SHOTS, file)).size,
      sha256: createHash('sha256').update(bytes).digest('hex'),
    };
  });
  await writeEvidenceFile(resolve(SHOTS, '..', 'screenshot-index.json'), `${JSON.stringify({
    schema: 'servana.ui13.screenshot-index.v1', phase: 'UI-13', account: 'merchant_front_office',
    host: 'office.servana.ke', data_provenance: 'synthetic; no real person, credential, token or provider payload',
    status: 'UI-13 implementation evidence; not a UI-16 release-approved visual baseline', captures,
    totals: { captures: files.length, implemented_page_captures: files.filter((file) => file.startsWith('desktop-light-')).length },
  }, null, 2)}\n`);
});
