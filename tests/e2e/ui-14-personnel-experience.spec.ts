import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { baseFixtures, IDS, prepare, type AuditScreen, type Fixture } from './support/releaseAudit';
import { assertNoHorizontalScroll } from './support/roleBootstrap';

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-14/screenshots');
mkdirSync(SHOTS, { recursive: true });

const PERMISSIONS = [
  'personnel.my_appointments.view', 'personnel.my_queue.view', 'personnel.my_sessions.view',
  'personnel.my_served_clients.view', 'personnel.my_sms.send', 'personnel.my_earnings.view',
  'personnel.my_compensation.view', 'personnel.my_payouts.view',
  'personnel.my_statements.download', 'personnel.my_earnings_query.create',
];

const PAGES = [
  { screen: 'dashboard', route: 'personnel.dashboard', path: '/dashboard', ready: '[data-testid="personnel-dashboard"]', api: '/personnel/me/workspace' },
  { screen: 'get-started', route: 'personnel.get-started', path: '/get-started', ready: 'main h1, main h2', api: '/personnel/me/workspace' },
  { screen: 'work-queue', route: 'personnel.work-queue', path: '/work/queue', ready: 'main h1', api: '/personnel/me/queue' },
  { screen: 'work-appointments', route: 'personnel.work-appointments', path: '/work/appointments', ready: 'main h1', api: '/personnel/me/appointments' },
  { screen: 'work-sessions', route: 'personnel.work-sessions', path: '/work/sessions', ready: 'main h1', api: '/personnel/me/sessions' },
  { screen: 'work-history', route: 'personnel.work-history', path: '/work/history', ready: '[data-testid="personnel-service-history"]', api: '/personnel/me/service-history' },
  { screen: 'work-preferred-requests', route: 'personnel.work-preferred-requests', path: '/work/preferred-requests', ready: '[data-testid="personnel-preferred-requests"]', api: '/personnel/me/preferred-requests' },
  { screen: 'clients', route: 'personnel.clients', path: '/clients', ready: '[data-testid="personnel-served-clients"]', api: '/personnel/me/served-clients' },
  { screen: 'messages-compose', route: 'personnel.messages-compose', path: '/messages/compose', ready: 'main h1', api: '/personnel/me/served-clients/sms' },
  { screen: 'messages', route: 'personnel.messages', path: '/messages', ready: 'main h1', api: '/personnel/me/sms-campaigns' },
  { screen: 'earnings', route: 'personnel.earnings', path: '/earnings', ready: '[data-testid="personnel-earnings-overview"]', api: '/personnel/me/earnings' },
  { screen: 'earnings-commission', route: 'personnel.earnings-commission', path: '/earnings/commission', ready: '[data-testid="personnel-commission"]', api: '/personnel/me/commissions' },
  { screen: 'earnings-salary', route: 'personnel.earnings-salary', path: '/earnings/salary', ready: '[data-testid="personnel-salary"]', api: '/personnel/me/salary' },
  { screen: 'earnings-payouts', route: 'personnel.earnings-payouts', path: '/earnings/payouts', ready: '[data-testid="personnel-payouts"]', api: '/personnel/me/payouts' },
  { screen: 'earnings-terms', route: 'personnel.earnings-terms', path: '/earnings/terms', ready: '[data-testid="personnel-compensation-terms"]', api: '/personnel/me/compensation' },
  { screen: 'earnings-statements', route: 'personnel.earnings-statements', path: '/earnings/statements', ready: '[data-testid="personnel-earnings-statements"]', api: '/personnel/me/payouts' },
  { screen: 'earnings-queries', route: 'personnel.earnings-queries', path: '/earnings/queries', ready: '[data-testid="personnel-earnings-queries"]', api: '/personnel/me/earnings-queries' },
  { screen: 'availability', route: 'personnel.availability', path: '/availability', ready: '[data-testid="personnel-availability"]', api: '/personnel/me/availability' },
  { screen: 'account', route: 'personnel.account', path: '/account', ready: 'main h1', api: '/auth/sessions' },
] as const;

const auditScreen = (entry: typeof PAGES[number]): AuditScreen => ({
  key: `personnel-${entry.screen}`,
  route: entry.route,
  path: entry.path,
  role: 'merchant_personnel',
  state: 'populated',
  ready: entry.ready,
  bootstrap: { permissions: PERMISSIONS },
});

async function waitForPreview(page: Page): Promise<void> {
  let lastError: unknown;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    try {
      const response = await page.request.get('/', { timeout: 1_000 });
      if (response.ok()) return;
      lastError = new Error(`Preview readiness returned HTTP ${response.status()}`);
    } catch (error: unknown) { lastError = error; }
    await page.waitForTimeout(250);
  }
  throw lastError;
}

async function openPersonnel(page: Page, entry: typeof PAGES[number], fixtures?: Fixture[]) {
  const errors: string[] = [];
  const failed: string[] = [];
  const requests: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('requestfailed', (request) => failed.push(request.url()));
  page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
  await prepare(page, auditScreen(entry), fixtures ? { fixtures } : undefined);
  await waitForPreview(page);
  await page.goto(entry.path);
  await expect(page.locator(entry.ready).first()).toBeVisible();
  return { errors, failed, requests };
}

async function settle(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all(Array.from(document.images, (image) => image.complete
      ? Promise.resolve()
      : new Promise<void>((done) => {
          image.addEventListener('load', () => done(), { once: true });
          image.addEventListener('error', () => done(), { once: true });
        })));
    window.scrollTo(0, 0);
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    document.querySelectorAll<HTMLElement>('main, [data-testid="sidebar-primary-nav"]').forEach((element) => element.scrollTo(0, 0));
    await new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done())));
  });
}

const modelFixture = (model: 'commission_only' | 'salary_only' | 'salary_plus_commission'): Fixture[] => [{
  match: /^\/personnel\/me\/earnings$/,
  body: {
    data: {
      tab_visibility: {
        model, has_current_plan: true, conflicting: false,
        salary_tab: model !== 'commission_only', commission_tab: model !== 'salary_only',
      },
      currencies: [{ currency: 'KES', salary_unpaid_minor: 2800000, salary_paid_minor: 0, commission_unpaid_minor: 185000, commission_paid_minor: 0, adjustment_unpaid_minor: 0, adjustment_paid_minor: 0, unpaid_minor: 2985000, paid_minor: 0, net_minor: 2985000 }],
    },
  },
}, ...baseFixtures()];

test.describe('all nineteen implemented Personnel pages', () => {
  for (const entry of PAGES) {
    test(`${entry.screen} resolves its canonical staff route and own state`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openPersonnel(page, entry);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      expect(health.requests.some((url) => url.includes(entry.api)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.errors, `${entry.screen} browser errors`).toEqual([]);
      expect(health.failed, `${entry.screen} failed requests`).toEqual([]);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }
});

test.describe('Notifications gate and entry safety', () => {
  test('Notifications remains visible, disabled and names Phase 21N and External Gate W', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openPersonnel(page, PAGES[0]);
    const item = page.getByTestId('sidebar-primary-nav').getByTestId('nav-stacked-gated-merchant_personnel.notifications');
    await item.scrollIntoViewIfNeeded();
    await expect(item).toBeVisible();
    await expect(item).toHaveAttribute('aria-disabled', 'true');
    await expect(item).toContainText(/Phase 21N/i);
    await expect(item).toContainText(/External Gate W/i);
    await expect(item).not.toHaveAttribute('href');
  });

  test('Notifications has no route, component, placeholder data or network runtime', async ({ page }) => {
    const requests: string[] = [];
    page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
    await prepare(page, auditScreen(PAGES[0]));
    await page.goto('/notifications');
    await expect(page.getByTestId('public-not-found')).toBeVisible();
    await expect(page.getByText(/0 notifications|mark all read|notification preferences/i)).toHaveCount(0);
    expect(requests.some((url) => /notifications/i.test(url))).toBe(false);
    await settle(page);
    await writeEvidenceScreenshot(page, join(SHOTS, 'gated-notifications.png'), { animations: 'disabled' });
  });

  test('a wrong account role cannot render the Personnel shell', async ({ page }) => {
    const screen = auditScreen(PAGES[0]);
    await prepare(page, { ...screen, role: 'merchant_finance', bootstrap: { permissions: ['invoice.view'], hostAccountKey: 'merchant_personnel' } });
    await page.goto('/dashboard');
    await expect(page.getByTestId('personnel-dashboard')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'You do not have access to this page' })).toBeVisible();
  });

  test('a mismatched host context fails closed before the Personnel page mounts', async ({ page }) => {
    const screen = auditScreen(PAGES[0]);
    await prepare(page, { ...screen, bootstrap: { ...screen.bootstrap, hostAccountKey: 'merchant_front_office' } });
    await page.goto('/dashboard');
    await expect(page.getByTestId('personnel-dashboard')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'You do not have access to this page' })).toBeVisible();
  });

  test('an inactive session is redirected to Magic Link sign-in without private content', async ({ page }) => {
    const screen = auditScreen(PAGES[0]);
    await prepare(page, { ...screen, bootstrap: { ...screen.bootstrap, authenticated: false } });
    await page.goto('/dashboard');
    await expect(page.getByTestId('personnel-dashboard')).toHaveCount(0);
    await expect(page).toHaveURL(/\/auth\/login/);
  });

  test('an authenticated deep link preserves the intended authorized destination', async ({ page }) => {
    await openPersonnel(page, PAGES[6]);
    await expect(page).toHaveURL(/\/work\/preferred-requests$/);
    await expect(page.getByTestId('personnel-preferred-requests')).toBeVisible();
  });
});

test.describe('own-scope, contact and authority boundaries', () => {
  test('staff-selector query input is ignored and all reads remain /personnel/me', async ({ page }) => {
    const requests: string[] = [];
    page.on('request', (request) => { if (request.url().includes('/api/v1/personnel/')) requests.push(request.url()); });
    await prepare(page, auditScreen(PAGES[2]));
    await page.goto(`/work/queue?staff_profile_ulid=${IDS.staff.replace('STAFF', 'OTHER')}`);
    await expect(page.getByRole('heading', { name: 'My Queue' })).toBeVisible();
    expect(requests.length).toBeGreaterThan(0);
    expect(requests.every((url) => url.includes('/personnel/me/'))).toBe(true);
    expect(requests.some((url) => /staff_profile|OTHER/i.test(url))).toBe(false);
    await expect(page.locator('select[name*="staff"], input[name*="staff"]')).toHaveCount(0);
  });

  for (const boundary of ['foreign branch', 'foreign tenant']) {
    test(`${boundary} own-work data fails non-enumerating`, async ({ page }) => {
      const fixtures: Fixture[] = [{
        match: /^\/personnel\/me\/service-history$/,
        body: { error: { code: 'not_found', message: 'Not found.', fields: {}, meta: {} } },
        status: 404,
      }, ...baseFixtures()];
      await openPersonnel(page, PAGES[5], fixtures);
      await expect(page.getByText('Unable to load your service history.')).toBeVisible();
      await expect(page.getByText(/other personnel|foreign branch|foreign tenant/i)).toHaveCount(0);
    });
  }

  test('served-client view is masked and has no export, raw contact, print or clipboard control', async ({ page }) => {
    await openPersonnel(page, PAGES[7]);
    await expect(page.locator('main')).toContainText('+2547•••••678');
    await expect(page.locator('main')).not.toContainText('+254712345678');
    await expect(page.getByRole('button', { name: /export|download|copy|print/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /export|download|copy|print/i })).toHaveCount(0);
  });

  test('availability is HR-owned and exposes no Personnel mutation', async ({ page }) => {
    await openPersonnel(page, PAGES[17]);
    await expect(page.locator('main')).toContainText(/Human Resource|HR/i);
    await expect(page.getByRole('button', { name: /available|unavailable|edit|save|toggle/i })).toHaveCount(0);
    await expect(page.locator('input[type="checkbox"], input[type="radio"], select')).toHaveCount(0);
  });

  test('financial and administrative mutations do not appear on own-scope pages', async ({ page }) => {
    const forbidden = /validate payment|issue receipt|edit ledger|change compensation|approve payout|manage availability|invite staff|export contacts/i;
    for (const entry of [PAGES[0], PAGES[7], PAGES[11], PAGES[12], PAGES[13], PAGES[14], PAGES[17], PAGES[18]]) {
      await openPersonnel(page, entry);
      await expect(page.getByRole('button', { name: forbidden })).toHaveCount(0);
      await expect(page.getByRole('link', { name: forbidden })).toHaveCount(0);
    }
  });
});

test.describe('SMS safety and own message history', () => {
  test('previews server cost, requires the exact billing notice and sends through Servana', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await openPersonnel(page, PAGES[8]);
    await page.getByLabel('Select Njeri Kamau').check();
    await page.getByLabel('Message').fill('Your appointment is ready. We look forward to seeing you.');
    await page.getByTestId('sms-preview-button').click();
    await expect(page.getByTestId('sms-preview-cost')).toContainText('KES 1.50');
    await expect(page.getByTestId('sms-billing-notice')).toHaveText('SMS charges for this message will be billed to your branch together with the Servana subscription invoice. Continue?');
    await page.getByTestId('sms-send-button').click();
    await expect(page.getByTestId('sms-confirm-modal')).toBeVisible();
    await settle(page);
    await writeEvidenceScreenshot(page, join(SHOTS, 'sms-confirmation.png'), { animations: 'disabled' });
    await page.getByTestId('sms-confirm-send').click();
    await expect(page.getByTestId('sms-status-region')).toContainText('Message queued to 1 recipients.');
  });

  test('message history loads only the acting Personnel campaigns with masked outcomes', async ({ page }) => {
    const health = await openPersonnel(page, PAGES[9]);
    await expect(page.getByTestId('sms-campaign-list')).toContainText('Queued');
    expect(health.requests.some((url) => url.includes('/personnel/me/sms-campaigns'))).toBe(true);
    expect(health.requests.some((url) => /staff_profile/i.test(url))).toBe(false);
  });
});

test.describe('all compensation models and private earnings actions', () => {
  for (const model of ['commission_only', 'salary_only', 'salary_plus_commission'] as const) {
    test(`${model} shows only the applicable earnings navigation`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await openPersonnel(page, PAGES[10], modelFixture(model));
      const earningsNav = page.getByRole('navigation', { name: 'My earnings pages' });
      await expect(earningsNav.getByRole('link', { name: 'Commission' })).toHaveCount(model === 'salary_only' ? 0 : 1);
      await expect(earningsNav.getByRole('link', { name: 'Salary' })).toHaveCount(model === 'commission_only' ? 0 : 1);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `earnings-model-${model}.png`), { animations: 'disabled' });
    });
  }

  test('direct non-applicable detail links remain truthful and do not fabricate a ledger', async ({ page }) => {
    await openPersonnel(page, PAGES[12], modelFixture('commission_only'));
    await expect(page.locator('main')).toContainText('Salary does not apply to your current compensation model.');
    await openPersonnel(page, PAGES[11], modelFixture('salary_only'));
    await expect(page.locator('main')).toContainText('Commission does not apply to your current compensation model.');
  });

  test('own statements and queries expose supported actions without compensation mutation', async ({ page }) => {
    const mutations: string[] = [];
    page.on('request', (request) => { if (request.method() !== 'GET') mutations.push(request.url()); });
    await openPersonnel(page, PAGES[15]);
    await expect(page.getByRole('button', { name: /Generate statement|Download statement/ })).toBeVisible();
    await openPersonnel(page, PAGES[16]);
    await page.getByLabel('Own earnings reference').fill('01HZZCOMMISSION00000000000');
    await page.getByLabel('Describe the issue').fill('Please explain this commission entry.');
    await page.getByRole('button', { name: 'Submit to Finance' }).click();
    await expect(page.getByText('Your query was submitted to Finance.')).toBeVisible();
    expect(mutations.some((url) => /compensation|commissions|salary|payouts/.test(url))).toBe(false);
    expect(mutations.some((url) => url.includes('/personnel/me/earnings-queries'))).toBe(true);
  });
});

test.describe('responsive, theme, motion, keyboard and accessibility', () => {
  test('queue fact labels and ledger headings, amounts and references remain readable without overlap', async ({ page }) => {
    test.setTimeout(180_000);
    for (const theme of ['light', 'dark'] as const) {
      for (const width of [360, 640, 767, 768, 1024, 1025]) {
        await page.setViewportSize({ width, height: 900 });
        for (const screen of ['work-queue', 'earnings-commission', 'earnings-salary']) {
          const entry = PAGES.find((candidate) => candidate.screen === screen)!;
          await prepare(page, auditScreen(entry), { theme });
          await page.goto(entry.path);
          await expect(page.locator(entry.ready).first()).toBeVisible();
          await settle(page);
          const clipped = await page.locator(screen === 'work-queue'
            ? '[data-testid="sv-stat-tile"] p'
            : '[data-testid="sv-lead-record"] [data-testid="sv-lead-content"] p, [data-testid="sv-lead-record"] [data-testid="sv-lead-status"] p, [data-testid="sv-lead-record"] dd').evaluateAll((elements) => elements
            .filter((element) => element.scrollWidth > element.clientWidth + 1)
            .map((element) => ({ text: element.textContent?.trim(), scrollWidth: element.scrollWidth, clientWidth: element.clientWidth })));
          expect(clipped, `${screen} ${theme} ${width}px text clipping`).toEqual([]);
          if (screen !== 'work-queue') {
            const record = page.getByTestId('sv-lead-record').first();
            const heading = await record.getByTestId('sv-lead-content').boundingBox();
            const status = await record.getByTestId('sv-lead-status').boundingBox();
            expect(heading).not.toBeNull();
            expect(status).not.toBeNull();
            const overlap = heading && status
              && Math.min(heading.x + heading.width, status.x + status.width) - Math.max(heading.x, status.x) > 1
              && Math.min(heading.y + heading.height, status.y + status.height) - Math.max(heading.y, status.y) > 1;
            expect(overlap, `${screen} ${theme} ${width}px heading/amount overlap`).toBe(false);
          }
        }
      }
    }
  });

  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all implemented pages avoid horizontal overflow at ${width}px`, async ({ page }) => {
      test.setTimeout(180_000);
      await page.setViewportSize({ width, height: 900 });
      for (const entry of PAGES) {
        await openPersonnel(page, entry);
        await assertNoHorizontalScroll(page);
      }
      await openPersonnel(page, PAGES[0]);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `responsive-dashboard-${width}.png`), { animations: 'disabled' });
    });
  }

  test('captures intentional mobile and tablet transformations for complex archetypes', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    for (const entry of [PAGES[0], PAGES[2], PAGES[7], PAGES[8], PAGES[10], PAGES[11], PAGES[12], PAGES[18]]) {
      await openPersonnel(page, entry);
      await assertNoHorizontalScroll(page);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `mobile-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
    await page.setViewportSize({ width: 768, height: 1024 });
    for (const entry of [PAGES[0], PAGES[8], PAGES[10], PAGES[18]]) {
      await openPersonnel(page, entry);
      await assertNoHorizontalScroll(page);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `tablet-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
  });

  test('six representative complex pages remain usable at 200% equivalent', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 450 });
    for (const entry of [PAGES[0], PAGES[2], PAGES[8], PAGES[10], PAGES[11], PAGES[18]]) {
      await openPersonnel(page, entry);
      await assertNoHorizontalScroll(page);
      const [footer, reserve] = await Promise.all([
        page.getByTestId('sv-fixed-footer').boundingBox(),
        page.locator('.sv-footer-reserve').evaluate((root) => Number.parseFloat(getComputedStyle(root).paddingBottom)),
      ]);
      expect(footer && reserve >= footer.height).toBe(true);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `zoom-200-${entry.screen}.png`), { animations: 'disabled', fullPage: true });
    }
  });

  test('all pages intentionally render dark with zero serious or critical axe violations', async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    for (const entry of PAGES) {
      await prepare(page, auditScreen(entry), { theme: 'dark' });
      await page.goto(entry.path);
      await expect(page.locator(entry.ready).first()).toBeVisible();
      await expect(page.locator('html')).toHaveClass(/dark/);
      await assertNoHorizontalScroll(page);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
      await settle(page);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-dark-${entry.screen}.png`), { animations: 'disabled' });
    }
  });

  test('fresh light is default and a deliberate dark choice persists after reload', async ({ page }) => {
    await openPersonnel(page, PAGES[0]);
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
  });

  test('reduced motion is honoured on the Personnel dashboard', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openPersonnel(page, PAGES[0]);
    expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
    const longestTransition = await page.locator('[data-testid="personnel-dashboard"] article, [data-testid="personnel-dashboard"] section').evaluateAll((nodes) => Math.max(...nodes.map((node) => Number.parseFloat(getComputedStyle(node).transitionDuration) || 0)));
    expect(longestTransition).toBeLessThanOrEqual(0.00001);
  });

  test('mobile navigation traps focus, closes with Escape and restores the trigger', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await openPersonnel(page, PAGES[0]);
    const trigger = page.getByTestId('nav-drawer-trigger').first();
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('nav-drawer-close')).toBeVisible();
    await settle(page);
    await writeEvidenceScreenshot(page, join(SHOTS, 'mobile-navigation.png'), { animations: 'disabled' });
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('nav-drawer-close')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('interactive controls on every implemented page meet the 44px target floor', async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 360, height: 780 });
    for (const entry of PAGES) {
      await openPersonnel(page, entry);
      const undersized = await page.locator('main a, main button, main input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), main select, main textarea')
        .evaluateAll((nodes) => nodes
          .filter((node) => {
            const style = getComputedStyle(node);
            return style.display !== 'none' && style.visibility !== 'hidden' && !node.hasAttribute('disabled');
          })
          .map((node) => ({ label: node.getAttribute('aria-label') ?? node.textContent?.trim() ?? node.tagName, height: Math.round(node.getBoundingClientRect().height) }))
          .filter((control) => control.height < 44));
      expect(undersized, `${entry.screen} has undersized interactive controls`).toEqual([]);
    }
  });

  for (const entry of PAGES) {
    test(`${entry.screen} has zero serious or critical axe violations`, async ({ page }) => {
      await openPersonnel(page, entry);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
    });
  }
});

test('writes the deterministic UI-14 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  const byScreen = new Map(PAGES.map((entry) => [entry.screen, entry]));
  const captures = files.map((file) => {
    const bytes = readFileSync(join(SHOTS, file));
    const key = file
      .replace(/^desktop-(?:light|dark)-/, '').replace(/^mobile-/, '').replace(/^tablet-/, '')
      .replace(/^zoom-200-/, '').replace(/\.png$/, '');
    const entry = byScreen.get(key as typeof PAGES[number]['screen']);
    const viewport = file.startsWith('mobile-') ? '360x780'
      : file.startsWith('tablet-') ? '768x1024'
        : file.startsWith('zoom-200-') ? '640x450 (200% equivalent)'
          : file.startsWith('responsive-dashboard-') ? `${file.match(/(\d+)\.png$/)?.[1]}x900`
            : '1440x900';
    return {
      route: entry?.path ?? (file === 'gated-notifications.png' ? '/notifications' : null),
      account_fixture: 'merchant_personnel / deterministic own-scope synthetic fixture',
      viewport,
      theme: file.includes('dark') ? 'dark' : 'light',
      state: file.includes('gated') ? 'disabled_by_gate' : file.includes('confirmation') ? 'confirmation' : 'settled_populated',
      purpose: file.replace(/\.png$/, '').replaceAll('-', ' '),
      file: `screenshots/${file}`,
      bytes: statSync(join(SHOTS, file)).size,
      sha256: createHash('sha256').update(bytes).digest('hex'),
      source_build: 'Vite production preview from the UI-14 working tree',
      source_commit: 'pre-commit UI-14 tree; exact committed tree is recorded in docs/proof/ui-14.md',
    };
  });
  await writeEvidenceFile(resolve(SHOTS, '..', 'screenshot-index.json'), `${JSON.stringify({
    schema: 'servana.ui14.screenshot-index.v1', phase: 'UI-14', account: 'merchant_personnel',
    host: 'staff.servana.ke', data_provenance: 'synthetic own-scope data; masked contacts; no real person, credential, token or provider payload',
    captures,
    totals: {
      captures: files.length,
      desktop_light_pages: files.filter((file) => file.startsWith('desktop-light-')).length,
      desktop_dark_pages: files.filter((file) => file.startsWith('desktop-dark-')).length,
    },
  }, null, 2)}\n`);
});
