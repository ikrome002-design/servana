import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import {
  assertAxeClean,
  assertNoElementOverflow,
  assertNoHorizontalOverflow,
  assertShellUsable,
  IDS,
  readyLocator,
  type Viewport,
} from './support/releaseAudit';
import { contractRows, liveContractPages, prepareVisual } from './support/ui16Visual';

/**
 * Phase UI-16 — responsive, accessibility, theme and gate acceptance over the WHOLE 160-page
 * contract (UI/UX plan §25 UI-16, §13, §19, §12; ADR-018/021/024).
 *
 * ONE data-driven matrix, derived at run time from the canonical UI-07 projection, so a newly
 * implemented page cannot escape it and a gated page can never be enrolled as live:
 *
 *  - every IMPLEMENTED page (132) is loaded once and RESIZED through the seven plan boundaries
 *    (360/767/768/1024/1025/1280/1440) plus a 200%-zoom-equivalent CSS viewport, with no page-level
 *    or element overflow, its own content visible, the right primary navigation for the width, and
 *    the fixed footer's block size reserved below the content;
 *  - every implemented page is axe-clean (no serious/critical WCAG 2.0/2.1 A/AA) in light at
 *    desktop and in dark at mobile, and its dark theme is a real token surface, not an inversion;
 *  - every GATED row (28) is inert in its account navigation and has no runtime route;
 *  - first paint is light even when the OS prefers dark, and an explicit theme persists.
 *
 * The SPA preview has no backend: `/me` and `/api/v1/**` are stubbed so the REAL frontend is
 * driven (see support/ui16Visual.ts). Authorization is proven by the backend suite; nothing here is
 * a security claim.
 */

const WIDTHS = [360, 767, 768, 1024, 1025, 1280, 1440] as const;

function viewportFor(width: number, height = 900): Viewport {
  return { name: width < 768 ? 'mobile' : width < 1025 ? 'tablet' : 'desktop', width, height };
}

const LIVE = liveContractPages();
const ROWS = contractRows();

async function openLive(page: Page, entry: (typeof LIVE)[number], theme: 'light' | 'dark' = 'light'): Promise<void> {
  await prepareVisual(page, entry.screen, theme);
  await page.goto(entry.screen.path);
  await expect(page.locator(readyLocator(entry.screen)).first(), `${entry.contractKey}: own content`).toBeVisible({ timeout: 15_000 });
}

/**
 * UI16-TOKEN-002 / UI16-RESP-001: a utility that names an unconfigured colour (`bg-savannah-orange`,
 * `text-danger`, `to-brand-citrus`) or screen (`sm:`) is not an error anywhere in the toolchain — it
 * simply compiles to nothing. This reads the BUILT stylesheet the preview serves and fails when a
 * static template utility has no rule.
 */
function deadUtilities(templates: Array<{ file: string; source: string }>, css: string): string[] {
  const defined = new Set<string>();
  for (const m of css.matchAll(/\.((?:\\.|[A-Za-z0-9_-])+)/g)) defined.add(m[1]!.replace(/\\(.)/g, '$1'));
  const utility = /^(?:[a-z0-9-]+:)*!?-?(bg|text|border|ring|from|via|to|fill|stroke|outline|decoration|divide|shadow|placeholder|accent|caret)-/;
  const out: string[] = [];
  for (const { file, source } of templates) {
    const tpl = source.slice(Math.max(0, source.indexOf('<template>'))).replace(/<!--[\s\S]*?-->/g, '');
    const chunks = [
      ...Array.from(tpl.matchAll(/\sclass="([^"]*)"/g), (m) => m[1]!),
      ...Array.from(tpl.matchAll(/:class="([^"]*)"/g)).flatMap((m) => Array.from(m[1]!.matchAll(/'([^']*)'/g), (s) => s[1]!)),
    ];
    for (const cls of chunks.flatMap((c) => c.split(/\s+/)).filter(Boolean)) {
      if (utility.test(cls) && !cls.includes('${') && !defined.has(cls)) out.push(`${file}: ${cls}`);
    }
  }
  return [...new Set(out)];
}

test.describe('UI-16 compiled utilities', () => {
  const root = resolve(import.meta.dirname, '../..');
  const css = readdirSync(join(root, 'public/spa/spa-assets')).filter((f) => f.endsWith('.css'))
    .map((f) => readFileSync(join(root, 'public/spa/spa-assets', f), 'utf8')).join('\n');
  const templates: Array<{ file: string; source: string }> = [];
  (function walk(dir: string): void {
    for (const entry of readdirSync(dir)) {
      const path = join(dir, entry);
      if (statSync(path).isDirectory()) walk(path);
      else if (entry.endsWith('.vue')) templates.push({ file: relative(root, path), source: readFileSync(path, 'utf8') });
    }
  })(join(root, 'resources/spa/src'));

  test('every static template utility compiles to a rule in the built stylesheet', () => {
    expect(templates.length).toBeGreaterThan(250);
    expect(css.length).toBeGreaterThan(10_000);
    expect(deadUtilities(templates, css)).toEqual([]);
  });

  test('negative control: an unconfigured colour and screen are reported', () => {
    expect(deadUtilities([{ file: 'x.vue', source: '<template><p class="text-sv-text bg-savannah-orange sm:text-sv-text" /></template>' }], css))
      .toEqual(['x.vue: bg-savannah-orange', 'x.vue: sm:text-sv-text']);
  });
});

test.describe('UI-16 contract coverage', () => {
  test('derives exactly 160 contract rows: 132 implemented, 28 gated, none planned', () => {
    expect(ROWS).toHaveLength(160);
    const byStatus = ROWS.reduce<Record<string, number>>((acc, row) => {
      acc[row.implementation_status] = (acc[row.implementation_status] ?? 0) + 1;
      return acc;
    }, {});
    expect(byStatus).toEqual({ implemented: 132, disabled_by_gate: 28 });
    expect(LIVE).toHaveLength(132);
    // 82 of the live pages belong to the five UI-08…UI-12 accounts UI-16 remediated.
    expect(LIVE.filter((p) => ['UI-08', 'UI-09', 'UI-10', 'UI-11', 'UI-12'].includes(p.ownerPhase))).toHaveLength(82);
  });
});

test.describe('UI-16 responsive sweep — seven widths and 200% zoom', () => {
  for (const entry of LIVE) {
    test(`responsive: ${entry.contractKey}`, async ({ page }) => {
      test.setTimeout(90_000);
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.setViewportSize({ width: 1440, height: 900 });
      await openLive(page, entry);

      // One navigation, then a LIVE resize through the matrix: a resize must re-lay-out, not only
      // a fresh load (UI/UX plan §13).
      for (const width of WIDTHS) {
        const viewport = viewportFor(width);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const label = `${entry.contractKey} @ ${width}px`;
        await expect(page.locator(readyLocator(entry.screen)).first(), `${label}: content`).toBeVisible();
        await assertNoHorizontalOverflow(page, label);
        await assertNoElementOverflow(page, label);
        await assertShellUsable(page, entry.screen, viewport);
        if (entry.screen.key !== 'first-time-setup') await assertFooterReserved(page, label);
      }

      // 200% zoom of a 1280×800 window is a 640×400 CSS viewport. The short height also exercises
      // the footer's extreme-zoom fallback (ADR-024 rule 6).
      await page.setViewportSize({ width: 640, height: 400 });
      await assertNoHorizontalOverflow(page, `${entry.contractKey} @ 200%`);
      if (entry.screen.key !== 'first-time-setup') await assertFooterReserved(page, `${entry.contractKey} @ 200%`);

      expect(errors, `${entry.contractKey}: page errors`).toEqual([]);
    });
  }
});

/**
 * The fixed footer never covers content: the shell root reserves at least the footer's own block
 * size, and the last content element ends above the footer once the page is scrolled to the end.
 */
async function assertFooterReserved(page: Page, label: string): Promise<void> {
  const result = await page.evaluate(async () => {
    const footer = document.querySelector<HTMLElement>('.sv-fixed-footer');
    const reserve = document.querySelector<HTMLElement>('.sv-footer-reserve');
    if (!footer || !reserve) return { ok: false, why: 'footer or reserve missing' };
    // instant: the page may use smooth scrolling, and a measurement taken mid-animation is wrong.
    window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' });
    await new Promise((r) => requestAnimationFrame(() => r(null)));
    const footerTop = footer.getBoundingClientRect().top;
    const reserved = parseFloat(getComputedStyle(reserve).paddingBottom);
    const main = document.querySelector<HTMLElement>('#main-content') ?? reserve;
    const contentBottom = Math.max(...Array.from(main.querySelectorAll<HTMLElement>('*'))
      // Content of a CLOSED <details> keeps a layout box in Chromium but is not rendered.
      .filter((el) => el.getBoundingClientRect().height > 0 && getComputedStyle(el).position !== 'fixed'
        && !(el.closest('details:not([open])') && !el.closest('summary')))
      .map((el) => el.getBoundingClientRect().bottom), 0);
    window.scrollTo({ top: 0, behavior: 'instant' });
    return {
      ok: reserved + 0.5 >= footer.getBoundingClientRect().height && contentBottom <= footerTop + 1,
      why: `reserved ${reserved}px, footer ${Math.round(footer.getBoundingClientRect().height)}px, content bottom ${Math.round(contentBottom)} vs footer top ${Math.round(footerTop)}`,
    };
  });
  expect(result.ok, `${label}: fixed footer obstructs content — ${result.why}`).toBe(true);
}

test.describe('UI-16 accessibility — every live page, light desktop and dark mobile', () => {
  for (const entry of LIVE) {
    test(`axe: ${entry.contractKey}`, async ({ page }) => {
      test.setTimeout(90_000);
      await page.setViewportSize({ width: 1440, height: 900 });
      await openLive(page, entry, 'light');
      await assertAxeClean(page, `${entry.contractKey} (light, 1440)`);
      await expect(page.locator('h1'), `${entry.contractKey}: one h1`).toHaveCount(1);

      await page.context().clearCookies();
      await page.setViewportSize({ width: 360, height: 780 });
      await openLive(page, entry, 'dark');
      await expect(page.locator('html'), `${entry.contractKey}: dark class`).toHaveClass(/\bdark\b/);
      // A real dark surface from the token, never a filter inversion.
      const surface = await page.evaluate(() => ({
        body: getComputedStyle(document.body).backgroundColor,
        filter: getComputedStyle(document.documentElement).filter,
      }));
      // COR-UI08-UI12-VIS-001: the five corrective accounts use the refined dark canvas (night-950
      // #0F151C); every other surface keeps the standard dark page (#111827). Both are real tokens.
      const refinedAccount = ['super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource', 'merchant_finance'].includes(entry.account);
      expect(surface.body, `${entry.contractKey}: dark page surface`).toBe(refinedAccount ? 'rgb(15, 21, 28)' : 'rgb(17, 24, 39)');
      expect(surface.filter, `${entry.contractKey}: no inversion filter`).toBe('none');
      await assertAxeClean(page, `${entry.contractKey} (dark, 360)`);
    });
  }
});

test.describe('UI-16 theme — light first paint and explicit persistence', () => {
  // One representative page per account: the dashboard (the Merchant Administrator's is behind setup).
  const dashboards = LIVE.filter((p) => p.contractKey.endsWith('.dashboard'));

  test('covers one dashboard per account', () => {
    expect(dashboards.map((p) => p.account).sort()).toEqual([
      'merchant_administrator', 'merchant_audit', 'merchant_branch', 'merchant_finance',
      'merchant_front_office', 'merchant_human_resource', 'merchant_personnel', 'super_administrator',
    ]);
  });

  for (const entry of dashboards) {
    test(`first paint is light under an OS dark preference: ${entry.account}`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: 'dark' });
      await prepareVisual(page, entry.screen, 'light');
      // Remove the harness's explicit choice so the APPLICATION default decides (ADR-021).
      await page.addInitScript(() => {
        try { localStorage.removeItem('servana.theme'); } catch { /* storage may be unavailable */ }
      });
      await page.goto(entry.screen.path);
      await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
      // COR-UI08-UI12-VIS-001: the five corrective accounts paint the refined LIGHT canvas (linen
      // #F5F3EF); every other surface keeps the standard light page (#F9FAFB). Both are light — the
      // assertion still fails on any dark first paint.
      const refined = ['super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource', 'merchant_finance'].includes(entry.account);
      expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(refined ? 'rgb(245, 243, 239)' : 'rgb(249, 250, 251)');
    });
  }

  test('an explicit dark choice persists across reload and navigation', async ({ page }) => {
    const entry = dashboards.find((p) => p.account === 'merchant_finance')!;
    await prepareVisual(page, entry.screen, 'dark');
    await page.goto(entry.screen.path);
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.reload();
    await expect(page.locator(readyLocator(entry.screen)).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    await page.getByTestId('sidebar-primary-nav').getByRole('link', { name: 'Payment Records' }).click();
    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
  });

  test('honours prefers-reduced-motion on the recomposed shell and dashboards', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const entry = dashboards.find((p) => p.account === 'super_administrator')!;
    await openLive(page, entry);
    const animated = await page.evaluate(() => Array.from(document.querySelectorAll<HTMLElement>('*'))
      .filter((el) => {
        const style = getComputedStyle(el);
        return parseFloat(style.transitionDuration) + parseFloat(style.animationDuration) > 0.05;
      })
      .slice(0, 5)
      .map((el) => `${el.tagName.toLowerCase()}.${el.className?.toString().slice(0, 50)}`));
    expect(animated, `long motion under prefers-reduced-motion:\n${animated.join('\n')}`).toEqual([]);
  });

  test('active navigation stays AA through both theme transitions', async ({ page }) => {
    const entry = dashboards.find((p) => p.account === 'merchant_administrator')!;
    await openLive(page, entry);
    await page.evaluate(() => document.fonts.ready);
    const ratios = await page.evaluate(async () => {
      const row = document.querySelector<HTMLElement>('.sv-shell-sidebar .sv-nav-entry--active')!;
      const toggle = document.querySelector<HTMLButtonElement>('[data-testid="theme-toggle"]')!;
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1;
      const context = canvas.getContext('2d')!;
      const luminance = (color: string): number => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        const values = Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3).map((value) => {
          const channel = value / 255;
          return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        });
        return values[0]! * 0.2126 + values[1]! * 0.7152 + values[2]! * 0.0722;
      };
      const sampleTransitions = async (): Promise<number[]> => {
        const samples: number[] = [];
        for (let change = 0; change < 2; change += 1) {
          toggle.click();
          for (let frame = 0; frame < 20; frame += 1) {
            const style = getComputedStyle(row);
            const foreground = luminance(style.color);
            const background = luminance(style.backgroundColor);
            samples.push((Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05));
            await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
          }
        }
        return samples;
      };
      const actual = await sampleTransitions();
      // Negative control: restoring the old interpolation must reproduce a low-contrast midpoint.
      row.style.transition = 'background-color 160ms linear, color 160ms linear';
      const perturbed = await sampleTransitions();
      return { actual, perturbed };
    });
    expect(ratios.actual).toHaveLength(40);
    expect(Math.min(...ratios.actual), 'navigation foreground/background must never cross through a low-contrast midpoint').toBeGreaterThanOrEqual(4.5);
    expect(Math.min(...ratios.perturbed), 'the control must detect the old low-contrast interpolation').toBeLessThan(4.5);
  });
});

test.describe('UI-16 gates — 28 contract rows stay inert', () => {
  const gated = ROWS.filter((row) => row.implementation_status === 'disabled_by_gate');

  test('the gated set is exactly 28 rows', () => {
    expect(gated).toHaveLength(28);
  });

  for (const row of gated) {
    test(`gated: ${row.key}`, async ({ page }) => {
      // The account's own dashboard hosts its navigation.
      const host = LIVE.find((p) => p.account === row.account && p.contractKey.endsWith('.dashboard'))!;
      const requests: string[] = [];
      page.on('request', (request) => { if (request.url().includes('/api/v1/')) requests.push(new URL(request.url()).pathname); });
      await page.setViewportSize({ width: 1440, height: 900 });
      await openLive(page, host);

      if (row.navigation_visibility !== 'primary') {
        // A contextual child (e.g. Edit Staff Profile under Staff Detail) is not a navigation link;
        // its parent is. It needs only the no-runtime-route proof below.
      } else if (row.account === 'super_administrator') {
        // Header navigation (ADR-018): open the entry's group disclosure, then inspect the entry.
        const groupId = `nav-group-${row.navigation_group.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`;
        await page.getByTestId(`nav-group-trigger-${groupId}`).click();
        const entry = page.getByTestId(`nav-gated-${row.key}`);
        await expect(entry).toBeVisible();
        await expect(entry).toHaveAttribute('aria-disabled', 'true');
        await expect(entry).not.toHaveAttribute('href');
      } else {
        const entry = page.getByTestId('sidebar-primary-nav').getByTestId(`nav-stacked-gated-${row.key}`);
        await expect(entry).toBeVisible();
        await expect(entry).toHaveAttribute('aria-disabled', 'true');
        await expect(entry).not.toHaveAttribute('href');
        // The dependency stays readable: it is the entry's accessible description.
        const describedBy = await entry.getAttribute('aria-describedby');
        expect(describedBy, `${row.key}: gate statement`).toBeTruthy();
        await expect(page.locator(`[id="${describedBy}"]`)).toContainText('Unavailable');
      }

      // No runtime route: the canonical path renders the not-found boundary.
      const before = requests.length;
      await page.goto(row.route_path.replace(/:[A-Za-z]+Ulid/g, IDS.staff));
      await expect(page.getByTestId('public-not-found')).toBeVisible();
      const slug = row.route_path.split('/').filter(Boolean).pop()!;
      expect(requests.slice(before).filter((path) => path.includes(slug) && !path.endsWith('/me')), `${row.key}: no runtime request`).toEqual([]);
    });
  }
});
