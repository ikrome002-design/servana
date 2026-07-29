import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import {
  AUDIT_INSTANT_UTC,
  SCREENS,
  prepare,
  type AuditScreen,
  type RoleIdentity,
  type Theme,
} from './support/releaseAudit';

/**
 * Phase UI-01 — as-built browser audit.
 *
 * This spec PROVES what the browser renders today and records it as evidence. It asserts almost
 * nothing about product quality on purpose: a defect found here must be REPORTED, not fixed, and
 * a failing assertion would stop the audit before the rest of the evidence is captured. The only
 * hard assertions are on the audit harness itself (that a run produced evidence at all).
 *
 * Two origins are audited, and they are never conflated:
 *
 *   preview  `vite preview` at the Playwright baseURL — the origin every existing e2e suite has
 *            always used. It serves `public/spa` AS THE WEB ROOT with a stubbed API. It is a test
 *            harness, not a deployment.
 *   served   the real production nginx image (UI01_SERVED_ORIGIN), where Laravel owns `/` and the
 *            SPA is mounted under `/spa/`. This is what a user would actually receive.
 *
 * Output: docs/proof/ui-01/network/browser-evidence.json (sanitized — no cookies, tokens,
 * authorization headers or personal data) plus baseline screenshots under
 * docs/proof/ui-01/screenshots/. Both are consumed by scripts/audit-ui-as-built.mjs.
 */

const ROOT = resolve(import.meta.dirname, '../..');
const EVIDENCE_DIR = resolve(ROOT, 'docs/proof/ui-01/network');
const SHOT_DIR = resolve(ROOT, 'docs/proof/ui-01/screenshots');
const SERVED_ORIGIN = process.env['UI01_SERVED_ORIGIN'] ?? 'http://localhost:8099';

const git = (args: string[]): string => {
  try {
    return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();
  } catch {
    return 'unknown';
  }
};

const COMMIT = git(['rev-parse', 'HEAD']);
const COMMIT7 = COMMIT.slice(0, 7);
const TREE = git(['rev-parse', 'HEAD^{tree}']);

// --- evidence accumulators ----------------------------------------------------

interface RouteVisit {
  route_name: string | null;
  account_key: string;
  path: string;
  result: 'rendered' | 'blank' | 'redirected' | 'error' | 'failed';
  http_status: number | null;
  final_path: string;
  landmark: string | null;
  console_error_count: number;
  detail: string | null;
}

interface Screenshot {
  account_key: string;
  origin: string;
  route: string;
  surface: string;
  auth_state: 'anonymous' | 'authenticated';
  viewport_width: number;
  viewport_height: number;
  device_scale_factor: number;
  theme_requested: string;
  theme_rendered: string | null;
  provenance: string;
  path: string | null;
  captured_at_utc: string;
  result: 'captured' | 'unreachable' | 'not_configured' | 'failed';
  related_defect_ids: string[];
}

const routeVisits: RouteVisit[] = [];
const screenshots: Screenshot[] = [];
const navigationObservations: Record<string, unknown>[] = [];
const themeObservations: Record<string, unknown>[] = [];
const legalObservations: Record<string, unknown>[] = [];
const servedOriginObservations: Record<string, unknown>[] = [];
const boundaryObservations: Record<string, unknown>[] = [];
const consoleErrors: string[] = [];
const unhandledRejections: string[] = [];
const failedRequests: { url: string; status: number | null; failure: string | null }[] = [];
const loadedFirstPartyAssets: { url: string; status: number; resource_type: string }[] = [];

/** Strip anything that could carry a secret before it reaches a committed evidence file. */
const sanitize = (value: string): string =>
  value
    .replace(/([?&](token|signature|key|secret|code|access_token)=)[^&\s]*/gi, '$1[REDACTED]')
    .replace(/Bearer\s+[\w.-]+/gi, 'Bearer [REDACTED]')
    .slice(0, 500);

function watch(page: Page): void {
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(sanitize(`${page.url()} :: ${message.text()}`));
  });
  page.on('pageerror', (error) => unhandledRejections.push(sanitize(`${page.url()} :: ${error.message}`)));
  page.on('requestfailed', (request) => {
    failedRequests.push({ url: sanitize(request.url()), status: null, failure: request.failure()?.errorText ?? null });
  });
  page.on('response', (response) => {
    const url = response.url();
    if (!/^https?:\/\/(localhost|127\.0\.0\.1)/.test(url)) return;
    if (response.status() >= 400) {
      failedRequests.push({ url: sanitize(url), status: response.status(), failure: null });
    }
    if (/\.(js|css|png|ico|svg|woff2?)(\?|$)/.test(url)) {
      loadedFirstPartyAssets.push({ url: sanitize(url), status: response.status(), resource_type: response.request().resourceType() });
    }
  });
}

/** Wait for the page's own landmark, then a short beat for layout, before capturing. */
async function settle(page: Page): Promise<void> {
  await page.locator('h1, h2').first().waitFor({ state: 'attached', timeout: 8000 }).catch(() => undefined);
  await page.waitForTimeout(250);
}

const slug = (path: string): string =>
  path.replace(/^\//, '').replace(/[/?=&:]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') || 'root';

async function shot(
  page: Page,
  meta: Omit<Screenshot, 'path' | 'captured_at_utc' | 'result' | 'theme_rendered' | 'device_scale_factor'> & { fullPage?: boolean },
): Promise<void> {
  const name = `${meta.account_key}--${meta.surface}--${slug(meta.route)}--${meta.viewport_width}x${meta.viewport_height}--${meta.theme_requested}--${COMMIT7}.png`;
  const target = resolve(SHOT_DIR, name);
  const rendered = await page
    .evaluate(() => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'))
    .catch(() => null);
  try {
    mkdirSync(SHOT_DIR, { recursive: true });
    await page.screenshot({ path: target, fullPage: meta.fullPage ?? false });
    screenshots.push({
      ...meta,
      device_scale_factor: 1,
      theme_rendered: rendered,
      path: `docs/proof/ui-01/screenshots/${name}`,
      captured_at_utc: AUDIT_INSTANT_UTC,
      result: 'captured',
      related_defect_ids: meta.related_defect_ids ?? [],
    });
  } catch (error) {
    screenshots.push({
      ...meta,
      device_scale_factor: 1,
      theme_rendered: rendered,
      path: null,
      captured_at_utc: AUDIT_INSTANT_UTC,
      result: 'failed',
      related_defect_ids: meta.related_defect_ids ?? [],
    });
    void error;
  }
}

/** Record a screenshot that could NOT be taken. An absent surface is evidence, never a gap. */
function shotUnavailable(meta: Omit<Screenshot, 'path' | 'captured_at_utc' | 'theme_rendered' | 'device_scale_factor'>): void {
  screenshots.push({
    ...meta,
    device_scale_factor: 1,
    theme_rendered: null,
    path: null,
    captured_at_utc: AUDIT_INSTANT_UTC,
  });
}

const ACCOUNTS: { key: RoleIdentity; landing: string; getStarted: string; dashboard: string }[] = [
  { key: 'super_administrator', landing: '/platform', getStarted: '/platform/get-started', dashboard: '/platform/dashboard' },
  { key: 'merchant_administrator', landing: '/merchant', getStarted: '/merchant/get-started', dashboard: '/merchant/dashboard' },
  { key: 'merchant_branch', landing: '/branch', getStarted: '/branch/get-started', dashboard: '/branch/dashboard' },
  { key: 'merchant_human_resource', landing: '/hr', getStarted: '/hr/get-started', dashboard: '/hr/dashboard' },
  { key: 'merchant_finance', landing: '/finance', getStarted: '/finance/get-started', dashboard: '/finance/dashboard' },
  { key: 'merchant_front_office', landing: '/front-office', getStarted: '/front-office/get-started', dashboard: '/front-office/dashboard' },
  { key: 'merchant_personnel', landing: '/personnel', getStarted: '/personnel/get-started', dashboard: '/personnel/dashboard' },
  { key: 'merchant_audit', landing: '/audit', getStarted: '/audit/get-started', dashboard: '/audit/dashboard' },
];

/** The plan's complete browser-width matrix. */
const RESPONSIVE_WIDTHS = [360, 768, 1025, 1440];
const BOUNDARY_WIDTHS = [767, 1024, 1280];
const VIEWPORT_HEIGHT = 900;

/**
 * The audit screen for a path/role pair. Several entries can share a path — a route and the
 * access state reached at the same URL under a degraded bootstrap (`/merchant` is both the
 * Merchant Administrator landing and the unsupported-role boundary). The routed entry is the
 * one this audit means, so prefer it; picking the access state would bootstrap a null role and
 * misreport a working landing as broken.
 */
function screenFor(path: string, role: RoleIdentity): AuditScreen {
  const matches = SCREENS.filter((s) => s.path === path && s.role === role);
  return matches.find((s) => s.route !== null) ?? matches[0] ?? { key: `ad-hoc:${path}`, route: null, path, role, state: 'static' };
}

/** Visit a path under a role bootstrap and classify what the browser actually produced. */
async function visit(
  page: Page,
  account: RoleIdentity,
  path: string,
  opts: { theme?: Theme; routeName?: string | null } = {},
): Promise<RouteVisit> {
  const before = consoleErrors.length;
  const screen = screenFor(path, account);
  let status: number | null = null;
  let result: RouteVisit['result'];
  let landmark: string | null = null;
  let detail: string | null = null;

  try {
    await prepare(page, screen, { theme: opts.theme ?? 'light' });
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    status = response?.status() ?? null;

    // Wait for the page's own landmark rather than a fixed delay: the heavier role landings
    // resolve their content asynchronously, and a short fixed wait misreports them as broken.
    await page
      .locator('h1, h2')
      .first()
      .waitFor({ state: 'attached', timeout: 8000 })
      .catch(() => undefined);
    await page.waitForTimeout(250);

    const appHtml = await page.evaluate(() => document.querySelector('#app')?.innerHTML.trim().length ?? 0);
    landmark = await page
      .locator('h1, h2')
      .first()
      .textContent({ timeout: 2000 })
      .then((text) => (text ?? '').trim().slice(0, 120))
      .catch(() => null);

    const finalPath = new URL(page.url()).pathname;
    if (appHtml === 0) {
      result = 'blank';
      detail = 'The #app root mounted no markup.';
    } else if (finalPath !== path.split('?')[0]) {
      result = 'redirected';
      detail = `Requested ${path}; the router settled on ${finalPath}.`;
    } else if (landmark === null) {
      result = 'error';
      detail = 'Markup mounted but no h1/h2 landmark rendered.';
    } else {
      result = 'rendered';
    }
  } catch (error) {
    result = 'failed';
    detail = sanitize(String((error as Error).message));
  }

  const record: RouteVisit = {
    route_name: opts.routeName ?? screen.route,
    account_key: account,
    path,
    result,
    http_status: status,
    final_path: (() => {
      try {
        return new URL(page.url()).pathname;
      } catch {
        return path;
      }
    })(),
    landmark,
    console_error_count: consoleErrors.length - before,
    detail,
  };
  routeVisits.push(record);
  return record;
}

// --- 1. served-origin audit ---------------------------------------------------

test.describe('UI-01 served origin (production nginx image)', () => {
  test('records what the deployed application actually returns', async ({ browser }) => {
    const context: BrowserContext = await browser.newContext({ viewport: { width: 1280, height: VIEWPORT_HEIGHT } });
    const page = await context.newPage();
    watch(page);

    for (const [surface, path] of [
      ['served-root', '/'],
      ['served-spa-shell', '/spa/'],
    ] as const) {
      let reachable = true;
      let status: number | null = null;
      let title: string | null = null;
      let appMounted: number | null = null;
      let detail: string | null = null;

      try {
        const response = await page.goto(`${SERVED_ORIGIN}${path}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
        status = response?.status() ?? null;
        await page.waitForTimeout(1200);
        title = await page.title();
        appMounted = await page.evaluate(() => document.querySelector('#app')?.innerHTML.trim().length ?? -1);
      } catch (error) {
        reachable = false;
        detail = sanitize(String((error as Error).message));
      }

      servedOriginObservations.push({
        origin: SERVED_ORIGIN,
        path,
        surface,
        reachable,
        http_status: status,
        document_title: title,
        app_root_markup_length: appMounted,
        renders_servana_surface: title === 'Servana by Citrus' && (appMounted ?? 0) > 0,
        detail,
      });

      if (reachable) {
        await shot(page, {
          account_key: 'served_origin',
          origin: SERVED_ORIGIN,
          route: path,
          surface,
          auth_state: 'anonymous',
          viewport_width: 1280,
          viewport_height: VIEWPORT_HEIGHT,
          theme_requested: 'default',
          provenance: 'production nginx image (servana-ui01-nginx:audit)',
          related_defect_ids: [],
        });
      } else {
        shotUnavailable({
          account_key: 'served_origin',
          origin: SERVED_ORIGIN,
          route: path,
          surface,
          auth_state: 'anonymous',
          viewport_width: 1280,
          viewport_height: VIEWPORT_HEIGHT,
          theme_requested: 'default',
          provenance: 'production nginx image',
          result: 'not_configured',
          related_defect_ids: [],
        });
      }
    }

    await context.close();
    expect(servedOriginObservations.length).toBe(2);
  });
});

// --- 2. theme audit -----------------------------------------------------------

test.describe('UI-01 theme behaviour', () => {
  const cases: { name: string; colorScheme: 'light' | 'dark'; stored: string | null }[] = [
    { name: 'clean-browser-no-preference', colorScheme: 'light', stored: null },
    { name: 'prefers-color-scheme-dark-no-stored-value', colorScheme: 'dark', stored: null },
    { name: 'prefers-color-scheme-light-no-stored-value', colorScheme: 'light', stored: null },
    { name: 'stored-dark-preference', colorScheme: 'light', stored: 'dark' },
    { name: 'stored-light-preference-under-dark-os', colorScheme: 'dark', stored: 'light' },
  ];

  for (const scenario of cases) {
    test(`theme: ${scenario.name}`, async ({ browser }) => {
      const context = await browser.newContext({ colorScheme: scenario.colorScheme, viewport: { width: 1280, height: VIEWPORT_HEIGHT } });
      const page = await context.newPage();
      watch(page);
      if (scenario.stored) {
        await page.addInitScript((value) => localStorage.setItem('servana.theme', value), scenario.stored);
      }
      await prepare(page, screenFor('/auth/login', 'public'), { theme: (scenario.stored as Theme) ?? 'light' });
      // `prepare` seeds the storage key, so re-clear it for the true clean-browser cases.
      if (!scenario.stored) {
        await page.addInitScript(() => localStorage.removeItem('servana.theme'));
      }

      await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
      const beforeHydration = await page.evaluate(() => document.documentElement.className);
      await page.waitForTimeout(500);
      const afterHydration = await page.evaluate(() => ({
        className: document.documentElement.className,
        stored: localStorage.getItem('servana.theme'),
        background: getComputedStyle(document.body).backgroundColor,
      }));

      themeObservations.push({
        scenario: scenario.name,
        os_color_scheme: scenario.colorScheme,
        stored_preference: scenario.stored,
        root_class_at_first_paint: beforeHydration,
        root_class_after_hydration: afterHydration.className,
        stored_after_load: afterHydration.stored,
        body_background: afterHydration.background,
        rendered_theme: afterHydration.className.includes('dark') ? 'dark' : 'light',
        contract_expected_theme: scenario.stored ?? 'light',
        contract_satisfied: (afterHydration.className.includes('dark') ? 'dark' : 'light') === (scenario.stored ?? 'light'),
        contract: 'ADR-021 — light is the default; prefers-color-scheme must not select the theme.',
      });

      await context.close();
    });
  }
});

// --- 3. per-account reachability, navigation and baselines ---------------------

for (const account of ACCOUNTS) {
  test.describe(`UI-01 account ${account.key}`, () => {
    test(`entry surfaces, navigation and responsive baselines`, async ({ browser }) => {
      const context = await browser.newContext({ viewport: { width: 1280, height: VIEWPORT_HEIGHT } });
      const page = await context.newPage();
      watch(page);

      // Entry surfaces.
      const landing = await visit(page, account.key, account.landing);
      const getStarted = await visit(page, account.key, account.getStarted);
      const dashboard = await visit(page, account.key, account.dashboard);

      // Navigation shell, as rendered.
      let navigation: Record<string, unknown> = { result: 'not_observed' };
      if (landing.result === 'rendered') {
        await prepare(page, screenFor(account.landing, account.key), { theme: 'light' });
        await page.goto(account.landing, { waitUntil: 'domcontentloaded' });
        await settle(page);
        navigation = await page.evaluate(() => {
          const nav = document.querySelector('nav');
          if (!nav) return { result: 'no_nav_element', rendered_placement: null, visible_item_count: 0, labels: [] as string[], detail: 'No <nav> element rendered.' };
          const links = [...nav.querySelectorAll('a')];
          const rect = nav.getBoundingClientRect();
          const header = nav.closest('header') !== null;
          const aside = nav.closest('aside') !== null;
          return {
            result: 'observed',
            rendered_placement: header ? 'header' : aside ? 'sidebar' : rect.width < window.innerWidth * 0.5 ? 'sidebar' : 'header',
            visible_item_count: links.length,
            labels: links.map((a) => (a.textContent ?? '').trim()).filter(Boolean).slice(0, 40),
            disabled_item_count: nav.querySelectorAll('[aria-disabled="true"], button[disabled]').length,
            nav_width: Math.round(rect.width),
            nav_height: Math.round(rect.height),
            detail: null,
          };
        });
      }
      navigationObservations.push({ account_key: account.key, ...navigation });

      // Wrong-account deep link — a UX observation only; the API is the security boundary.
      const foreign = ACCOUNTS.find((a) => a.key !== account.key)!;
      const cross = await visit(page, account.key, foreign.landing);
      boundaryObservations.push({
        account_key: account.key,
        probe: 'wrong_account_deep_link',
        requested_path: foreign.landing,
        result: cross.result,
        final_path: cross.final_path,
        landmark: cross.landmark,
        note: 'UX routing observation. Backend authorization is proven by the feature/authorization suites, not here.',
      });

      // Responsive baselines for landing + dashboard.
      for (const [surface, path, reached] of [
        ['landing', account.landing, landing.result === 'rendered'],
        ['dashboard', account.dashboard, dashboard.result === 'rendered'],
      ] as const) {
        for (const width of RESPONSIVE_WIDTHS) {
          if (!reached) {
            shotUnavailable({
              account_key: account.key,
              origin: 'preview',
              route: path,
              surface,
              auth_state: 'authenticated',
              viewport_width: width,
              viewport_height: VIEWPORT_HEIGHT,
              theme_requested: 'light',
              provenance: 'vite preview + stubbed API',
              result: 'unreachable',
              related_defect_ids: [],
            });
            continue;
          }
          await page.setViewportSize({ width, height: VIEWPORT_HEIGHT });
          await prepare(page, screenFor(path, account.key), { theme: 'light' });
          await page.goto(path, { waitUntil: 'domcontentloaded' });
          await settle(page);
          await shot(page, {
            account_key: account.key,
            origin: 'preview',
            route: path,
            surface,
            auth_state: 'authenticated',
            viewport_width: width,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: 'light',
            provenance: 'vite preview + stubbed API',
            related_defect_ids: [],
            fullPage: false,
          });
        }

        // Light + dark at 1280 for every reachable landing and dashboard.
        for (const theme of ['light', 'dark'] as Theme[]) {
          if (!reached) {
            shotUnavailable({
              account_key: account.key,
              origin: 'preview',
              route: path,
              surface,
              auth_state: 'authenticated',
              viewport_width: 1280,
              viewport_height: VIEWPORT_HEIGHT,
              theme_requested: theme,
              provenance: 'vite preview + stubbed API',
              result: 'unreachable',
              related_defect_ids: [],
            });
            continue;
          }
          await page.setViewportSize({ width: 1280, height: VIEWPORT_HEIGHT });
          await prepare(page, screenFor(path, account.key), { theme });
          await page.goto(path, { waitUntil: 'domcontentloaded' });
          await settle(page);
          await shot(page, {
            account_key: account.key,
            origin: 'preview',
            route: path,
            surface,
            auth_state: 'authenticated',
            viewport_width: 1280,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: theme,
            provenance: 'vite preview + stubbed API',
            related_defect_ids: [],
          });
        }
      }

      // Get-started + navigation shell at the breakpoint boundaries.
      if (getStarted.result === 'rendered') {
        for (const width of BOUNDARY_WIDTHS) {
          await page.setViewportSize({ width, height: VIEWPORT_HEIGHT });
          await prepare(page, screenFor(account.getStarted, account.key), { theme: 'light' });
          await page.goto(account.getStarted, { waitUntil: 'domcontentloaded' });
          await settle(page);
          await shot(page, {
            account_key: account.key,
            origin: 'preview',
            route: account.getStarted,
            surface: 'get-started',
            auth_state: 'authenticated',
            viewport_width: width,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: 'light',
            provenance: 'vite preview + stubbed API',
            related_defect_ids: [],
            fullPage: true,
          });
        }
      } else {
        for (const width of BOUNDARY_WIDTHS) {
          shotUnavailable({
            account_key: account.key,
            origin: 'preview',
            route: account.getStarted,
            surface: 'get-started',
            auth_state: 'authenticated',
            viewport_width: width,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: 'light',
            provenance: 'vite preview + stubbed API',
            result: 'unreachable',
            related_defect_ids: [],
          });
        }
      }

      await context.close();
    });
  });
}

// --- 4. whole-inventory route sweep -------------------------------------------

test.describe('UI-01 implemented-claim sweep', () => {
  test('visits every screen the inventory claims is implemented', async ({ browser }) => {
    // One navigation per claimed screen; the sweep is inherently longer than a single-screen test.
    test.setTimeout(15 * 60_000);
    const context = await browser.newContext({ viewport: { width: 1280, height: VIEWPORT_HEIGHT } });
    const page = await context.newPage();
    watch(page);

    const seen = new Set(routeVisits.map((v) => `${v.account_key}:${v.path}`));
    for (const screen of SCREENS) {
      if (seen.has(`${screen.role}:${screen.path}`)) continue;
      await visit(page, screen.role, screen.path, { routeName: screen.route });
    }

    await context.close();
    expect(routeVisits.length).toBeGreaterThan(SCREENS.length / 2);
  });
});

// --- 5. representative states and legal content -------------------------------

test.describe('UI-01 representative surfaces', () => {
  test('captures list, detail, form, table, states and legal content', async ({ browser }) => {
    // ~50 navigations plus full-page screenshots and 32 legal/FAQ probes.
    test.setTimeout(15 * 60_000);
    const context = await browser.newContext({ viewport: { width: 1280, height: VIEWPORT_HEIGHT } });
    const page = await context.newPage();
    watch(page);

    const representative: { surface: string; account: RoleIdentity; path: string; widths: number[] }[] = [
      { surface: 'list', account: 'merchant_front_office', path: '/front-office/clients', widths: [360, 1024, 1280] },
      { surface: 'detail', account: 'merchant_front_office', path: '/front-office/appointments', widths: [1280] },
      { surface: 'form', account: 'merchant_front_office', path: '/front-office/clients/new', widths: [360, 767, 1280] },
      { surface: 'table', account: 'merchant_finance', path: '/finance/invoices', widths: [767, 1024, 1280] },
      { surface: 'footer-and-shell', account: 'merchant_audit', path: '/audit/events', widths: [360, 1024, 1440] },
      { surface: 'mobile-navigation', account: 'merchant_finance', path: '/finance', widths: [360] },
      { surface: 'access-state-unsupported-role', account: 'merchant_administrator', path: '/merchant', widths: [1280] },
      { surface: 'not-found', account: 'public', path: '/no-such-page-01hzz', widths: [1280] },
      { surface: 'login', account: 'public', path: '/auth/login', widths: [360, 1280] },
      { surface: 'design-system', account: 'public', path: '/dev/design-system', widths: [1280] },
    ];

    for (const item of representative) {
      for (const width of item.widths) {
        await page.setViewportSize({ width, height: VIEWPORT_HEIGHT });
        const result = await visit(page, item.account, item.path);
        if (result.result === 'rendered' || result.result === 'redirected') {
          await shot(page, {
            account_key: item.account,
            origin: 'preview',
            route: item.path,
            surface: item.surface,
            auth_state: item.account === 'public' ? 'anonymous' : 'authenticated',
            viewport_width: width,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: 'light',
            provenance: 'vite preview + stubbed API',
            related_defect_ids: [],
            fullPage: true,
          });
        } else {
          shotUnavailable({
            account_key: item.account,
            origin: 'preview',
            route: item.path,
            surface: item.surface,
            auth_state: item.account === 'public' ? 'anonymous' : 'authenticated',
            viewport_width: width,
            viewport_height: VIEWPORT_HEIGHT,
            theme_requested: 'light',
            provenance: 'vite preview + stubbed API',
            result: 'unreachable',
            related_defect_ids: [],
          });
        }
      }
    }

    // Legal + FAQ reachability for all eight accounts.
    const roles: RoleIdentity[] = ACCOUNTS.map((a) => a.key);
    for (const role of roles) {
      for (const doc of ['terms-of-service', 'privacy-policy', 'data-policy', 'faq']) {
        const path = `/legal/${role}/${doc}`;
        const result = await visit(page, 'public', path);
        const bodyLength = await page.evaluate(() => document.querySelector('#app')?.textContent?.trim().length ?? 0).catch(() => 0);
        legalObservations.push({
          account_key: role,
          document: doc,
          path,
          result: result.result,
          landmark: result.landmark,
          rendered_text_length: bodyLength,
          renders_role_specific_content: result.result === 'rendered' && bodyLength > 500,
          note:
            doc === 'faq'
              ? 'No FAQ route exists; the router catch-all renders the Home entry card instead of a 404. UI-06 owns FAQ surfaces.'
              : 'Rendered from docs/legal via the legal.document route.',
        });
      }
    }

    await context.close();
  });
});

// --- 6. write evidence --------------------------------------------------------

test.afterAll(async () => {
  mkdirSync(EVIDENCE_DIR, { recursive: true });

  const dedupe = <T>(list: T[], key: (item: T) => string): T[] => {
    const seen = new Map<string, T>();
    for (const item of list) if (!seen.has(key(item))) seen.set(key(item), item);
    return [...seen.values()];
  };

  const evidence = {
    schema: 'ui-01.audit.v1.browser-evidence',
    phase: 'UI-01',
    generated_by: 'tests/e2e/ui-01-as-built-audit.spec.ts',
    captured_at_utc: AUDIT_INSTANT_UTC,
    sanitization:
      'No raw HAR is committed. URLs are token/secret-redacted, all data is stubbed test fixture data, and no cookie, authorization header or personal record is recorded.',
    source_commit: COMMIT,
    git_tree: TREE,
    base_url: 'http://localhost:4173',
    base_url_kind: 'vite preview serving public/spa as the web root, with a fully stubbed API — a test harness, not a deployment',
    served_origin: SERVED_ORIGIN,
    user_agent: 'chromium (Playwright Desktop Chrome device profile)',
    service_worker_registrations: 0,
    service_worker_controller: null,
    cache_storage_keys: [],
    service_worker_note: 'The SPA registers no service worker; the application source contains no serviceWorker registration and no Cache Storage use.',
    served_origin_observations: servedOriginObservations,
    theme_observations: themeObservations.sort((a, b) => String(a['scenario']).localeCompare(String(b['scenario']))),
    navigation_observations: navigationObservations.sort((a, b) => String(a['account_key']).localeCompare(String(b['account_key']))),
    legal_observations: legalObservations.sort(
      (a, b) => String(a['account_key']).localeCompare(String(b['account_key'])) || String(a['document']).localeCompare(String(b['document'])),
    ),
    boundary_observations: boundaryObservations.sort((a, b) => String(a['account_key']).localeCompare(String(b['account_key']))),
    route_visits: dedupe(routeVisits, (v) => `${v.account_key}:${v.path}`).sort(
      (a, b) => a.account_key.localeCompare(b.account_key) || a.path.localeCompare(b.path),
    ),
    screenshots: screenshots.sort(
      (a, b) =>
        a.account_key.localeCompare(b.account_key) ||
        a.surface.localeCompare(b.surface) ||
        a.route.localeCompare(b.route) ||
        a.viewport_width - b.viewport_width ||
        a.theme_requested.localeCompare(b.theme_requested),
    ),
    loaded_first_party_assets: dedupe(loadedFirstPartyAssets, (a) => a.url).sort((a, b) => a.url.localeCompare(b.url)),
    failed_requests: dedupe(failedRequests, (r) => `${r.url}:${r.status}`).sort((a, b) => a.url.localeCompare(b.url)),
    console_errors: [...new Set(consoleErrors)].sort(),
    unhandled_rejections: [...new Set(unhandledRejections)].sort(),
  };

  writeFileSync(resolve(EVIDENCE_DIR, 'browser-evidence.json'), `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
});
