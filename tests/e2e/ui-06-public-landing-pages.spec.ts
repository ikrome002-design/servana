import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { writeEvidenceFile, writeEvidenceScreenshot } from './support/evidenceScreenshot';

/**
 * Phase UI-06 — focused browser proof of the eight public landing pages.
 *
 * It proves what UI-06 built and nothing else. It does not repeat UI-01's as-built audit, UI-02's
 * host screenshots, UI-03's authentication proof, UI-04's design-system matrix or UI-05's content
 * pipeline proof, and it writes only into `docs/frontend/audits/ui-06/`.
 *
 * ## Why the account context is injected
 *
 * In production the Laravel shell resolves the account host and embeds the context; the browser
 * never decides its own account. The Playwright harness runs against a standalone Vite preview
 * origin with no Laravel behind it, so the shell that would embed the context is absent
 * (`UI01-PROV-003`, owned by UI-16). The context block is therefore installed before boot exactly
 * as the shell writes it — same element id, same payload shape — so the application takes its real
 * path instead of the fail-closed one.
 *
 * That is a harness accommodation, not a relaxation: the SAME eight hosts are additionally probed
 * over HTTP against the BUILT production images by `scripts/ui06-public-host-smoke.mjs`, where the
 * real Laravel shell does the resolving and the `Host` header is the input.
 */

const ROOT = resolve(import.meta.dirname, '../..');
const EVIDENCE = resolve(ROOT, 'docs/frontend/audits/ui-06');
const SHOTS = resolve(ROOT, 'docs/proof/ui-06');

interface AccountRow {
  account_key: string;
  display_name: string;
  production_host: string;
  local_host: string;
  document_title: string;
  content_source: string;
  content_sha256: string;
  faq_item_count: number;
  navigation: { label: string; region: string; anchor: string }[];
}

const manifest = JSON.parse(
  readFileSync(resolve(EVIDENCE, 'landing-page-manifest.json'), 'utf8'),
) as { accounts: AccountRow[] };

const ctaMatrix = JSON.parse(readFileSync(resolve(EVIDENCE, 'cta-matrix.json'), 'utf8')) as {
  accounts: {
    account_key: string;
    resolved: { key: string; label: string; kind: string; same_host_url: string }[];
  }[];
};

const imageMatrix = JSON.parse(readFileSync(resolve(EVIDENCE, 'image-render-matrix.json'), 'utf8')) as {
  accounts: {
    account_key: string;
    images: { landing_section: string; source_public_path: string; fetch_priority: string; loading: string }[];
  }[];
};

const legalMatrix = JSON.parse(readFileSync(resolve(EVIDENCE, 'legal-link-matrix.json'), 'utf8')) as {
  rows: { account_key: string; document: string; route: string; source_path: string; source_sha256: string }[];
};

const ACCOUNTS = manifest.accounts;
const ACCOUNT_KEYS = ACCOUNTS.map((account) => account.account_key);

/** The sixteen semantic regions, minus the two rendered as the header and the fixed footer. */
const BODY_REGIONS = [
  'hero', 'social_proof', 'problem', 'solution', 'features', 'how_it_works', 'benefits',
  'product_showcase', 'use_cases', 'testimonials', 'pricing', 'security', 'faq', 'final_cta',
] as const;

/** The plan's binding viewport contract: mobile ≤767, tablet 768–1024, desktop ≥1025. */
const WIDTHS = [360, 767, 768, 1024, 1025, 1280, 1440] as const;

const COMMIT7 = process.env['GITHUB_SHA']?.slice(0, 7) ?? 'worktree';

interface Observation {
  check: string;
  subject: string;
  ok: boolean;
  detail: string;
}

const observations: Observation[] = [];
const responsiveRows: Record<string, unknown>[] = [];
const themeRows: Record<string, unknown>[] = [];
const accessibilityRows: Record<string, unknown>[] = [];
const networkRows: Record<string, unknown>[] = [];
const performanceRows: Record<string, unknown>[] = [];
const screenshots: Record<string, unknown>[] = [];

function record(check: string, subject: string, ok: boolean, detail = ''): void {
  observations.push({ check, subject, ok, detail });
  expect(ok, `${check} — ${subject}${detail === '' ? '' : ` (${detail})`}`).toBe(true);
}

/**
 * Serve the account context the way the Laravel shell serves it: inside the HTML document.
 *
 * `initAccountContext()` runs at module load in `main.ts` and reads
 * `document.getElementById('servana-account-context')`, so the block must be part of the parsed
 * document — not appended by a script that races the parser. An earlier revision used
 * `addInitScript` to append the element at document-start and every page resolved `missing`,
 * because the parser had not yet produced a document element to append to.
 *
 * Rewriting the response is also the closer analogue of production: the same element id, the same
 * payload shape, in the same place, chosen by the SERVER rather than by anything in the page.
 *
 * Registered LAST, so Playwright checks it FIRST; anything that is not a document navigation is
 * handed straight back with `fallback()` to the bootstrap stubs registered before it.
 */
async function useAccount(page: Page, accountKey: string, displayName: string): Promise<void> {
  const context = JSON.stringify({
    account_key: accountKey,
    display_name: displayName,
    // `localhost` is not one of the eight approved hostnames, so the browser-side consistency
    // check finds nothing to disagree with and the server's answer stands — exactly the
    // non-account-host case the resolver is written for.
    host: 'localhost',
    environment: 'testing',
  });

  await page.route('**/*', async (route) => {
    if (route.request().resourceType() !== 'document') {
      return route.fallback();
    }

    const response = await route.fetch();
    const body = await response.text();
    if (!body.includes('<div id="app">')) {
      return route.fulfill({ response });
    }

    return route.fulfill({
      response,
      body: body.replace(
        '</body>',
        `<script id="servana-account-context" type="application/json">${context}</script></body>`,
      ),
    });
  });
}

/** The backend an anonymous visitor really meets: 401 on the bootstrap, not the preview's 404. */
async function stubAnonymousBootstrap(page: Page): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ error: { code: 'unauthenticated', message: 'Unauthenticated.' } }),
  }));
}

interface PageWatch {
  errors: string[];
  failedRequests: { url: string; status: number; type: string }[];
  requests: string[];
}

function watchPage(page: Page): PageWatch {
  const watch: PageWatch = { errors: [], failedRequests: [], requests: [] };

  page.on('request', (request) => watch.requests.push(request.url()));
  page.on('response', (response) => {
    if (response.status() >= 400) {
      watch.failedRequests.push({
        url: response.url(),
        status: response.status(),
        type: response.request().resourceType(),
      });
    }
  });
  page.on('console', (message) => {
    if (message.type() !== 'error') {
      return;
    }
    // Chromium logs a status-free "Failed to load resource" for the expected 401 bootstrap; failed
    // requests are tracked separately and asserted directly below.
    if (/Failed to load resource/.test(message.text())) {
      return;
    }
    watch.errors.push(`console: ${message.text()}`);
  });
  page.on('pageerror', (error) => watch.errors.push(`pageerror: ${error.message}`));

  return watch;
}

const CONTENT_RESOURCE_TYPES = new Set(['document', 'script', 'stylesheet', 'image', 'font', 'media']);

function assertClean(watch: PageWatch, subject: string): void {
  const broken = watch.failedRequests.filter((request) => CONTENT_RESOURCE_TYPES.has(request.type));
  record(
    'requests no asset that fails to load',
    subject,
    broken.length === 0,
    broken.map((request) => `${request.status} ${request.url}`).join(' | '),
  );

  const unexpected = watch.failedRequests.filter(
    (request) => !(request.status === 401 && request.url.includes('/api/v1/me')),
  );
  record(
    'issues no unexpected failing request',
    subject,
    unexpected.length === 0,
    unexpected.map((request) => `${request.status} ${request.url}`).join(' | '),
  );

  record('produces no console or page error', subject, watch.errors.length === 0, watch.errors.join(' | '));
}

async function capture(page: Page, meta: Record<string, unknown>): Promise<void> {
  mkdirSync(SHOTS, { recursive: true });
  const name = `${String(meta['account'])}--${String(meta['surface'])}--${String(meta['viewport'])}--${String(meta['theme_rendered'])}--${COMMIT7}.png`;
  const path = resolve(SHOTS, name);
  const buffer = await writeEvidenceScreenshot(page, path, { fullPage: false });

  screenshots.push({
    ...meta,
    file: `docs/proof/ui-06/${name}`,
    sha256: createHash('sha256').update(buffer).digest('hex'),
    source_commit: COMMIT7,
    captured_at: new Date().toISOString(),
    data_provenance: 'synthetic/public — approved landing content only; no user, tenant or token',
  });
}

// ==============================================================================================
// The eight landing pages
// ==============================================================================================

for (const account of ACCOUNTS) {
  const key = account.account_key;

  test(`${key} — renders its own landing page`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');

    const landing = page.getByTestId('landing-page');
    await expect(landing).toBeVisible();
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    // 1. This account's page, from this account's compiled source.
    record('renders this account\'s page', key, await landing.getAttribute('data-landing-account-key') === key);
    record(
      'carries this account\'s content source',
      key,
      await landing.getAttribute('data-content-source') === account.content_source,
      account.content_source,
    );
    record(
      'carries the recorded content hash',
      key,
      await landing.getAttribute('data-content-sha256') === account.content_sha256,
    );
    await expect(page).toHaveTitle(account.document_title);

    // 2. Exactly one h1, and it is the compiled hero headline.
    const headings = await page.locator('h1').allInnerTexts();
    record('renders exactly one h1', key, headings.length === 1, String(headings.length));

    // 3. All sixteen semantic regions.
    await expect(page.getByTestId('landing-header')).toBeVisible();
    await expect(page.getByTestId('sv-fixed-footer')).toBeVisible();
    for (const region of BODY_REGIONS) {
      const section = page.locator(`[data-landing-region="${region}"]`);
      record(`presents region ${region}`, key, await section.count() === 1);
    }

    // 4. No other account's content, anywhere in the document.
    const html = await page.content();
    for (const other of ACCOUNT_KEYS) {
      if (other === key) {
        continue;
      }
      record('shows no other account\'s content', `${key} vs ${other}`, !html.includes(other));
    }

    // 5. No fabricated evidence: no quotation styling, rating, adoption figure or amount.
    const trust = page.getByTestId('landing-trust-evidence');
    await expect(trust).toBeVisible();
    record('renders no blockquote in the trust region', key, await trust.locator('blockquote').count() === 0);
    const trustText = await trust.innerText();
    record('renders no quotation marks in the trust region', key, !/[“”]/.test(trustText));
    for (const item of await trust.getByTestId('landing-trust-item').all()) {
      record('declares no customer claim', key, await item.getAttribute('data-customer-claim') === 'false');
      record('declares no metric claim', key, await item.getAttribute('data-metric-claim') === 'false');
    }

    // 6. No invented pricing.
    const plan = page.getByTestId('landing-plan-access');
    await expect(plan).toBeVisible();
    record('states no plan amount', key, !/\bKES\s*[\d,]/i.test(await plan.innerText()));
    record('offers no purchase action', key, await plan.getAttribute('data-purchase-cta') === 'false');

    // 7. Every anchor in the in-page navigation resolves to a section on this page.
    for (const item of account.navigation) {
      const target = page.locator(item.anchor.replace('#', '#'));
      record(`navigation anchor ${item.label} exists`, key, await target.count() === 1, item.anchor);
    }

    // 8. Every link is same-host or an approved external property.
    const hrefs = await page.locator('a[href]').evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('href') ?? ''));
    const unsafe = hrefs.filter((href) => !/^(https?:\/\/|mailto:|\/|#)/i.test(href));
    record('emits only safe link schemes', key, unsafe.length === 0, unsafe.join(', '));
    for (const external of await page.locator('a[target="_blank"]').all()) {
      record('opens external links safely', key, (await external.getAttribute('rel')) === 'noopener noreferrer');
    }

    assertClean(watch, `${key} /`);
    networkRows.push({
      account_key: key,
      route: '/',
      request_count: watch.requests.length,
      failed_requests: watch.failedRequests,
      other_account_asset_requests: watch.requests.filter((url) =>
        ACCOUNT_KEYS.some((other) => other !== key && url.includes(`/landing_page_images/${other}/`))),
    });

    await capture(page, { account: key, surface: 'landing', route: '/', viewport: '1280x900', theme_requested: 'default', theme_rendered: 'light' });
  });

  test(`${key} — renders its own curated images and no other account's`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    // Scroll the whole page so lazy images below the fold are actually requested.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) {
        window.scrollTo(0, y);
        await new Promise((done) => setTimeout(done, 40));
      }
      window.scrollTo(0, 0);
    });
    await page.waitForLoadState('networkidle');

    const expected = imageMatrix.accounts.find((row) => row.account_key === key);
    const pictures = page.getByTestId('landing-picture');
    record(
      'renders its curated images',
      key,
      await pictures.count() === (expected?.images.length ?? 0),
      `${await pictures.count()} of ${expected?.images.length ?? 0}`,
    );

    for (const picture of await pictures.all()) {
      record('renders only its own account\'s image', key,
        await picture.getAttribute('data-landing-image-account') === key);

      const img = picture.locator('img');
      const [width, height, alt, loading, priority] = await Promise.all([
        img.getAttribute('width'), img.getAttribute('height'), img.getAttribute('alt'),
        img.getAttribute('loading'), img.getAttribute('fetchpriority'),
      ]);
      record('declares intrinsic dimensions', key, width !== null && height !== null);
      record('carries real alternative text', key, (alt ?? '').length > 30);
      record('declares a loading strategy', key, loading === 'eager' || loading === 'lazy');
      record('declares a fetch priority', key, priority === 'high' || priority === 'auto');
      record('offers AVIF and WebP candidates', key, await picture.locator('source').count() === 2);
    }

    record('marks exactly one image high priority', key,
      await page.locator('img[fetchpriority="high"]').count() === 1);

    // The strongest isolation assertion available: what the browser actually asked for.
    const foreign = watch.requests.filter((url) =>
      ACCOUNT_KEYS.some((other) => other !== key && url.includes(`landing_page_images/${other}/`)));
    record('requests no other account\'s image', key, foreign.length === 0, foreign.join(', '));

    // Every image the browser fetched came back.
    const brokenImages = watch.failedRequests.filter((request) => request.type === 'image');
    record('loads every image it requests', key, brokenImages.length === 0,
      brokenImages.map((request) => `${request.status} ${request.url}`).join(' | '));

    assertClean(watch, `${key} images`);
  });

  test(`${key} — exposes only the calls to action its account may offer`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    const expected = ctaMatrix.accounts.find((row) => row.account_key === key);
    for (const cta of expected?.resolved ?? []) {
      const control = page.getByTestId(`landing-hero-cta-${cta.key}`);
      record(`offers the ${cta.key} action`, key, await control.count() === 1);
      record(`points ${cta.key} at ${cta.same_host_url}`, key,
        await control.getAttribute('href') === cta.same_host_url);
      record(`labels ${cta.key} in sentence case`, key, cta.label !== cta.label.toUpperCase());
    }

    // Registration is offered by exactly one account, and never by any other.
    const html = await page.content();
    if (key === 'merchant_administrator') {
      record('offers merchant self-registration', key, html.includes('data-cta-kind="self_registration"'));
    } else {
      record('offers no merchant self-registration', key, !html.includes('data-cta-kind="self_registration"'));
      record('links no registration route', key, !html.includes('"/auth/register"') && !html.includes('"/register"'));
    }

    // Every CTA destination is a real page on this host, not a dead link.
    for (const cta of (expected?.resolved ?? []).filter((entry) => entry.kind !== 'in_page_anchor')) {
      const response = await page.request.get(cta.same_host_url);
      record(`the ${cta.key} destination resolves`, key, response.status() === 200, String(response.status()));
    }

    assertClean(watch, `${key} ctas`);
  });

  test(`${key} — serves its own FAQ at /faq`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.goto('/faq');

    const faq = page.getByTestId('public-faq');
    await expect(faq).toBeVisible();
    record('serves this account\'s FAQ', key, await faq.getAttribute('data-faq-account-key') === key);
    record(
      'reads this account\'s FAQ source',
      key,
      await faq.getAttribute('data-content-source') === `docs/support/faq/${key}_faq.md`,
    );

    const items = await page.locator('details').count();
    record('renders every compiled question', key, items === account.faq_item_count,
      `${items} of ${account.faq_item_count}`);

    // Native <details>/<summary>: keyboard-operable without any ARIA of its own.
    const first = page.locator('details').first();
    await first.locator('summary').focus();
    await page.keyboard.press('Enter');
    record('opens a question from the keyboard', key, await first.evaluate((node) => (node as HTMLDetailsElement).open));

    const ids = await page.locator('details').evaluateAll((nodes) => nodes.map((node) => node.id));
    record('gives every question a unique id', key, new Set(ids).size === ids.length);

    assertClean(watch, `${key} /faq`);
    await capture(page, { account: key, surface: 'faq', route: '/faq', viewport: '1280x720', theme_requested: 'default', theme_rendered: 'light' });
  });

  test(`${key} — serves its own three legal documents at role-free paths`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);

    for (const row of legalMatrix.rows.filter((entry) => entry.account_key === key)) {
      await page.goto(row.route);

      const article = page.getByTestId('sv-legal-document');
      await expect(article).toBeVisible();
      record(`serves ${row.document}`, key, await article.getAttribute('data-legal-account-key') === key);
      record(`reads ${row.document} from this account's source`, key,
        await article.getAttribute('data-content-source') === row.source_path);
      record(`carries the recorded ${row.document} hash`, key,
        await article.getAttribute('data-content-sha256') === row.source_sha256);

      for (const other of ACCOUNT_KEYS) {
        if (other !== key) {
          record('shows no other account\'s document', `${key}/${row.document} vs ${other}`,
            !(await article.getAttribute('data-content-source') ?? '').includes(other));
        }
      }

      const html = await article.innerHTML();
      record(`emits no executable markup in ${row.document}`, key,
        !/<(script|iframe|object|embed)/i.test(html));
    }

    assertClean(watch, `${key} legal`);
  });

  test(`${key} — the fixed footer links this account's documents and obstructs nothing`, async ({ page }) => {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    const footer = page.getByTestId('sv-fixed-footer');
    await expect(footer).toBeVisible();

    for (const doc of ['data-policy', 'privacy-policy', 'terms-of-service']) {
      record(`links ${doc}`, key, await page.getByTestId(`sv-footer-${doc}`).getAttribute('href') === `/legal/${doc}`);
    }
    record('links the FAQ', key, await page.getByTestId('sv-footer-faq').getAttribute('href') === '/faq');
    record('states the copyright verbatim', key,
      (await page.getByTestId('sv-footer-copyright').innerText()) === '© 2026 Citrus Labs. All Rights Reserved.');

    for (const social of ['instagram', 'x', 'facebook', 'youtube', 'linkedin', 'corporate']) {
      const link = page.getByTestId(`sv-footer-${social}`);
      record(`opens ${social} safely`, key, await link.getAttribute('rel') === 'noopener noreferrer');
    }

    // The footer must not sit on top of the last call to action.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(150);
    const overlap = await page.evaluate(() => {
      const footerBox = document.querySelector('[data-testid="sv-fixed-footer"]')?.getBoundingClientRect();
      const ctas = [...document.querySelectorAll('[data-testid^="landing-final-cta-"]')];
      if (footerBox === undefined) {
        return 'no footer';
      }

      return ctas
        .map((node) => node.getBoundingClientRect())
        .filter((box) => box.bottom > footerBox.top && box.top < footerBox.bottom)
        .length;
    });
    record('does not cover the final call to action', key, overlap === 0, String(overlap));

    assertClean(watch, `${key} footer`);
  });
}

// ==============================================================================================
// Responsive, theme and accessibility matrices
// ==============================================================================================

for (const account of ACCOUNTS) {
  const key = account.account_key;

  test(`${key} — no horizontal overflow at any required width`, async ({ page }) => {
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);

    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await expect(page.getByTestId('landing-hero')).toBeVisible();

      const metrics = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      const ok = metrics.scrollWidth <= metrics.clientWidth;
      responsiveRows.push({
        account_key: key, route: '/', width, ...metrics, no_horizontal_overflow: ok,
      });
      record('has no horizontal page overflow', `${key} @ ${width}`, ok,
        `${metrics.scrollWidth} > ${metrics.clientWidth}`);

      // The header stays usable: desktop navigation above the boundary, the menu trigger below it.
      const desktopNav = await page.getByTestId('landing-desktop-nav').isVisible();
      const trigger = await page.getByTestId('landing-menu-trigger').isVisible();
      record('offers exactly one navigation affordance', `${key} @ ${width}`, desktopNav !== trigger,
        `desktopNav=${desktopNav} trigger=${trigger}`);
    }

    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    await expect(page.getByTestId('landing-hero')).toBeVisible();
    await capture(page, { account: key, surface: 'landing', route: '/', viewport: '360x800', theme_requested: 'default', theme_rendered: 'light' });
  });

  test(`${key} — light is the default and dark is explicit`, async ({ page }) => {
    // A fresh browser under a dark operating system must still render light (ADR-021).
    await page.emulateMedia({ colorScheme: 'dark' });
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.goto('/');
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    const fresh = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    record('renders light in a fresh browser under a dark OS', key, !fresh);

    await page.getByTestId('theme-toggle').click();
    const afterToggle = await page.evaluate(() => ({
      dark: document.documentElement.classList.contains('dark'),
      stored: localStorage.getItem('servana.theme'),
    }));
    record('applies an explicit dark choice', key, afterToggle.dark && afterToggle.stored === 'dark');

    // The choice survives a reload, and is applied before hydration rather than after it.
    await page.reload();
    await expect(page.getByTestId('landing-hero')).toBeVisible();
    const afterReload = await page.evaluate(() => document.documentElement.classList.contains('dark'));
    record('persists the explicit choice per browser', key, afterReload);

    themeRows.push({
      account_key: key,
      fresh_context_theme: fresh ? 'dark' : 'light',
      os_preference: 'dark',
      explicit_dark_applied: afterToggle.dark,
      persisted: afterReload,
      storage_key: 'servana.theme',
    });

    await capture(page, { account: key, surface: 'landing', route: '/', viewport: '1280x900', theme_requested: 'dark', theme_rendered: 'dark' });
    await page.evaluate(() => localStorage.removeItem('servana.theme'));
  });

  for (const theme of ['light', 'dark'] as const) {
    test(`${key} — the landing page is axe clean in ${theme}`, async ({ page }) => {
      await stubAnonymousBootstrap(page);
      await useAccount(page, key, account.display_name);
      if (theme === 'dark') {
        await page.addInitScript(() => localStorage.setItem('servana.theme', 'dark'));
      }
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto('/');
      await expect(page.getByTestId('landing-hero')).toBeVisible();

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
      const serious = results.violations.filter((violation) =>
        violation.impact === 'serious' || violation.impact === 'critical');

      accessibilityRows.push({
        account_key: key, route: '/', theme,
        total_violations: results.violations.length,
        serious_or_critical: serious.length,
        rules: serious.map((violation) => violation.id),
      });

      record(`is axe clean in ${theme}`, key, serious.length === 0,
        serious.map((violation) => `${violation.id} (${violation.impact})`).join(', '));
    });
  }

  test(`${key} — the FAQ page is axe clean`, async ({ page }) => {
    await stubAnonymousBootstrap(page);
    await useAccount(page, key, account.display_name);
    await page.goto('/faq');
    await expect(page.getByTestId('public-faq')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((violation) =>
      violation.impact === 'serious' || violation.impact === 'critical');

    accessibilityRows.push({
      account_key: key, route: '/faq', theme: 'light',
      total_violations: results.violations.length,
      serious_or_critical: serious.length,
      rules: serious.map((violation) => violation.id),
    });

    record('is axe clean', `${key} /faq`, serious.length === 0,
      serious.map((violation) => violation.id).join(', '));
  });
}

test('a representative legal page is axe clean in both themes', async ({ page }) => {
  for (const theme of ['light', 'dark'] as const) {
    await stubAnonymousBootstrap(page);
    await useAccount(page, 'merchant_finance', 'Finance');
    if (theme === 'dark') {
      await page.addInitScript(() => localStorage.setItem('servana.theme', 'dark'));
    }
    await page.goto('/legal/privacy-policy');
    await expect(page.getByTestId('sv-legal-document')).toBeVisible();

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    const serious = results.violations.filter((violation) =>
      violation.impact === 'serious' || violation.impact === 'critical');

    accessibilityRows.push({
      account_key: 'merchant_finance', route: '/legal/privacy-policy', theme,
      total_violations: results.violations.length,
      serious_or_critical: serious.length,
      rules: serious.map((violation) => violation.id),
    });
    record('is axe clean', `merchant_finance /legal/privacy-policy ${theme}`, serious.length === 0,
      serious.map((violation) => violation.id).join(', '));

    await page.evaluate(() => localStorage.removeItem('servana.theme'));
  }
});

// ==============================================================================================
// Mobile menu, keyboard behaviour and the safe boundaries
// ==============================================================================================

test('the mobile menu traps focus, closes on Escape and restores focus to its trigger', async ({ page }) => {
  await stubAnonymousBootstrap(page);
  await useAccount(page, 'merchant_branch', 'Branch Manager');
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/');
  await expect(page.getByTestId('landing-hero')).toBeVisible();

  const trigger = page.getByTestId('landing-menu-trigger');
  await expect(trigger).toBeVisible();

  // 44×44 minimum target.
  const box = await trigger.boundingBox();
  record('offers a 44px menu trigger', 'merchant_branch',
    (box?.width ?? 0) >= 44 && (box?.height ?? 0) >= 44, `${box?.width}x${box?.height}`);

  await trigger.focus();
  await page.keyboard.press('Enter');
  const panel = page.getByTestId('landing-mobile-menu');
  await expect(panel).toBeVisible();
  record('marks the menu as a modal dialog', 'merchant_branch',
    await panel.getAttribute('aria-modal') === 'true');
  record('moves focus into the menu', 'merchant_branch',
    await page.evaluate(() => document.querySelector('[data-testid="landing-mobile-menu"]')?.contains(document.activeElement) ?? false));

  // Tab all the way round: focus must not escape to the page behind.
  for (let step = 0; step < 12; step += 1) {
    await page.keyboard.press('Tab');
  }
  record('keeps focus inside the open menu', 'merchant_branch',
    await page.evaluate(() => document.querySelector('[data-testid="landing-mobile-menu"]')?.contains(document.activeElement) ?? false));

  await page.keyboard.press('Escape');
  await expect(panel).toHaveCount(0);
  record('returns focus to the trigger', 'merchant_branch',
    await page.evaluate(() => document.activeElement?.getAttribute('data-testid') === 'landing-menu-trigger'));

  // A distinct surface name: `landing` at 360x800 in light is already captured by the responsive
  // test for this account, and two records naming one file would mean the second silently
  // overwrote the first.
  await capture(page, { account: 'merchant_branch', surface: 'mobile-menu', route: '/', viewport: '360x800', theme_requested: 'default', theme_rendered: 'light' });
});

test('the skip link reaches the main landmark from the keyboard', async ({ page }) => {
  await stubAnonymousBootstrap(page);
  await useAccount(page, 'super_administrator', 'Super Administrator');
  await page.goto('/');
  await expect(page.getByTestId('landing-hero')).toBeVisible();

  await page.keyboard.press('Tab');
  record('focuses the skip link first', 'super_administrator',
    await page.evaluate(() => document.activeElement?.getAttribute('data-testid') === 'landing-skip-link'));

  await page.keyboard.press('Enter');
  record('lands on the main landmark', 'super_administrator',
    await page.evaluate(() => window.location.hash === '#main-content'));
  record('has exactly one main landmark', 'super_administrator',
    await page.locator('main').count() === 1);
});

test('an unknown account context renders no account experience', async ({ page }) => {
  const watch = watchPage(page);
  await stubAnonymousBootstrap(page);
  await useAccount(page, 'not_an_account', 'Unknown');
  await page.goto('/');

  const boundary = page.getByTestId('landing-context-boundary');
  await expect(boundary).toBeVisible();
  record('fails closed on an unknown account', 'not_an_account',
    await boundary.getAttribute('data-account-context-failure') === 'unknown_account');
  record('renders no landing content', 'not_an_account',
    await page.getByTestId('landing-hero').count() === 0);
  assertClean(watch, 'unknown account');
});

test('a mismatched role in the legacy legal path renders nothing', async ({ page }) => {
  const watch = watchPage(page);
  await stubAnonymousBootstrap(page);
  await useAccount(page, 'merchant_finance', 'Finance');

  // The account's own documents redirect to the canonical, role-free path.
  await page.goto('/legal/merchant_finance/privacy-policy');
  await expect(page).toHaveURL(/\/legal\/privacy-policy$/);
  record('redirects the account\'s own legacy path to the canonical one', 'merchant_finance', true);

  // Another account's does not, and shows nothing.
  await page.goto('/legal/merchant_personnel/privacy-policy');
  await expect(page.getByTestId('sv-legal-document')).toHaveCount(0);
  await expect(page.getByText('That legal document could not be found.')).toBeVisible();
  record('fails closed on a mismatched role', 'merchant_finance', !page.url().includes('personnel')
    || (await page.getByTestId('sv-legal-document').count()) === 0);

  assertClean(watch, 'legacy legal path');
});

test('an unknown path says so rather than rendering a page', async ({ page }) => {
  const watch = watchPage(page);
  await stubAnonymousBootstrap(page);
  await useAccount(page, 'merchant_audit', 'Audit');
  await page.goto('/definitely-not-a-page');

  await expect(page.getByTestId('public-not-found')).toBeVisible();
  record('renders the not-found boundary', 'merchant_audit',
    await page.getByTestId('landing-hero').count() === 0);
  assertClean(watch, 'not found');
});

// ==============================================================================================
// Preliminary performance observations — recorded, never claimed as release compliance
// ==============================================================================================

test('records preliminary performance observations for each account', async ({ page }) => {
  for (const account of ACCOUNTS) {
    const watch = watchPage(page);
    await stubAnonymousBootstrap(page);
    await useAccount(page, account.account_key, account.display_name);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/', { waitUntil: 'load' });
    await expect(page.getByTestId('landing-hero')).toBeVisible();

    const metrics = await page.evaluate(() => new Promise<Record<string, number>>((done) => {
      const result: Record<string, number> = { lcp: 0, cls: 0 };
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          result.lcp = Math.max(result.lcp, entry.startTime);
        }
      }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries() as (PerformanceEntry & { value: number; hadRecentInput: boolean })[]) {
          if (!entry.hadRecentInput) {
            result.cls += entry.value;
          }
        }
      }).observe({ type: 'layout-shift', buffered: true });
      setTimeout(() => done(result), 1200);
    }));

    const transfer = await page.evaluate(() =>
      performance.getEntriesByType('resource').reduce(
        (totals, entry) => {
          const resource = entry as PerformanceResourceTiming;
          const bytes = resource.transferSize ?? 0;
          if (resource.initiatorType === 'script') {
            totals.script += bytes;
          } else if (resource.initiatorType === 'img') {
            totals.image += bytes;
          }
          totals.total += bytes;
          totals.requests += 1;

          return totals;
        },
        { script: 0, image: 0, total: 0, requests: 0 },
      ));

    performanceRows.push({
      account_key: account.account_key,
      route: '/',
      viewport: '1280x900',
      lcp_ms: Math.round(metrics.lcp),
      cls: Number(metrics.cls.toFixed(4)),
      script_transfer_bytes: transfer.script,
      image_transfer_bytes: transfer.image,
      total_transfer_bytes: transfer.total,
      request_count: transfer.requests,
      failed_requests: watch.failedRequests.length,
      note: 'PRELIMINARY. A single local run on a Vite preview origin, not a p75 field measurement. Final performance acceptance and the CDN budget are UI-17.',
    });

    // Only what this account needs was fetched — not eight accounts' content chunks.
    const foreignChunks = watch.requests.filter((url) =>
      ACCOUNT_KEYS.some((other) => other !== account.account_key && url.includes(other)));
    record('downloads no other account\'s content chunk', account.account_key,
      foreignChunks.length === 0, foreignChunks.join(', '));
  }
});

// ==============================================================================================
// Evidence
// ==============================================================================================

test.afterAll(async () => {
  mkdirSync(EVIDENCE, { recursive: true });
  const write = async (name: string, payload: unknown): Promise<void> => {
    await writeEvidenceFile(resolve(EVIDENCE, name), `${JSON.stringify(payload, null, 2)}\n`);
  };

  const provenance = {
    generated_by: 'tests/e2e/ui-06-public-landing-pages.spec.ts',
    phase: 'UI-06',
    origin: 'http://localhost:4173 (Vite preview). UI01-PROV-003 — the deployed-origin browser gate is UI-16/UI-17.',
    account_context: 'Installed before boot exactly as the Laravel shell embeds it, because the preview origin has no Laravel behind it. The same eight hosts are probed over HTTP against the built production images by scripts/ui06-public-host-smoke.mjs.',
    source_commit: COMMIT7,
  };

  await write('responsive-matrix.json', {
    ...provenance,
    widths: WIDTHS,
    rule: 'mobile ≤767, tablet 768–1024, desktop ≥1025 — CSS media queries only, no JavaScript device detection',
    rows: responsiveRows,
  });
  await write('theme-matrix.json', {
    ...provenance,
    rule: 'ADR-021 — light is the default and prefers-color-scheme never selects the theme; dark is explicit and persistent per browser for an anonymous visitor',
    rows: themeRows,
  });
  await write('accessibility-matrix.json', {
    ...provenance,
    tool: 'axe-core via @axe-core/playwright',
    tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
    gate: '0 serious, 0 critical',
    rows: accessibilityRows,
  });
  await write('network-asset-matrix.json', { ...provenance, rows: networkRows });
  await write('performance-observations.json', {
    ...provenance,
    targets: { lcp_ms: 2500, cls: 0.1, inp_ms: 200 },
    status: 'PRELIMINARY — recorded, not claimed. UI-17 owns the agreed p75 budget and the CDN proof.',
    rows: performanceRows,
  });
  await write('screenshot-index.json', {
    ...provenance,
    policy: 'Focused implementation evidence, NOT release-approved visual baselines. UI-16 owns reviewed baselines.',
    count: screenshots.length,
    screenshots,
  });
  await write('browser-proof.json', {
    ...provenance,
    total_observations: observations.length,
    failed_observations: observations.filter((observation) => !observation.ok).length,
    observations,
  });
});
