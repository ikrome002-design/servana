/**
 * LOCAL-UAT-001 §7B — every contract page of all eight accounts, visited by THAT account's own signed-in
 * identity on THAT account's host, against the real local stack. The page list is read from the
 * generated UI-07 account-page matrix, so it is the current inventory rather than a copy of it.
 *
 *   implemented       — must land on its own path, render a real heading (not the not-found page and
 *                       not the generic error state), with no page error, console error, 5xx or failed
 *                       request. Every 4xx the page triggered is recorded for review.
 *   disabled_by_gate  — must stay visibly unavailable in navigation (named gate, no live link) and must
 *                       not render a working destination when its path is typed directly.
 */
import { readFileSync } from 'node:fs';

import { expect, test, type BrowserContext, type Page } from '@playwright/test';

import {
  ACCOUNT_KEYS,
  ACCOUNTS,
  fixtureManifest,
  origin,
  readRuntime,
  appendResult,
  statePath,
  watchPage,
  type AccountKey,
  type PageHealth,
} from './support/localUat';

interface ContractPage {
  key: string;
  label: string;
  route_path: string;
  implementation_status: 'implemented' | 'disabled_by_gate' | 'planned' | 'removed_by_authority';
  gate: string | null;
}

const matrix = JSON.parse(readFileSync('docs/frontend/audits/ui-07/account-page-matrix.json', 'utf8')) as {
  accounts: { account_type: AccountKey; pages: ContractPage[] }[];
};

const fixture = fixtureManifest();
const runtime = readRuntime();

/**
 * Implemented contracts whose correct behaviour is a redirect for the seeded identity, with the reason.
 * /setup is the first-time setup gate: a merchant that has completed setup is sent to its dashboard.
 * The setup page itself is proven by 15-merchant-self-registration.spec.ts on a brand-new merchant.
 */
const EXPECTED_REDIRECTS: Record<string, string> = {
  'merchant_administrator.setup': '/dashboard',
};

/**
 * Contextual gated children render as a DISABLED control on their parent page rather than as a
 * navigation entry (the same controls UI-11 asserts against mocks; here they are checked on the real page).
 */
const CONTEXTUAL_GATED_CONTROLS: Record<string, string> = {
  'merchant_human_resource.staff-detail-edit': 'Edit profile — unavailable',
  'merchant_human_resource.staff-detail-access': 'Assign role or branch — unavailable',
};

function resolvePath(account: AccountKey, routePath: string): string {
  const values: Record<string, string | undefined> = {
    merchantUlid: fixture.merchants.acacia.ulid,
    branchUlid: fixture.branches['WST']?.ulid,
    invoiceUlid: account === 'merchant_administrator' ? fixture.subscription_invoice_ulid : runtime['invoiceUlid'],
    staffUlid: runtime['staffUlid'],
    groupUlid: runtime['groupUlid'],
    clientUlid: runtime['clientUlid'] ?? fixture.westlands_client_ulid,
    queueUlid: runtime['queueUlid'],
    eventUlid: runtime['eventUlid'],
  };

  return routePath.replace(/:([A-Za-z]+)/g, (_, name: string) => {
    const value = values[name];
    if (value === undefined || value === '') throw new Error(`No runtime value for :${name} (run 10-role-workflows first).`);

    return value;
  });
}


for (const account of ACCOUNT_KEYS) {
  const pages = matrix.accounts.find((a) => a.account_type === account)?.pages ?? [];

  test.describe(`${account} on ${ACCOUNTS[account].host}`, () => {
    let context: BrowserContext;
    let page: Page;
    let health: PageHealth;

    test.beforeAll(async ({ browser }) => {
      context = await browser.newContext({ storageState: statePath(account) });
      page = await context.newPage();
      health = watchPage(page);
    });

    test.afterAll(async () => {
      await context.close();
    });

    for (const contract of pages.filter((p) => p.implementation_status === 'implemented')) {
      test(`implemented ${contract.key} ${contract.route_path}`, async () => {
        const target = resolvePath(account, contract.route_path);
        const errorsBefore = health.errors.length;
        const clientErrorsBefore = health.clientErrors.length;

        await page.goto(`${origin(account)}${target}`);
        await page.waitForLoadState('networkidle');

        const landed = new URL(page.url()).pathname;
        const heading = page.locator('main h1, h1').first();
        await expect(heading).toBeVisible({ timeout: 15_000 });
        const headingText = (await heading.innerText()).trim();
        const genericError = await page.getByText('Something went wrong').count();

        const errors = health.errors.slice(errorsBefore);
        const clientErrors = health.clientErrors.slice(clientErrorsBefore);
        const expectedLanding = EXPECTED_REDIRECTS[contract.key] ?? target;
        const ok = landed === expectedLanding && !/couldn't find that page/i.test(headingText) && genericError === 0 && errors.length === 0;

        appendResult('authenticated-pages', {
          account,
          key: contract.key,
          status: 'implemented',
          url: `${origin(account)}${target}`,
          landed,
          expected_landing: expectedLanding,
          heading: headingText,
          errors,
          client_errors: clientErrors,
          result: ok ? 'pass' : 'fail',
        });

        expect(landed, 'stays on its own route (or its documented redirect)').toBe(expectedLanding);
        expect(headingText).not.toMatch(/couldn't find that page/i);
        expect(genericError, 'generic error state').toBe(0);
        expect(errors, 'page errors, console errors, 5xx, failed requests').toEqual([]);
      });
    }

    for (const contract of pages.filter((p) => p.implementation_status === 'disabled_by_gate')) {
      test(`gated ${contract.key} ${contract.route_path} (${contract.gate})`, async () => {
        const errorsBefore = health.errors.length;
        const gatedPath = resolvePath(account, contract.route_path);
        // A contextual child (e.g. HR "Edit Staff Profile") is offered on its parent detail page;
        // a primary entry is offered by the account navigation, reachable from the dashboard.
        const offeredOn = contract.route_path.includes(':') ? gatedPath.replace(/\/[^/]+$/, '') : '/dashboard';

        await page.goto(`${origin(account)}${offeredOn}`);
        await page.waitForLoadState('networkidle');
        const entry = page.locator('[aria-disabled="true"]', { hasText: contract.label });
        const contextual = CONTEXTUAL_GATED_CONTROLS[contract.key];
        let disabledShown = contextual === undefined
          ? (await entry.count()) > 0
          : await page.getByRole('button', { name: contextual }).isDisabled().catch(() => false);
        // Header navigation (Super Administrator) and collapsed sidebar groups are disclosures that open
        // one at a time, so open each in turn and look while it is open.
        const triggers = page.locator('nav button[aria-expanded]');
        for (let i = 0; !disabledShown && i < (await triggers.count()); i++) {
          const trigger = triggers.nth(i);
          if (!(await trigger.isVisible())) continue;
          if ((await trigger.getAttribute('aria-expanded')) === 'false') await trigger.click();
          disabledShown = (await entry.count()) > 0;
        }
        const liveLinks = await page.locator(`a[href="${gatedPath}"]`).count();

        // Direct navigation: no working destination.
        await page.goto(`${origin(account)}${gatedPath}`);
        await page.waitForLoadState('networkidle');
        const landed = new URL(page.url()).pathname;
        const heading = (await page.locator('h1').first().innerText().catch(() => '')).trim();
        const errors = health.errors.slice(errorsBefore);
        const noDestination = landed !== gatedPath || /couldn't find that page/i.test(heading);

        appendResult('authenticated-pages', {
          account,
          key: contract.key,
          status: 'disabled_by_gate',
          gate: contract.gate,
          offered_on: offeredOn,
          disabled_entry_shown: disabledShown,
          live_links: liveLinks,
          direct_url: gatedPath,
          direct_landed: landed,
          direct_heading: heading,
          errors,
          result: disabledShown && liveLinks === 0 && noDestination && errors.length === 0 ? 'pass' : 'fail',
        });

        expect(disabledShown, 'the gated entry is shown as unavailable, naming its gate').toBe(true);
        expect(liveLinks, 'no live link to the gated path').toBe(0);
        expect(noDestination, 'typing the gated path reaches no working page').toBe(true);
        expect(errors).toEqual([]);
      });
    }
  });
}
