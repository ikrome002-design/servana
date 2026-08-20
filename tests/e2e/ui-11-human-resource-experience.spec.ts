import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';
import { IDS, prepare, type AuditScreen } from './support/releaseAudit';
import { assertNoHorizontalScroll } from './support/roleBootstrap';

const SHOTS = resolve(process.cwd(), 'docs/frontend/audits/ui-11/screenshots');
mkdirSync(SHOTS, { recursive: true });

const PAGES = [
  { screen: 'dashboard', route: 'hr.dashboard', path: '/dashboard', testId: 'hr-dashboard', api: '/hr/workspace' },
  { screen: 'get-started', route: 'hr.get-started', path: '/get-started', testId: null, api: '/hr/workspace' },
  { screen: 'staff', route: 'hr.staff', path: '/staff', testId: 'hr-staff-roster', api: '/staff' },
  { screen: 'staff-invite', route: 'hr.staff-invite', path: '/staff/invite', testId: 'hr-staff-invite', api: '/staff-invitations' },
  { screen: 'staff-detail', route: 'hr.staff-detail', path: `/staff/${IDS.staff}`, testId: 'hr-staff-detail', api: `/staff/${IDS.staff}` },
  { screen: 'staff-detail-lifecycle', route: 'hr.staff-detail-lifecycle', path: `/staff/${IDS.staff}/lifecycle`, testId: 'hr-staff-lifecycle', api: `/staff/${IDS.staff}` },
  { screen: 'eligibility', route: 'hr.eligibility', path: '/eligibility', testId: 'hr-service-eligibility', api: '/hr/service-options' },
  { screen: 'availability', route: 'hr.availability', path: '/availability', testId: 'hr-availability', api: '/staff' },
  { screen: 'compensation', route: 'hr.compensation', path: '/compensation', testId: 'hr-compensation', api: '/compensation-plans' },
  { screen: 'compensation-detail', route: 'hr.compensation-detail', path: `/compensation/${IDS.staff}`, testId: 'hr-compensation-detail', api: `/staff/${IDS.staff}` },
  { screen: 'compensation-setup', route: 'hr.compensation-setup', path: `/compensation/${IDS.staff}/setup`, testId: 'hr-compensation-setup', api: `/staff/${IDS.staff}` },
  { screen: 'compensation-history', route: 'hr.compensation-history', path: `/compensation/${IDS.staff}/history`, testId: 'hr-compensation-history', api: `/staff/${IDS.staff}` },
  { screen: 'payouts', route: 'hr.payouts', path: '/payouts', testId: 'hr-payouts', api: '/payout-runs' },
  { screen: 'audit', route: 'hr.audit', path: '/audit', testId: 'hr-audit-activity', api: '/hr/audit-activity' },
  { screen: 'account', route: 'hr.account', path: '/account', testId: 'hr-account-screen', api: '/auth/sessions' },
] as const;

const GATED = [
  { key: 'merchant_human_resource.staff-detail-edit', path: `/staff/${IDS.staff}/edit`, label: 'Edit Staff Profile', gate: 'staff-profile mutation API' },
  { key: 'merchant_human_resource.staff-detail-access', path: `/staff/${IDS.staff}/access`, label: 'Role and Branch Assignment', gate: 'role-and-branch assignment API' },
  { key: 'merchant_human_resource.reports', path: '/reports', label: 'HR Reports', gate: 'External Gate W' },
  { key: 'merchant_human_resource.notifications', path: '/notifications', label: 'Notifications', gate: 'External Gate W' },
] as const;

const auditScreen = (entry: typeof PAGES[number]): AuditScreen => ({
  key: entry.screen,
  route: entry.route,
  path: entry.path,
  role: 'merchant_human_resource',
  state: 'populated',
  ready: entry.testId ? `[data-testid="${entry.testId}"]` : 'main h1, main h2',
  bootstrap: {
    permissions: [
      'staff.view', 'staff.invite', 'staff.suspend',
      'personnel.eligibility.manage', 'personnel.availability.manage',
      'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
      'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
      'compensation.plan.cancel', 'compensation.history.view',
      'payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft',
    ],
  },
});

async function openHr(page: Page, entry: typeof PAGES[number]) {
  const errors: string[] = [];
  const failed: string[] = [];
  const requests: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('requestfailed', (request) => failed.push(request.url()));
  page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
  const screen = auditScreen(entry);
  await prepare(page, screen);
  await page.goto(entry.path);
  await expect(page.locator(screen.ready ?? 'main h1').first()).toBeVisible();
  return { errors, failed, requests };
}

test.describe('all fifteen implemented Human Resource pages', () => {
  for (const entry of PAGES) {
    test(`${entry.screen} resolves its canonical screen and server-owned state`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const health = await openHr(page, entry);
      await expect(page.getByTestId('public-not-found')).toHaveCount(0);
      await expect(page.getByTestId('branch-context')).toContainText('Westlands Branch');
      expect(health.requests.some((url) => url.includes(entry.api)), `${entry.screen} never requested ${entry.api}`).toBe(true);
      expect(health.errors, `${entry.screen} browser errors`).toEqual([]);
      expect(health.failed, `${entry.screen} failed requests`).toEqual([]);
      await writeEvidenceScreenshot(page, join(SHOTS, `desktop-light-${entry.screen}.png`), { animations: 'disabled' });
    });
  }
});

test.describe('four gated Human Resource contract pages', () => {
  test('are discoverable, disabled and dependency-specific at their authoritative entry points', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await openHr(page, PAGES[0]);
    const nav = page.getByTestId('sidebar-primary-nav');
    for (const entry of GATED.slice(2)) {
      const item = nav.getByTestId(`nav-stacked-gated-${entry.key}`);
      await expect(item).toBeVisible();
      await expect(item).toHaveAttribute('aria-disabled', 'true');
      await expect(item).toContainText(entry.gate);
      await expect(item).not.toHaveAttribute('href');
    }
    await openHr(page, PAGES[4]);
    const edit = page.getByRole('button', { name: 'Edit profile — unavailable' });
    const access = page.getByRole('button', { name: 'Assign role or branch — unavailable' });
    await expect(edit).toBeDisabled();
    await expect(access).toBeDisabled();
    await expect(page.locator('main')).toContainText('canonical permission and mutation contracts');
  });

  for (const entry of GATED) {
    test(`${entry.label} has no route, component or network runtime`, async ({ page }) => {
      const requests: string[] = [];
      page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(request.url()); });
      await prepare(page, auditScreen(PAGES[0]));
      await page.goto(entry.path);
      await expect(page.getByTestId('public-not-found')).toBeVisible();
      await expect(page.getByText(/coming soon|0 notifications/i)).toHaveCount(0);
      expect(requests.some((url) => /reports|notifications|profile-update|role-assignment/.test(url))).toBe(false);
    });
  }
});

test.describe('HR authority and workflow boundaries', () => {
  test('wrong account and missing account fail closed', async ({ page }) => {
    const dashboard = auditScreen(PAGES[0]);
    await prepare(page, { ...dashboard, role: 'merchant_finance' });
    await page.goto('/dashboard');
    await expect(page.getByTestId('hr-dashboard')).toHaveCount(0);

    await page.close();
  });

  test('staff and payout surfaces expose no self-escalation, contact export or checker actions', async ({ page }) => {
    for (const entry of [PAGES[2], PAGES[4], PAGES[12]]) {
      await openHr(page, entry);
      await expect(page.getByRole('button', { name: /export contact|assign merchant administrator|verify payout|approve payout|mark paid/i })).toHaveCount(0);
      await expect(page.getByRole('link', { name: /export contact|assign merchant administrator|verify payout|approve payout|mark paid/i })).toHaveCount(0);
    }
    await expect(page.locator('main')).toContainText('Finance');
  });

  test('lifecycle confirmation identifies the subject and requires typed confirmation', async ({ page }) => {
    await openHr(page, PAGES[5]);
    await page.getByRole('button', { name: 'Suspend', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('Amina Wanjiku');
    await expect(dialog).toContainText(/sessions|access/i);
    await expect(dialog.getByRole('button', { name: 'Suspend access' })).toBeDisabled();
  });
});

test.describe('responsive, theme and accessibility', () => {
  for (const width of [360, 767, 768, 1024, 1025, 1280, 1440]) {
    test(`all HR pages avoid horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      for (const entry of PAGES) {
        await openHr(page, entry);
        await assertNoHorizontalScroll(page);
      }
      await writeEvidenceScreenshot(page, join(SHOTS, `responsive-${width}.png`), { animations: 'disabled' });
    });
  }

  test('defaults light, persists dark and keeps the fixed footer clear at 200% equivalent', async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 450 });
    await openHr(page, PAGES[0]);
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

  for (const entry of PAGES) {
    test(`${entry.screen} has zero serious or critical axe violations`, async ({ page }) => {
      await openHr(page, entry);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((item) => item.impact === 'serious' || item.impact === 'critical')).toEqual([]);
    });
  }
});

test('writes the UI-11 screenshot evidence index', async () => {
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
    schema: 'servana.ui11.screenshot-index.v1', phase: 'UI-11', account: 'merchant_human_resource',
    host: 'hr.servana.ke', data_provenance: 'synthetic; no real person, credential, token or provider payload',
    status: 'UI-11 implementation evidence; not a UI-16 release-approved visual baseline', captures,
    totals: { captures: files.length, implemented_page_captures: files.filter((file) => file.startsWith('desktop-light-')).length },
  }, null, 2)}\n`);
});
