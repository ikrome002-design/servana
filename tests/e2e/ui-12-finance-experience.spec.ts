import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { IDS, prepare, type AuditScreen } from './support/releaseAudit';
import { assertNoHorizontalScroll } from './support/roleBootstrap';

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-12/screenshots');
mkdirSync(SHOTS, { recursive: true });

const PAGES = [
  { screen: 'dashboard', route: 'finance.dashboard', path: '/dashboard', testId: 'finance-dashboard', api: '/finance/workspace' },
  { screen: 'get-started', route: 'finance.get-started', path: '/get-started', testId: null, api: '/finance/workspace' },
  { screen: 'tasks', route: 'finance.tasks', path: '/tasks', testId: 'finance-task-inbox', api: '/finance/workspace' },
  { screen: 'payments-validations', route: 'finance.payments-validations', path: '/payments/validations', testId: 'finance-pending-validations', api: '/payment-recording-groups' },
  { screen: 'payments-validation-detail', route: 'finance.payments-validation-detail', path: `/payments/validations/${IDS.paymentGroup}`, testId: 'finance-payment-validation-detail', api: `/payment-recording-groups/${IDS.paymentGroup}` },
  { screen: 'payments-duplicates', route: 'finance.payments-duplicates', path: '/payments/duplicates', testId: 'finance-duplicate-review', api: '/finance/duplicate-references' },
  { screen: 'invoices', route: 'finance.invoices', path: '/invoices', testId: null, api: '/invoices' },
  { screen: 'payments', route: 'finance.payments', path: '/payments', testId: 'finance-payment-records', api: '/payment-recording-groups' },
  { screen: 'payments-partial-split', route: 'finance.payments-partial-split', path: '/payments/partial-split', testId: 'finance-partial-split-payments', api: '/finance/partial-split-payments' },
  { screen: 'receipts', route: 'finance.receipts', path: '/receipts', testId: null, api: '/receipts' },
  { screen: 'disputes', route: 'finance.disputes', path: '/disputes', testId: null, api: '/finance-disputes' },
  { screen: 'refunds', route: 'finance.refunds', path: '/refunds', testId: null, api: '/refunds' },
  { screen: 'cash-up', route: 'finance.cash-up', path: '/cash-up', testId: null, api: '/cash-ups' },
  { screen: 'periods', route: 'finance.periods', path: '/periods', testId: null, api: '/period-locks' },
  { screen: 'payouts', route: 'finance.payouts', path: '/payouts', testId: null, api: '/finance/payout-runs' },
  { screen: 'compensation-liabilities', route: 'finance.compensation-liabilities', path: '/compensation/liabilities', testId: null, api: '/compensation/liabilities/summary' },
  { screen: 'compensation-queries', route: 'finance.compensation-queries', path: '/compensation/queries', testId: null, api: '/finance/earnings-queries' },
  { screen: 'exports', route: 'finance.exports', path: '/exports', testId: null, api: '/finance-exports' },
  { screen: 'audit', route: 'finance.audit', path: '/audit', testId: null, api: '/audit-logs/finance' },
  { screen: 'settings', route: 'finance.settings', path: '/settings', testId: 'finance-settings', api: '/auth/sessions' },
] as const;

const GATED = [
  { key: 'merchant_finance.subscription', path: '/subscription', label: 'Subscription Billing' },
  { key: 'merchant_finance.subscription-payment-attempts', path: '/subscription/payment-attempts', label: 'Subscription Payment Attempts' },
  { key: 'merchant_finance.reports', path: '/reports', label: 'Finance Reports' },
  { key: 'merchant_finance.notifications', path: '/notifications', label: 'Notifications' },
] as const;

const FINANCE_PERMISSIONS = [
  'customer_payment.view', 'customer_payment.validate', 'customer_payment.reject',
  'customer_payment.reference_correct', 'customer_payment.duplicate_override',
  'invoice.view', 'receipt.view', 'receipt.download', 'receipt.reissue',
  'finance_dispute.manage', 'refund.create', 'refund.approve', 'refund.finalize',
  'cash_up.view', 'cash_up.approve', 'cash_up.reject',
  'period_lock.create', 'period_lock.reopen',
  'payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid',
  'compensation.liability.view', 'compensation.adjustment.create', 'earnings_query.respond',
  'finance_export.create', 'finance_export.download', 'finance.audit.view',
];

const auditScreen = (entry: typeof PAGES[number]): AuditScreen => ({
  key: entry.screen,
  route: entry.route,
  path: entry.path,
  role: 'merchant_finance',
  state: 'populated',
  ready: entry.testId ? `[data-testid="${entry.testId}"]` : 'main h1, main h2',
  bootstrap: { permissions: FINANCE_PERMISSIONS },
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

async function openFinance(page: Page, entry: typeof PAGES[number]) {
  const errors: string[] = [];
  const failed: string[] = [];
  const requests: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('requestfailed', (request) => failed.push(request.url()));
  page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
  const screen = auditScreen(entry);
  await prepare(page, screen);
  // A freshly built Vite preview can briefly close its listener while handing off from the
  // readiness probe. Keep that infrastructure race outside product-health observations.
  await waitForPreview(page);
  await page.goto(entry.path);
  await expect(page.locator(screen.ready ?? 'main h1').first()).toBeVisible();
  return { errors, failed, requests };
}

test.describe('all twenty implemented Finance pages', () => {
  for (const entry of PAGES) {
    test(`${entry.screen} resolves its canonical screen and server-owned state`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openFinance(page, entry);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      await expect(page.getByTestId('branch-context')).toContainText('Westlands Branch');
      expect(health.requests.some((url) => url.includes(entry.api)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.errors, `${entry.screen} browser errors`).toEqual([]);
      expect(health.failed, `${entry.screen} failed requests`).toEqual([]);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }
});

test.describe('four gated Finance contract pages', () => {
  test('are discoverable, disabled and dependency-specific in the seven-group navigation', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openFinance(page, PAGES[0]);
    const nav = page.getByTestId('sidebar-primary-nav');
    await expect(nav.locator('section')).toHaveCount(7);
    await expect(nav.locator('section > p')).toHaveText([
      'Home',
      'Merchant-Client Finance',
      'Controls & Close',
      'Compensation Finance',
      'Subscription Finance',
      'Reporting & Audit',
      'Utility',
    ]);
    for (const entry of GATED) {
      const item = nav.getByTestId(`nav-stacked-gated-${entry.key}`);
      await expect(item).toBeVisible();
      await expect(item).toHaveAttribute('aria-disabled', 'true');
      await expect(item).toContainText('External Gate W');
      await expect(item).not.toHaveAttribute('href');
    }
  });

  for (const entry of GATED) {
    test(`${entry.label} has no route, component or network runtime`, async ({ page }) => {
      const requests: string[] = [];
      page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
      await prepare(page, auditScreen(PAGES[0]));
      await page.goto(entry.path);
      await expect(page.getByTestId('public-not-found')).toBeVisible();
      await expect(page.getByText(/coming soon|0 notifications|provider status/i)).toHaveCount(0);
      expect(requests.some((url) => /subscription|payment-attempt|reports|notifications/.test(url))).toBe(false);
    });
  }
});

test.describe('Finance authority and workflow boundaries', () => {
  test('wrong account fails closed', async ({ page }) => {
    const dashboard = auditScreen(PAGES[0]);
    await prepare(page, { ...dashboard, role: 'merchant_human_resource', bootstrap: { permissions: ['staff.view'] } });
    await page.goto('/dashboard');
    await expect(page.getByTestId('finance-dashboard')).toHaveCount(0);
  });

  test('keeps invoice creation, provider settlement and manual receipt issuance absent', async ({ page }) => {
    for (const entry of [PAGES[6], PAGES[14], PAGES[19]]) {
      await openFinance(page, entry);
      await expect(page.getByRole('button', { name: /new invoice|send money|settle through provider|issue receipt/i })).toHaveCount(0);
      await expect(page.getByRole('link', { name: /new invoice|send money|settle through provider|issue receipt/i })).toHaveCount(0);
    }
  });

  test('duplicate override preserves masking and requires an explicit reason', async ({ page }) => {
    await openFinance(page, PAGES[5]);
    await expect(page.locator('main')).toContainText('••••••1ABC');
    await expect(page.locator('main')).not.toContainText('QGX7YT1ABC');
    await page.getByRole('button', { name: 'Review override' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText(/fresh step-up|preserves the original reference/i);
    await expect(dialog.getByRole('button', { name: 'Override and release' })).toBeDisabled();
  });
});

test.describe('responsive, theme, motion, keyboard and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all Finance pages avoid horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of PAGES) {
        await openFinance(page, entry);
        await assertNoHorizontalScroll(page);
      }
      await writeEvidenceScreenshot(page, join(SHOTS, `responsive-${width}.png`), { animations: 'disabled' });
    });
  }

  test('defaults light, persists dark and keeps the fixed footer clear at 200% equivalent', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 450 });
    await openFinance(page, PAGES[0]);
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByTestId('theme-toggle').first().click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await assertNoHorizontalScroll(page);
    const [footer, reserve] = await Promise.all([
      page.locator('footer').boundingBox(),
      page.locator('.sv-footer-reserve').evaluate((root) => Number.parseFloat(getComputedStyle(root).paddingBottom)),
    ]);
    expect(footer && reserve >= footer.height).toBe(true);
    await writeEvidenceScreenshot(page, join(SHOTS, 'dashboard-dark-200-percent-equivalent.png'), { animations: 'disabled' });
  });

  test('honours reduced-motion preference in the Finance task surface', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openFinance(page, PAGES[2]);
    expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
    const transitionSeconds = await page.locator('[data-testid="finance-task-inbox"] article').first().evaluate((node) => Number.parseFloat(getComputedStyle(node).transitionDuration));
    expect(transitionSeconds).toBeLessThanOrEqual(0.00001);
  });

  test('mobile drawer is keyboard reachable and Escape restores focus', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await openFinance(page, PAGES[0]);
    const trigger = page.getByTestId('nav-drawer-trigger').first();
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('nav-drawer-close')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('nav-drawer-close')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  for (const entry of PAGES) {
    test(`${entry.screen} has zero serious or critical axe violations`, async ({ page }) => {
      await openFinance(page, entry);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
    });
  }
});

test('writes the UI-12 screenshot evidence index', async () => {
  const files = readdirSync(SHOTS).filter((file) => file.endsWith('.png')).sort();
  const byScreen = new Map(PAGES.map((entry) => [entry.screen, entry]));
  const captures = files.map((file) => {
    const bytes = readFileSync(join(SHOTS, file));
    const key = file.replace(/^desktop-light-/, '').replace(/\.png$/, '');
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
    schema: 'servana.ui12.screenshot-index.v1', phase: 'UI-12', account: 'merchant_finance',
    host: 'finance.servana.ke', data_provenance: 'synthetic; no real person, credential, token or provider payload',
    status: 'UI-12 implementation evidence; not a UI-16 release-approved visual baseline', captures,
    totals: { captures: files.length, implemented_page_captures: files.filter((file) => file.startsWith('desktop-light-')).length },
  }, null, 2)}\n`);
});
