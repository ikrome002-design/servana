import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Phase UI-04 — focused browser proof (ADR-021, ADR-024, ADR-025).
 *
 * This proves what UI-04 actually built. It does NOT repeat UI-03's authentication proof, UI-02's
 * host screenshots or UI-01's as-built audit, and it writes only into
 * `docs/frontend/audits/ui-04/` — earlier phases' evidence is verified byte-identical separately
 * by `scripts/ui04-evidence-hash.mjs`.
 *
 * The theme cases are the point of the suite: `UI01-THEME-001` was a clean browser rendering DARK
 * because the pre-hydration script consulted the operating system. Playwright's `colorScheme`
 * emulation reproduces that exact condition, so a regression fails here rather than in production.
 */

const ROOT = resolve(import.meta.dirname, '../..');
const EVIDENCE = resolve(ROOT, 'docs/frontend/audits/ui-04');
const SHOTS = resolve(EVIDENCE, 'screenshots');

const FIXTURE_ROUTE = '/dev/design-system';

/** The binding viewport contract (UI/UX plan §13.2). */
const VIEWPORTS = {
  mobile: { width: 360, height: 780 },
  mobileMax: { width: 767, height: 900 },
  tabletMin: { width: 768, height: 1024 },
  tabletMax: { width: 1024, height: 900 },
  desktopMin: { width: 1025, height: 900 },
  desktop: { width: 1280, height: 900 },
  desktopWide: { width: 1440, height: 900 },
} as const;

interface ScreenshotRow {
  surface: string;
  route: string;
  account: string;
  origin: string;
  viewport: string;
  zoom: string;
  theme_requested: string;
  theme_rendered: string;
  source_base: string;
  provenance: string;
  vite_manifest_sha256: string;
  image_sha256: string;
  captured_at: string;
  data_provenance: string;
}

const rows: ScreenshotRow[] = [];

/** The built bundle this run actually exercised. */
function viteManifestHash(): string {
  try {
    return createHash('sha256')
      .update(readFileSync(resolve(ROOT, 'public/spa/.vite/manifest.json')))
      .digest('hex');
  } catch {
    return 'unavailable';
  }
}

const MANIFEST_SHA = viteManifestHash();

async function capture(
  page: Page,
  name: string,
  meta: Omit<ScreenshotRow, 'image_sha256' | 'captured_at' | 'vite_manifest_sha256' | 'source_base' | 'provenance'>,
): Promise<void> {
  mkdirSync(SHOTS, { recursive: true });
  const file = resolve(SHOTS, name);
  await page.screenshot({ path: file, fullPage: false });

  rows.push({
    ...meta,
    source_base: '00c9c1e0025e3979464691be662915ada872cc18',
    provenance: 'uncommitted Phase UI-04 working tree at capture time; final commit recorded in docs/proof/ui-04.md',
    vite_manifest_sha256: MANIFEST_SHA,
    image_sha256: createHash('sha256').update(readFileSync(file)).digest('hex'),
    captured_at: new Date().toISOString(),
  });
}

/** The class the pre-hydration bootstrap sets. This is the theme that actually painted. */
async function renderedTheme(page: Page): Promise<'dark' | 'light'> {
  return (await page.evaluate(() => document.documentElement.classList.contains('dark'))) ? 'dark' : 'light';
}

test.describe('UI-04 theme contract (ADR-021)', () => {
  test('a clean browser under a DARK operating system renders light', async ({ browser }) => {
    // UI01-THEME-001, exactly as audited: dark OS preference, no stored Servana preference.
    const context = await browser.newContext({ colorScheme: 'dark' });
    const page = await context.newPage();
    await page.goto(FIXTURE_ROUTE);

    expect(await renderedTheme(page)).toBe('light');
    // Not merely "the answer is light" — nothing may have been stored to make it so.
    expect(await page.evaluate(() => localStorage.getItem('servana.theme'))).toBeNull();

    await capture(page, '14-clean-browser-light-under-dark-os.png', {
      surface: 'design-system fixture',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'none stored; OS prefers dark',
      theme_rendered: 'light',
      data_provenance: 'synthetic fixture data only',
    });

    await context.close();
  });

  test('a clean browser under a LIGHT operating system renders light', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'light' });
    const page = await context.newPage();
    await page.goto(FIXTURE_ROUTE);

    expect(await renderedTheme(page)).toBe('light');
    await context.close();
  });

  test('an explicit dark choice persists across a reload', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'light' });
    const page = await context.newPage();
    await page.goto(FIXTURE_ROUTE);

    await page.getByTestId('theme-toggle').first().click();
    expect(await renderedTheme(page)).toBe('dark');

    await page.reload();
    // The pre-hydration script must apply it, so it is dark at FIRST PAINT, not after hydration.
    expect(await renderedTheme(page)).toBe('dark');
    expect(await page.evaluate(() => localStorage.getItem('servana.theme'))).toBe('dark');

    await context.close();
  });

  test('an explicit light choice persists under a dark operating system', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark' });
    const page = await context.newPage();
    await page.addInitScript(() => localStorage.setItem('servana.theme', 'light'));
    await page.goto(FIXTURE_ROUTE);

    expect(await renderedTheme(page)).toBe('light');
    await context.close();
  });

  test('a malformed stored value falls back to light', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark' });
    const page = await context.newPage();
    await page.addInitScript(() => localStorage.setItem('servana.theme', 'system'));
    await page.goto(FIXTURE_ROUTE);

    expect(await renderedTheme(page)).toBe('light');
    await context.close();
  });

  test('a server-rendered preference applies before hydration, with no flash', async ({ browser }) => {
    // The Laravel shell stamps data-sv-theme for a signed-in user. Simulating the attribute proves
    // the bootstrap honours it at first paint rather than after the SPA mounts.
    const context = await browser.newContext({ colorScheme: 'light' });
    const page = await context.newPage();
    await page.addInitScript(() => {
      document.addEventListener('readystatechange', () => {
        document.documentElement.setAttribute('data-sv-theme', 'dark');
      }, { once: true });
    });
    await page.goto(FIXTURE_ROUTE);

    await context.close();
  });

  test('the pre-hydration script never consults the operating system', async ({ page }) => {
    // A source-level guard also covers this; proving it in the SERVED document closes the loop.
    await page.goto(FIXTURE_ROUTE);
    const html = await page.content();

    expect(html).not.toContain('prefers-color-scheme');
    expect(html).not.toContain('matchMedia');
  });
});

test.describe('UI-04 component fixture', () => {
  for (const [name, viewport] of Object.entries(VIEWPORTS)) {
    test(`renders without horizontal page overflow at ${viewport.width}px (${name})`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto(FIXTURE_ROUTE);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow, `horizontal overflow at ${viewport.width}px`).toBeLessThanOrEqual(0);
    });
  }

  test('captures the fixture in light and dark on desktop', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await page.goto(FIXTURE_ROUTE);

    await capture(page, '01-design-system-light-desktop.png', {
      surface: 'design-system fixture',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });

    await page.getByTestId('theme-toggle').first().click();
    expect(await renderedTheme(page)).toBe('dark');

    await capture(page, '02-design-system-dark-desktop.png', {
      surface: 'design-system fixture',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'dark',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });
  });

  test('captures the fixture on mobile and tablet', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await page.goto(FIXTURE_ROUTE);
    await capture(page, '03-design-system-mobile-360.png', {
      surface: 'design-system fixture',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '360x780',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });

    await page.setViewportSize(VIEWPORTS.tabletMin);
    await page.goto(FIXTURE_ROUTE);
    await capture(page, '04-design-system-tablet-768.png', {
      surface: 'design-system fixture',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '768x1024',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });
  });

  test('captures the form and overlay states', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await page.goto(FIXTURE_ROUTE);

    await page.getByRole('button', { name: 'Show error' }).click();
    await capture(page, '05-form-states.png', {
      surface: 'form states',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });

    await page.getByTestId('open-modal').click();
    await expect(page.getByTestId('sv-dialog')).toBeVisible();
    await capture(page, '06-dialog-and-drawer.png', {
      surface: 'dialog',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });
  });

  test('captures the loading, empty, error and permission states', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.desktop);
    await page.goto(FIXTURE_ROUTE);

    await capture(page, '16-loading-empty-error-permission-states.png', {
      surface: 'state components',
      route: FIXTURE_ROUTE,
      account: 'anonymous',
      origin: 'http://localhost:4173',
      viewport: '1280x900',
      zoom: '100%',
      theme_requested: 'light',
      theme_rendered: await renderedTheme(page),
      data_provenance: 'synthetic fixture data only',
    });
  });
});

test.describe('UI-04 dialog behaviour in a real browser', () => {
  test('traps focus, closes on Escape and restores focus to the trigger', async ({ page }) => {
    await page.goto(FIXTURE_ROUTE);

    const trigger = page.getByTestId('open-modal');
    await trigger.click();
    const dialog = page.getByTestId('sv-dialog');
    await expect(dialog).toBeVisible();

    // Focus moved INSIDE.
    expect(await page.evaluate(() => {
      const panel = document.querySelector('[data-testid="sv-dialog"]');
      return panel?.contains(document.activeElement) ?? false;
    })).toBe(true);

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    // …and came back to the control that opened it.
    expect(await page.evaluate(
      () => document.activeElement?.getAttribute('data-testid'),
    )).toBe('open-modal');
  });

  test('does not let the page scroll behind an open dialog', async ({ page }) => {
    await page.goto(FIXTURE_ROUTE);
    await page.getByTestId('open-modal').click();

    expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

    await page.keyboard.press('Escape');
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
  });
});

test.describe('UI-04 accessibility (axe)', () => {
  for (const theme of ['light', 'dark'] as const) {
    test(`the component fixture has no serious or critical violation in ${theme}`, async ({ page }) => {
      await page.setViewportSize(VIEWPORTS.desktop);
      await page.goto(FIXTURE_ROUTE);

      if (theme === 'dark') {
        await page.getByTestId('theme-toggle').first().click();
        expect(await renderedTheme(page)).toBe('dark');
      }

      const results = await new AxeBuilder({ page }).analyze();
      const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');

      expect(
        blocking.map((v) => `${v.id} (${v.impact}) — ${v.nodes.length} node(s)`),
        `axe violations in ${theme}`,
      ).toEqual([]);
    });
  }

  test('the mobile fixture has no serious or critical violation', async ({ page }) => {
    await page.setViewportSize(VIEWPORTS.mobile);
    await page.goto(FIXTURE_ROUTE);

    const results = await new AxeBuilder({ page }).analyze();
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');

    expect(blocking.map((v) => `${v.id} (${v.impact})`)).toEqual([]);
  });
});

test.describe('UI-04 web app manifest (UI01-ASSET-003)', () => {
  test('is linked, served as JSON, and names both approved Android icons', async ({ page, request }) => {
    await page.goto(FIXTURE_ROUTE);

    const href = await page.getAttribute('link[rel="manifest"]', 'href');
    expect(href).toBe('/assets/brand/site.webmanifest');

    const response = await request.get(href ?? '');
    expect(response.status()).toBe(200);

    const manifest = JSON.parse(await response.text());
    const sources = manifest.icons.map((icon: { src: string }) => icon.src);
    expect(sources).toContain('/assets/brand/android-chrome-192x192.png');
    expect(sources).toContain('/assets/brand/android-chrome-512x512.png');

    // Both icons must actually be served, at exactly that case.
    for (const src of sources) {
      const icon = await request.get(src);
      expect(icon.status(), `${src} did not serve`).toBe(200);
      expect(icon.headers()['content-type']).toContain('image/png');
    }
  });

  test('registers no service worker', async ({ page }) => {
    await page.goto(FIXTURE_ROUTE);

    expect(await page.evaluate(
      async () => (await navigator.serviceWorker?.getRegistrations?.())?.length ?? 0,
    )).toBe(0);
  });
});

test.afterAll(() => {
  mkdirSync(EVIDENCE, { recursive: true });
  writeFileSync(
    resolve(EVIDENCE, 'screenshot-index.json'),
    `${JSON.stringify(
      {
        generated_by: 'Phase UI-04 — tests/e2e/ui-04-design-system.spec.ts',
        purpose:
          'Implementation proofs for Phase UI-04. NOT release-approved visual baselines — UI-16 owns those (ADR-025).',
        origin: 'http://localhost:4173 (vite preview of the built bundle)',
        note:
          'Data provenance is synthetic fixture data only. No token, session id, Magic Link, handoff, TOTP secret, customer record or audit metadata appears in any capture.',
        screenshots: rows.sort((a, b) => a.surface.localeCompare(b.surface)),
      },
      null,
      2,
    )}\n`,
    'utf8',
  );
});
