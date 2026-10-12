/**
 * Phase UI-16 — release browser gate against the BUILT production origin (closes UI01-PROV-003).
 *
 * Every spec under tests/e2e runs against a Vite preview origin with a stubbed API and a
 * harness-installed account context, so a serving defect (wrong base path, missing manifest,
 * wrong MIME, server-side context drift) cannot fail it. This suite drives a real Chromium at
 * the production nginx + php image pair: each canonical account host is resolved to the local
 * edge by Chromium's host resolver, so the Host header, the server-resolved account context
 * and the served bundle are exactly what production serves.
 *
 * Run (after starting the built images, see docs/proof/ui-16.md):
 *   SERVANA_ORIGIN=127.0.0.1:8116 npx playwright test -c playwright.origin.config.ts
 */
import { expect, test, type Page, type Response } from '@playwright/test';
import { readFileSync } from 'node:fs';

interface HostRegistry {
  domains: { production: string };
  accounts: { account_key: string; subdomain: string | null }[];
}

const registry = JSON.parse(readFileSync('config/account-hosts.json', 'utf8')) as HostRegistry;
const HOSTS = registry.accounts.map((account) => ({
  key: account.account_key,
  host: account.subdomain === null ? registry.domains.production : `${account.subdomain}.${registry.domains.production}`,
}));

/** Records every same-host response and every page/console error while `page` is open. */
function observe(page: Page, host: string) {
  const failures: string[] = [];
  const assets: { url: string; status: number; type: string }[] = [];
  page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    // Chromium echoes every non-2xx resource as a console error; statuses are judged per URL below.
    if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) failures.push(`console: ${message.text()}`);
  });
  page.on('response', (response: Response) => {
    const url = new URL(response.url());
    if (url.hostname !== host) return;
    const status = response.status();
    const type = response.headers()['content-type'] ?? '';
    if (/\.(js|css|woff2|png|webp|svg|ico)$/.test(url.pathname)) assets.push({ url: url.pathname, status, type });
    // An unauthenticated session probe is expected to be refused; anything else must succeed.
    const expectedRefusal = url.pathname.startsWith('/api/') && (status === 401 || status === 419);
    if (status >= 400 && !expectedRefusal) failures.push(`${status} ${url.pathname}`);
  });
  page.on('requestfailed', (request) => failures.push(`requestfailed: ${request.url()} ${request.failure()?.errorText ?? ''}`));
  return { failures, assets };
}

async function embeddedAccount(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const node = document.getElementById('servana-account-context');
    if (!node?.textContent) return null;
    try {
      return (JSON.parse(node.textContent) as { account_key?: string }).account_key ?? null;
    } catch {
      return null;
    }
  });
}

test.describe('UI-16 production origin — eight account hosts', () => {
  for (const { key, host } of HOSTS) {
    test(`${host}: landing shell, entry assets, server context, light first paint`, async ({ page }) => {
      const seen = observe(page, host);
      const response = await page.goto(`http://${host}/`, { waitUntil: 'networkidle' });
      expect(response?.status()).toBe(200);
      expect(response?.headers()['content-type']).toContain('text/html');
      expect(await embeddedAccount(page)).toBe(key);
      await expect(page.locator('h1').first()).toBeVisible();
      await expect(page.getByTestId('sv-fixed-footer')).toBeVisible();
      // Light is the default even though nothing chose it (ADR-021).
      expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(false);
      // The entry bundle really loaded: a script and a stylesheet from the fingerprinted directory.
      const entry = seen.assets.filter((asset) => asset.url.startsWith('/spa-assets/') || asset.url.startsWith('/assets/'));
      expect(entry.some((asset) => asset.url.endsWith('.js') && asset.type.includes('javascript'))).toBe(true);
      expect(entry.some((asset) => asset.url.endsWith('.css') && asset.type.includes('text/css'))).toBe(true);
      expect(entry.filter((asset) => asset.status !== 200)).toEqual([]);
      expect(seen.failures).toEqual([]);
    });

    test(`${host}: sign-in page and protected deep link`, async ({ page }) => {
      const seen = observe(page, host);
      const login = await page.goto(`http://${host}/login`, { waitUntil: 'networkidle' });
      expect(login?.status()).toBe(200);
      expect(await embeddedAccount(page)).toBe(key);
      await expect(page.locator('input[type="email"]').first()).toBeVisible();
      // A protected route never renders protected content to an anonymous visitor.
      const deep = await page.goto(`http://${host}/dashboard`, { waitUntil: 'networkidle' });
      expect(deep?.status()).toBeLessThan(500);
      await expect(page.locator('input[type="email"]').first()).toBeVisible();
      expect(seen.failures).toEqual([]);
    });
  }
});
