import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Phase UI-05 — focused browser proof (UI/UX plan §8.8, §17; ADR-025).
 *
 * Proves what UI-05 actually built, and nothing else. It does NOT repeat UI-01's as-built audit,
 * UI-02's eight host screenshots, UI-03's authentication proof or UI-04's design-system matrix, and
 * it writes only into `docs/frontend/audits/ui-05/`.
 *
 * The subject is the twenty-four EXISTING legal pages — three documents for each of the eight
 * accounts — because they are the only surface that renders generated content today. The final FAQ
 * route and the eight public landing pages are UI-06's, and no page is created here to make a
 * browser assertion possible.
 *
 * Every assertion is about provenance or safety: the right account's document, sourced from the
 * right file, rendered without an unsafe link or raw HTML, with no console or page error. Layout
 * and visual approval belong to UI-16.
 */

const ROOT = resolve(import.meta.dirname, '../..');
const EVIDENCE = resolve(ROOT, 'docs/frontend/audits/ui-05');

interface ManifestEntry {
  account_key: string;
  category: string;
  source_path: string;
  source_sha256: string;
}

const contentManifest = JSON.parse(
  readFileSync(resolve(EVIDENCE, 'content-source-manifest.json'), 'utf8'),
) as { entries: ManifestEntry[] };

const imageManifest = JSON.parse(
  readFileSync(resolve(ROOT, 'public/assets/landing_page_images/manifest.json'), 'utf8'),
) as {
  images: {
    account_key: string;
    source_public_path: string;
    source_sha256: string;
    alternative_text: string;
    derivatives: { public_path: string; mime_type: string; sha256: string }[];
  }[];
};

const ACCOUNTS = [
  'super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource',
  'merchant_finance', 'merchant_front_office', 'merchant_personnel', 'merchant_audit',
] as const;

/** Route document type → content category, as the loader maps them. */
const LEGAL_DOCS = [
  { doc: 'terms-of-service', category: 'terms_of_service', title: 'Terms of Service' },
  { doc: 'privacy-policy', category: 'privacy_policy', title: 'Privacy Policy' },
  { doc: 'data-policy', category: 'data_policy', title: 'Data Policy' },
] as const;

interface Observation {
  check: string;
  subject: string;
  ok: boolean;
  detail: string;
}

const observations: Observation[] = [];

function record(check: string, subject: string, ok: boolean, detail = ''): void {
  observations.push({ check, subject, ok, detail });
  expect(ok, `${check} — ${subject}${detail === '' ? '' : ` (${detail})`}`).toBe(true);
}

/**
 * Give the page the backend an anonymous visitor would really meet.
 *
 * The legal routes are public, so the honest bootstrap result is **401**, not the 404 the preview
 * origin returns for every `/api/v1/*` path because it has no backend at all (`UI01-PROV-003`,
 * owned by UI-16/UI-17). Stubbing the two bootstrap calls makes the app take its real anonymous
 * path, which is what lets the error assertions below stay strict instead of being loosened to
 * tolerate a missing backend.
 */
async function stubAnonymousBootstrap(page: Page): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({ error: { code: 'unauthenticated', message: 'Unauthenticated.' } }),
  }));
}

/**
 * Fail on anything the page reports, rather than discovering it in a screenshot later.
 *
 * `pageerror` — an uncaught JavaScript exception — is always a failure. Console errors are too,
 * with one stated exception: Chromium logs a generic "Failed to load resource" line for the
 * expected 401 on the anonymous `/me` bootstrap, and that line carries no status code, so it cannot
 * be matched more precisely than by pairing it with a recorded failed request. Failed requests are
 * therefore tracked separately and asserted directly: any failure for a document, script, stylesheet,
 * image or font is a broken-asset defect and fails.
 */
interface PageWatch {
  errors: string[];
  failedRequests: { url: string; status: number; type: string }[];
}

function watchForErrors(page: Page): PageWatch {
  const watch: PageWatch = { errors: [], failedRequests: [] };

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
    if (/Failed to load resource/.test(message.text())) {
      return; // Paired with, and asserted through, `failedRequests` below.
    }
    watch.errors.push(`console: ${message.text()}`);
  });
  page.on('pageerror', (error) => watch.errors.push(`pageerror: ${error.message}`));

  return watch;
}

/** Requests whose failure would mean a broken page or a broken asset. */
const CONTENT_RESOURCE_TYPES = new Set(['document', 'script', 'stylesheet', 'image', 'font', 'media']);

function assertClean(watch: PageWatch, subject: string): void {
  const broken = watch.failedRequests.filter((request) => CONTENT_RESOURCE_TYPES.has(request.type));
  record(
    'requests no asset that fails to load',
    subject,
    broken.length === 0,
    broken.map((request) => `${request.status} ${request.url}`).join(' | '),
  );

  // The only tolerated failure is the anonymous /me bootstrap, which is a 401 by design.
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

const sourceText = (relative: string): string => readFileSync(resolve(ROOT, relative), 'utf8');

test.describe('UI-05 — generated content renders on the existing legal routes', () => {
  for (const account of ACCOUNTS) {
    for (const legal of LEGAL_DOCS) {
      test(`${account} / ${legal.doc} renders its own document`, async ({ page }) => {
        const watch = watchForErrors(page);
        await stubAnonymousBootstrap(page);
        const subject = `${account}/${legal.doc}`;

        await page.goto(`/legal/${account}/${legal.doc}`);

        const article = page.getByTestId('sv-legal-document');
        await expect(article).toBeVisible();

        // 1. The shared UI-04 component renders it, not a second markdown path.
        record('renders through SvLegalDocument', subject, await article.count() === 1);

        // 2. The document heading is the real page heading (not screen-reader-only).
        await expect(page.locator('h1').first()).toHaveText(legal.title);

        // 3. Provenance: the component carries the exact source file and hash it was compiled from.
        const entry = contentManifest.entries.find(
          (candidate) => candidate.account_key === account && candidate.category === legal.category,
        );
        expect(entry, `no manifest entry for ${subject}`).toBeDefined();

        record(
          'carries the source path of this account\'s own document',
          subject,
          await article.getAttribute('data-content-source') === entry?.source_path,
          entry?.source_path ?? '',
        );
        record(
          'carries the recorded source hash',
          subject,
          await article.getAttribute('data-content-sha256') === entry?.source_sha256,
          entry?.source_sha256.slice(0, 16) ?? '',
        );

        // 4. Content parity: the rendered text really is this document. Comparing a distinctive
        //    line beats comparing the whole body, which markdown rendering legitimately reflows.
        //    Not every document opens with a `#` heading — Super Administrator's Terms of Service
        //    opens with a bold paragraph — so the first heading is used when one exists and the
        //    first substantial line otherwise. An empty needle would make this assertion vacuous,
        //    which is why the length is asserted too.
        const source = sourceText(entry?.source_path ?? '');
        const inline = (text: string): string => text.replace(/[*`_]/g, '').replace(/\s+/g, ' ').trim();
        const heading = /^#{1,6}\s+(.+)$/m.exec(source)?.[1];
        const firstLine = source.split('\n').map(inline).find((line) => line.length > 12) ?? '';
        const needle = inline(heading ?? firstLine);

        record('has a non-empty content needle to compare', subject, needle.length > 12, needle);
        const rendered = inline(await article.innerText());
        record('renders the source document\'s own opening line', subject, rendered.includes(needle), needle);

        // 5. No cross-role leak: no OTHER account key may appear in the rendered provenance.
        for (const other of ACCOUNTS) {
          if (other !== account) {
            record(
              'shows no other account\'s document',
              `${subject} vs ${other}`,
              !(await article.getAttribute('data-content-source') ?? '').includes(other),
            );
          }
        }

        // 6. Safety: no unsafe link scheme, no executable markup, no inline handler.
        const html = await article.innerHTML();
        record('emits no script, iframe, object or embed', subject, !/<(script|iframe|object|embed)/i.test(html));
        record('emits no inline event handler', subject, !/\son[a-z]+\s*=/i.test(html));

        const hrefs = await article.locator('a[href]').evaluateAll(
          (nodes) => nodes.map((node) => node.getAttribute('href') ?? ''),
        );
        const unsafe = hrefs.filter((href) => !/^(https?:\/\/|mailto:|\/|#)/i.test(href));
        record('emits only safe link schemes', subject, unsafe.length === 0, unsafe.join(', '));

        // 7. Nothing the browser considers an error, and no asset that fails to load.
        assertClean(watch, subject);
      });
    }
  }

  test('an unknown legal document type renders the not-found boundary, never another document', async ({ page }) => {
    const watch = watchForErrors(page);
    await stubAnonymousBootstrap(page);
    await page.goto('/legal/merchant_finance/not-a-real-document');

    await expect(page.getByTestId('sv-legal-document')).toHaveCount(0);
    await expect(page.getByText('That legal document could not be found.')).toBeVisible();
    assertClean(watch, 'merchant_finance/not-a-real-document');
  });

  test('an unknown account renders the not-found boundary, never another account\'s document', async ({ page }) => {
    const watch = watchForErrors(page);
    await stubAnonymousBootstrap(page);
    await page.goto('/legal/not_an_account/privacy-policy');

    await expect(page.getByTestId('sv-legal-document')).toHaveCount(0);
    await expect(page.getByText('That legal document could not be found.')).toBeVisible();
    assertClean(watch, 'not_an_account/privacy-policy');
  });
});

test.describe('UI-05 — accessibility of the rendered content surfaces', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`a representative legal page is axe clean in ${theme}`, async ({ page }) => {
      await stubAnonymousBootstrap(page);
      if (theme === 'dark') {
        await page.addInitScript(() => window.localStorage.setItem('servana.theme', 'dark'));
      }
      await page.goto('/legal/merchant_finance/privacy-policy');
      await expect(page.getByTestId('sv-legal-document')).toBeVisible();

      // The rendered theme is the `dark` class on <html>, which is what the pre-hydration script
      // and the store both drive — `data-sv-theme` is the SERVER's input to that script (the
      // Laravel shell stamps it for a signed-in user) and is legitimately absent on this origin.
      // UI-04's own theme suite reads the same class.
      const rendered = await page.evaluate(
        () => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'),
      );
      record('renders the requested theme', `legal/${theme}`, rendered === theme, rendered);

      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''));
      record(
        'axe reports no serious or critical violation',
        `legal/${theme}`,
        serious.length === 0,
        serious.map((violation) => `${violation.id}(${violation.nodes.length})`).join(', '),
      );
    });
  }

  test('the FAQ disclosure renders every account\'s data set accessibly', async ({ page }) => {
    // The design-system fixture is the only surface that renders SvFaq today. UI-06 owns the public
    // FAQ route; building one here purely to satisfy a browser assertion is out of scope.
    const watch = watchForErrors(page);
    await stubAnonymousBootstrap(page);
    await page.goto('/dev/design-system');

    for (const account of ACCOUNTS) {
      await page.getByTestId(`faq-account-${account}`).click();

      const summary = page.getByTestId('faq-fixture-summary');
      await expect(summary).toContainText('questions compiled for');

      const compiled = Number(/^(\d+) questions/.exec((await summary.innerText()).trim())?.[1] ?? '0');
      record('the fixture compiles this account\'s own questions', account, compiled > 100, String(compiled));

      const questions = await page.getByTestId('sv-faq').first().locator('summary').allInnerTexts();
      record('renders the compiled disclosure items', account, questions.length === 8, String(questions.length));

      const results = await new AxeBuilder({ page }).include('[data-testid="sv-faq"]').analyze();
      const serious = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''));
      record('axe reports no serious or critical violation', `sv-faq/${account}`, serious.length === 0,
        serious.map((violation) => violation.id).join(', '));
    }

    assertClean(watch, 'sv-faq (all eight data sets)');
  });

  test('the FAQ disclosure is keyboard operable', async ({ page }) => {
    await stubAnonymousBootstrap(page);
    await page.goto('/dev/design-system');

    const faq = page.getByTestId('sv-faq').first();
    await expect(faq).toBeVisible();

    const summary = faq.locator('summary').first();
    await summary.focus();
    record('the disclosure control takes keyboard focus', 'sv-faq', await summary.evaluate((node) => node === document.activeElement));

    await page.keyboard.press('Enter');
    record('Enter expands the disclosure', 'sv-faq', await faq.locator('details').first().evaluate((node) => (node as HTMLDetailsElement).open));

    await page.keyboard.press('Enter');
    record('Enter collapses it again', 'sv-faq', !(await faq.locator('details').first().evaluate((node) => (node as HTMLDetailsElement).open)));
  });

  test('the FAQ disclosure stays operable at the mobile width', async ({ page }) => {
    await stubAnonymousBootstrap(page);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.goto('/dev/design-system');

    const faq = page.getByTestId('sv-faq').first();
    await expect(faq).toBeVisible();

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    record('the page does not scroll horizontally at 360px', 'sv-faq/mobile', !overflows);

    const results = await new AxeBuilder({ page }).include('[data-testid="sv-faq"]').analyze();
    const serious = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''));
    record('axe reports no serious or critical violation at 360px', 'sv-faq/mobile', serious.length === 0,
      serious.map((violation) => violation.id).join(', '));
  });
});

test.describe('UI-05 — curated assets over HTTP', () => {
  test('every selected image, derivative and the approved logo serve with the right bytes', async ({ request }) => {
    const logo = await request.get('/assets/brand/Logo.png');
    record('the approved logo serves', '/assets/brand/Logo.png',
      logo.status() === 200 && (logo.headers()['content-type'] ?? '').includes('image/png'),
      `${logo.status()} ${logo.headers()['content-type']}`);

    // The Vite preview origin answers every unknown path with the SPA shell at 200, so a status
    // check would prove nothing here. The property to assert on THIS origin is that no image is
    // served — an HTML document cannot be a logo. The 404 itself is asserted where it is real:
    // `scripts/ui05-production-smoke.mjs`, against the built nginx image.
    const svg = await request.get('/assets/brand/Logo.svg');
    record(
      'the deleted vector logo serves no image',
      '/assets/brand/Logo.svg',
      !/^image\//.test(svg.headers()['content-type'] ?? ''),
      `${svg.status()} ${svg.headers()['content-type']}`,
    );

    const manifest = await request.get('/assets/landing_page_images/manifest.json');
    record('the landing-image manifest serves', 'manifest.json', manifest.status() === 200, String(manifest.status()));

    for (const image of imageManifest.images) {
      const original = await request.get(image.source_public_path);
      const hash = createHash('sha256').update(await original.body()).digest('hex');
      record('the selected original serves its exact bytes', image.source_public_path,
        original.status() === 200 && hash === image.source_sha256, String(original.status()));

      for (const derivative of image.derivatives) {
        const response = await request.get(derivative.public_path);
        const body = await response.body();
        record('the derivative serves its exact bytes and MIME', derivative.public_path,
          response.status() === 200
          && createHash('sha256').update(body).digest('hex') === derivative.sha256
          && (response.headers()['content-type'] ?? '').includes(derivative.mime_type),
          `${response.status()} ${response.headers()['content-type']}`);
      }
    }
  });

  test('no quarantined brand working file is reachable', async ({ request }) => {
    const quarantine = JSON.parse(
      readFileSync(resolve(EVIDENCE, 'asset-quarantine.json'), 'utf8'),
    ) as { files: { original_public_url: string }[] };

    expect(quarantine.files).toHaveLength(11);
    for (const file of quarantine.files) {
      const response = await request.get(file.original_public_url);
      const body = await response.body();

      // As above: the preview origin falls back to the SPA shell, so the assertion is that no
      // IMAGE comes back — neither by content type nor by PNG signature. The production smoke
      // asserts the 404 against nginx.
      record(
        'the quarantined working file serves no image',
        file.original_public_url,
        !/^image\//.test(response.headers()['content-type'] ?? '')
          && body.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a',
        `${response.status()} ${response.headers()['content-type']}`,
      );
    }
  });
});

test.afterAll(() => {
  mkdirSync(EVIDENCE, { recursive: true });
  const failed = observations.filter((observation) => !observation.ok);

  writeFileSync(
    resolve(EVIDENCE, 'browser-proof.json'),
    `${JSON.stringify({
      generated_by: 'tests/e2e/ui-05-content-asset-pipeline.spec.ts',
      scope: 'The twenty-four existing legal routes, the SvFaq disclosure fixture, and the curated assets over HTTP. UI-06 owns the eight public landing pages and the final FAQ route; UI-16 owns visual baselines.',
      origin: 'http://localhost:4173 (vite preview) — the deployed-origin proof is UI-16/UI-17 work, as UI-01 recorded.',
      total_observations: observations.length,
      failures: failed.length,
      observations,
    }, null, 2)}\n`,
    'utf8',
  );
});
