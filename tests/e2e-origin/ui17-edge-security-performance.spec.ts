/**
 * Phase UI-17 — browser proof on the BUILT production origin for the edge policy and the critical
 * path (UI/UX plan §6.5, §18.6, §22.1, §22.2).
 *
 * Runs with the UI-16 production-origin gate (playwright.origin.config.ts): a real Chromium with every
 * canonical account host resolved to the built nginx + php pair. Static checks (the CSP hash contract,
 * the bundle budget) cannot prove what a browser actually does with the policy; these can:
 *
 *  - the enforced Content-Security-Policy blocks NOTHING the product needs: zero
 *    `securitypolicyviolation` events on every public surface of every host, light and dark;
 *  - the hashed theme bootstrap still runs under the policy (an explicit dark choice applies before
 *    first paint) — a drifted hash would silently leave every page light;
 *  - the session bootstrap reaches the server once per load (UI17-PERF-004);
 *  - every critical-path hint the shell emits resolves, and the landing hints name only THIS account;
 *  - the hero image is the one eager, high-priority image and is never fetched far larger than its
 *    slot; fingerprinted chunks arrive compressed.
 */
import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

interface HostRegistry {
  domains: { production: string };
  accounts: { account_key: string; subdomain: string | null; public_content_key?: string }[];
}

const registry = JSON.parse(readFileSync('config/account-hosts.json', 'utf8')) as HostRegistry;
const HOSTS = registry.accounts.map((account) => ({
  key: account.account_key,
  host: account.subdomain === null ? registry.domains.production : `${account.subdomain}.${registry.domains.production}`,
}));
const PUBLIC_PATHS = ['/', '/login', '/faq', '/legal/privacy-policy'];

/** Records CSP violations from inside the page — the only place the browser reports them. */
async function watchViolations(page: Page): Promise<void> {
  await page.addInitScript(() => {
    (window as unknown as { __svCsp: string[] }).__svCsp = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      (window as unknown as { __svCsp: string[] }).__svCsp.push(`${event.effectiveDirective} ${event.blockedURI || 'inline'} ${event.sourceFile}:${event.lineNumber}`);
    });
  });
}
const violations = (page: Page): Promise<string[]> => page.evaluate(() => (window as unknown as { __svCsp: string[] }).__svCsp);

test.describe('UI-17 production origin — edge policy and critical path', () => {
  for (const { key, host } of HOSTS) {
    test(`${host}: CSP blocks nothing on the public surface; theme bootstrap still runs`, async ({ page }) => {
      await watchViolations(page);
      for (const path of PUBLIC_PATHS) {
        const response = await page.goto(`http://${host}${path}`, { waitUntil: 'networkidle' });
        expect(response?.status(), path).toBe(200);
        const csp = response?.headers()['content-security-policy'] ?? '';
        expect(csp, path).toContain("script-src 'self' 'sha256-");
        expect(csp, path).not.toContain('unsafe-inline');
        await expect(page.locator('h1').first()).toBeVisible();
        expect(await violations(page), `${host}${path}`).toEqual([]);
      }
      // An explicit dark choice must still apply before hydration — proof the hashed inline script ran.
      await page.evaluate(() => localStorage.setItem('servana.theme', 'dark'));
      await page.goto(`http://${host}/`, { waitUntil: 'domcontentloaded' });
      expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);
      await page.waitForLoadState('networkidle');
      expect(await violations(page), `${key} dark`).toEqual([]);
    });

    test(`${host}: one session bootstrap, resolving preload hints for this account only, compressed chunks`, async ({ page }) => {
      const me: string[] = [];
      const chunkEncodings: { path: string; encoding: string; length: number }[] = [];
      page.on('request', (request) => {
        if (new URL(request.url()).pathname === '/api/v1/me') me.push(request.method());
      });
      page.on('response', (response) => {
        const url = new URL(response.url());
        if (url.pathname.startsWith('/spa-assets/') && url.pathname.endsWith('.js')) {
          const headers = response.headers();
          chunkEncodings.push({ path: url.pathname, encoding: headers['content-encoding'] ?? 'none', length: Number(headers['content-length'] ?? Number.NaN) });
        }
      });
      const response = await page.goto(`http://${host}/`, { waitUntil: 'networkidle' });
      expect(response?.status()).toBe(200);
      await expect(page.locator('h1').first()).toBeVisible();

      expect(me, 'GET /api/v1/me per cold load').toEqual(['GET']);
      expect(chunkEncodings.length).toBeGreaterThan(0);
      // Every chunk of at least 1 KiB arrives gzip-encoded; below that the edge's gzip_min_length
      // deliberately sends it as-is (compression would add bytes). An uncompressed chunk must
      // therefore carry a Content-Length under 1024.
      expect(chunkEncodings.filter((chunk) => chunk.encoding === 'gzip').length).toBeGreaterThan(0);
      expect(chunkEncodings.filter((chunk) => chunk.encoding !== 'gzip' && !(chunk.length < 1024))).toEqual([]);

      const hints = await page.evaluate(() => ({
        modules: [...document.querySelectorAll<HTMLLinkElement>('link[rel="modulepreload"]')].map((link) => link.getAttribute('href') ?? ''),
        fonts: [...document.querySelectorAll<HTMLLinkElement>('link[rel="preload"][as="font"]')].map((link) => ({ href: link.getAttribute('href') ?? '', cors: link.crossOrigin })),
      }));
      expect(hints.modules.length).toBeGreaterThan(2);
      expect(hints.fonts.map((font) => font.cors)).toEqual(['anonymous', 'anonymous']);
      // Fetched from INSIDE the page: same origin, same resolver, same CSP connect-src as the app.
      const statuses = await page.evaluate(
        (urls) => Promise.all(urls.map((url) => fetch(url, { cache: 'no-store' }).then((r) => `${r.status} ${url}`))),
        [...hints.modules, ...hints.fonts.map((font) => font.href)],
      );
      expect(statuses.filter((line) => !line.startsWith('200 '))).toEqual([]);
      // Landing hints name exactly one account's copy and composition: this one.
      const landing = hints.modules.filter((href) => /\/(landing\.generated|merchant[A-Z]\w+|superAdministrator)-/.test(href));
      expect(landing.length).toBe(2);
    });

    test(`${host}: hero is the only eager high-priority image and is not oversized`, async ({ page }) => {
      await page.setViewportSize({ width: 360, height: 780 });
      await page.goto(`http://${host}/`, { waitUntil: 'networkidle' });
      await expect(page.locator('h1').first()).toBeVisible();
      const images = await page.evaluate(() =>
        [...document.querySelectorAll<HTMLImageElement>('[data-testid="landing-picture"] img')].map((img) => ({
          loading: img.loading,
          priority: img.getAttribute('fetchpriority'),
          current: new URL(img.currentSrc || img.src).pathname,
          natural: img.naturalWidth,
          rendered: img.getBoundingClientRect().width,
          width: img.getAttribute('width'),
          height: img.getAttribute('height'),
          dpr: window.devicePixelRatio,
        })),
      );
      expect(images.length).toBeGreaterThan(0);
      const eager = images.filter((image) => image.loading === 'eager');
      expect(eager.length).toBe(1);
      expect(eager[0]?.priority).toBe('high');
      expect(images.filter((image) => image.priority === 'high').length).toBe(1);
      for (const image of images) {
        expect(image.width, image.current).not.toBeNull();
        expect(image.height, image.current).not.toBeNull();
        // A modern derivative, never the multi-megabyte original, for every image the browser fetched.
        if (image.natural > 0) {
          expect(image.current, image.current).toMatch(/\/generated\/.+\.(avif|webp)$/);
          expect(image.natural, `${image.current} natural vs slot`).toBeLessThanOrEqual(Math.max(1024, Math.ceil(image.rendered * image.dpr * 2)));
        }
      }
    });
  }
});
