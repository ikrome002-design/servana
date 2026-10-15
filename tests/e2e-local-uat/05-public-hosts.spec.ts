/**
 * LOCAL-UAT-001 §7A — the public entry surface of ALL eight local account hosts, served by the real
 * nginx edge and Laravel SPA shell (no Vite preview, no mocked API).
 */
import { request as nodeHttp } from 'node:http';

import { expect, test } from '@playwright/test';

import { ACCOUNT_KEYS, ACCOUNTS, PORT, origin, watchPage, writeResult } from './support/localUat';

const PUBLIC_PATHS = ['/', '/login', '/faq', '/legal/data-policy', '/legal/privacy-policy', '/legal/terms-of-service'];

const results: Record<string, unknown>[] = [];

test.afterAll(() => writeResult('public-hosts.json', results));

for (const account of ACCOUNT_KEYS) {
  test.describe(`${account} public host ${ACCOUNTS[account].host}`, () => {
    for (const publicPath of PUBLIC_PATHS) {
      test(`${publicPath} renders the ${account} experience`, async ({ page }) => {
        const health = watchPage(page);
        const imageAccounts = new Set<string>();
        page.on('request', (req) => {
          const match = /\/landing_page_images\/(?:generated\/)?([a-z_]+)\//.exec(new URL(req.url()).pathname);
          if (match?.[1] !== undefined) imageAccounts.add(match[1]);
        });

        const response = await page.goto(`${origin(account)}${publicPath}`);
        expect(response?.status()).toBe(200);
        await page.waitForLoadState('networkidle');

        // The server resolved THIS host to THIS account (the embedded, non-executing context block).
        const context = JSON.parse((await page.locator('#servana-account-context').textContent()) ?? '{}') as { account_key?: string };
        expect(context.account_key).toBe(account);

        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.locator('h1').first()).not.toHaveText(/couldn't find that page/i);

        // Every image on the page decoded (logo, landing imagery) — no broken asset.
        const broken = await page.locator('img').evaluateAll((imgs) =>
          (imgs as HTMLImageElement[]).filter((img) => img.complete && img.naturalWidth === 0).map((img) => img.getAttribute('src')),
        );
        expect(broken, 'broken images').toEqual([]);

        // No landing image from another account's directory (content bleed).
        expect([...imageAccounts].filter((key) => key !== account), 'cross-account landing images').toEqual([]);
        expect(health.errors, 'page errors / CSP violations / 5xx').toEqual([]);

        results.push({ account, host: ACCOUNTS[account].host, path: publicPath, status: response?.status(), images_from: [...imageAccounts], result: 'pass' });
      });
    }

    test('/dashboard is protected when signed out', async ({ page }) => {
      await page.goto(`${origin(account)}/dashboard`);
      await page.waitForURL(/\/(auth\/)?login/);
      const me = await page.evaluate(async () => (await fetch('/api/v1/me', { headers: { Accept: 'application/json' } })).status);
      expect(me).toBe(401);
      results.push({ account, host: ACCOUNTS[account].host, path: '/dashboard (signed out)', landed: new URL(page.url()).pathname, api_me: me, result: 'pass' });
    });

    test('self-registration is offered only by the Merchant Administrator landing page', async ({ page }) => {
      await page.goto(`${origin(account)}/`);
      await page.waitForLoadState('networkidle');
      const registerLinks = await page.locator('a[href$="/register"]').count();
      // UI/UX plan §4.2: only the Merchant Administrator host provides /register.
      expect(registerLinks > 0).toBe(account === 'merchant_administrator');

      // Observation LUAT-F01 (recorded, not asserted): the SPA router still renders the merchant
      // registration form when /register is typed directly on another host. The backend binds the
      // resulting link to the Merchant Administrator host regardless, so no account boundary moves.
      await page.goto(`${origin(account)}/register`);
      await page.waitForLoadState('networkidle');
      const directFormRendered = (await page.getByRole('heading', { name: 'Create your business account' }).count()) > 0;
      if (account !== 'merchant_administrator' && directFormRendered) {
        test.info().annotations.push({ type: 'LUAT-F01', description: `/register renders the registration form on ${ACCOUNTS[account].host}` });
      }
      results.push({ account, path: '/register', landing_register_links: registerLinks, direct_url_renders_form: directFormRendered, result: 'pass' });
    });
  });
}

test('the edge closes the connection for a host that is not an approved account host', async () => {
  const outcome = await new Promise<string>((resolve) => {
    const req = nodeHttp({ host: '127.0.0.1', port: PORT, path: '/', headers: { Host: 'evil.servana.test' } }, (res) => resolve(`status ${res.statusCode}`));
    req.on('error', (error) => resolve(`connection closed (${(error as NodeJS.ErrnoException).code ?? error.message})`));
    req.end();
  });
  expect(outcome).toMatch(/connection closed/);
  results.push({ host: 'evil.servana.test', path: '/', outcome, result: 'pass' });
});

test('health endpoints answer through the edge and disclose statuses only', async ({ page }) => {
  await page.goto(`${origin('merchant_administrator')}/`);
  const deep = await page.evaluate(async () => (await fetch('/health/deep')).json() as Promise<{ status: string; checks: Record<string, { status: string }> }>);
  expect(deep.status).toBe('ok');
  for (const [name, check] of Object.entries(deep.checks)) {
    expect(Object.keys(check), `${name} discloses only a status`).toEqual(['status']);
  }
  results.push({ path: '/health/deep', status: deep.status, checks: deep.checks, result: 'pass' });
});
