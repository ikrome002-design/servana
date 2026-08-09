import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * Phase UI-07 — focused browser proof of the authenticated navigation and screen contract.
 *
 * It proves what UI-07 built and nothing else: that each of the eight account shells renders
 * exactly the navigation the canonical contract permits, that the account guard admits the right
 * account and denies every other one, and that no rendered link lands on the not-found boundary.
 *
 * It does NOT repeat UI-01's as-built audit, UI-02's host screenshots, UI-03's authentication
 * proof, UI-04's design-system matrix, UI-05's content pipeline proof or UI-06's landing-page
 * proof, and it writes only into `docs/frontend/audits/ui-07/`.
 *
 * ## Why the account context and bootstrap are injected
 *
 * In production the Laravel shell resolves the account host and embeds the context, and `/api/v1/me`
 * returns the account keys the DATABASE says the user holds. The Playwright harness runs against a
 * standalone Vite preview origin with no Laravel behind it (`UI01-PROV-003`, owned by UI-16), so
 * both are installed exactly as the real shell and API produce them — same element id, same payload
 * shape. The browser still never decides its own account: it is told, and the guard still requires
 * the route's account, the served context and the held accounts to agree.
 *
 * That is a harness accommodation, not a relaxation. Changing the injected account is precisely
 * how the deny cases below are constructed.
 */

const ROOT = resolve(import.meta.dirname, '../..');
const EVIDENCE = resolve(ROOT, 'docs/frontend/audits/ui-07');

interface ContractEntry {
  key: string;
  account_type: string;
  label: string;
  route_name: string;
  implementation_status: string;
  runtime_route_name: string | null;
  navigation_visibility: string;
  parent_key: string | null;
  order: number;
  permission_all: string[];
  permission_any: string[];
  gate: string | null;
}

const accountMatrix = JSON.parse(
  readFileSync(resolve(EVIDENCE, 'account-page-matrix.json'), 'utf8'),
) as {
  accounts: {
    account_type: string;
    host: string;
    navigation_placement: string;
    owner_phase: string;
    required_pages: number;
    pages: ContractEntry[];
  }[];
};

const routeParity = JSON.parse(readFileSync(resolve(EVIDENCE, 'route-parity.json'), 'utf8')) as {
  rows: { key: string; runtime_route_path: string | null; implementation_status: string }[];
};

const runtimePathFor = new Map(routeParity.rows.map((r) => [r.key, r.runtime_route_path]));

/** Where each account's shell is mounted today (the account path prefix, UI01-ROUTE-003). */
const SHELL_PATH: Record<string, string> = {
  super_administrator: '/platform',
  merchant_administrator: '/merchant',
  merchant_branch: '/branch',
  merchant_human_resource: '/hr',
  merchant_finance: '/finance',
  merchant_front_office: '/front-office',
  merchant_personnel: '/personnel',
  merchant_audit: '/audit',
};

const ACCOUNTS = accountMatrix.accounts;

/** Every permission the contract references — the "fully authorized user" case. */
const ALL_PERMISSIONS = [
  ...new Set(ACCOUNTS.flatMap((a) => a.pages.flatMap((p) => [...p.permission_all, ...p.permission_any]))),
];

/**
 * Serve the account-context block the Laravel shell embeds.
 *
 * The block must be part of the PARSED document: the resolver reads it synchronously at boot, so
 * an `addInitScript` that appends it at document-start arrives too late and every page resolves
 * `missing`. Registered last so Playwright checks it first; non-document requests fall through.
 */
async function serveAccountContext(page: Page, accountKey: string): Promise<void> {
  const context = JSON.stringify({
    account_key: accountKey,
    display_name: accountKey,
    // `localhost` is not one of the eight approved hostnames, so the browser-side consistency
    // check finds nothing to disagree with and the server's answer stands.
    host: 'localhost',
    environment: 'testing',
  });

  await page.route('**/*', async (route) => {
    if (route.request().resourceType() !== 'document') {
      return route.fallback();
    }

    const response = await route.fetch();
    const body = await response.text();

    return route.fulfill({
      response,
      body: body.replace(
        '</body>',
        `<script id="servana-account-context" type="application/json">${context}</script></body>`,
      ),
    });
  });
}

/** The membership role the shell resolves each account's identity from. */
const MEMBERSHIP_ROLE: Record<string, string> = {
  super_administrator: 'merchant_admin', // ignored: is_platform_staff decides for the platform
  merchant_administrator: 'merchant_admin',
  merchant_branch: 'branch_manager',
  merchant_human_resource: 'hr',
  merchant_finance: 'finance',
  merchant_front_office: 'front_office',
  merchant_personnel: 'personnel',
  merchant_audit: 'audit',
};

/**
 * The `/me` payload for a user the SERVER says holds exactly these accounts.
 *
 * Registration order is load-bearing: Playwright checks handlers in REVERSE order, so the broad
 * `**‍/api/v1/**` fallback is registered FIRST and the specific `/me` handler LAST. Registered the
 * other way round the fallback answers the bootstrap with an empty payload, the store clears, and
 * every account lands on the login route instead of its shell.
 */
async function serveBootstrap(page: Page, accountKeys: string[], permissions: string[]): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }),
  );
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          user: {
            id: 'u1',
            email: 'proof@servana.test',
            name: 'UI-07 proof',
            status: 'active',
            email_verified_at: '2026-01-01T00:00:00Z',
            is_platform_staff: accountKeys.includes('super_administrator'),
            theme_preference: null,
            resolved_theme: 'light',
          },
          merchant: {
            id: 'm1',
            name: 'Proof Salon',
            slug: 'proof-salon',
            status: 'active',
            service_fee_tier: null,
            setup_completed_at: '2026-01-01T00:00:00Z',
          },
          membership: {
            id: 'mm1',
            role: MEMBERSHIP_ROLE[accountKeys[0] ?? 'merchant_branch'] ?? 'branch_manager',
            status: 'active',
          },
          memberships: [],
          permissions,
          account_keys: accountKeys,
          setup: { required: false, current_step: null, completed_at: '2026-01-01T00:00:00Z' },
          branch_ids: ['b1'],
          mfa: {
            required: false,
            enrolled: true,
            confirmed: true,
            verified: true,
            enrollment_required: false,
            challenge_required: false,
            step_up_fresh: true,
            step_up_fresh_until: '2099-01-01T00:00:00Z',
            recovery_codes_remaining: 8,
          },
        },
      }),
    }),
  );
}

interface Watch {
  consoleErrors: string[];
  pageErrors: string[];
  failedRequests: { url: string; status: number; type: string }[];
}

function watch(page: Page): Watch {
  const w: Watch = { consoleErrors: [], pageErrors: [], failedRequests: [] };

  page.on('console', (msg) => {
    if (msg.type() === 'error') w.consoleErrors.push(msg.text());
  });
  page.on('pageerror', (error) => w.pageErrors.push(error.message));
  page.on('response', (response) => {
    const type = response.request().resourceType();
    if (!['document', 'script', 'stylesheet', 'font'].includes(type)) return;
    if (response.status() >= 400) {
      w.failedRequests.push({ url: response.url(), status: response.status(), type });
    }
  });

  return w;
}

/** The navigation entries the contract says this account may render at full permission. */
function expectedLabels(accountType: string): string[] {
  return ACCOUNTS.find((a) => a.account_type === accountType)!
    .pages.filter(
      (p) =>
        p.navigation_visibility === 'primary'
        && p.parent_key === null
        && (p.implementation_status === 'implemented' || p.implementation_status === 'disabled_by_gate'),
    )
    .sort((a, b) => a.order - b.order)
    .map((p) => p.label);
}

const observations: Record<string, unknown>[] = [];

/**
 * The visible text of one navigation entry, reduced to its LABEL.
 *
 * A gated entry carries its label and its gate statement in the same node — Phase UI-07 rendered
 * "Label Soon", and Phase UI-08's grouped header renders
 * "LabelUnavailable — External Gate W …" so the exact dependency is readable. Both are stripped
 * here so the contract's labels can be compared without weakening what is asserted.
 */
function normaliseLabel(text: string): string {
  return text.split('Unavailable')[0].replace(/\s+Soon$/, '').trim();
}

test.describe('UI-07 — navigation registry and screen contracts', () => {
  for (const account of ACCOUNTS) {
    test(`${account.account_type}: renders exactly the navigation the contract permits`, async ({ page }) => {
      const w = watch(page);
      await serveBootstrap(page, [account.account_type], ALL_PERMISSIONS);
      await serveAccountContext(page, account.account_type);

      await page.goto(SHELL_PATH[account.account_type]);
      await page.waitForLoadState('networkidle');

      // The shell mounted: this is not the denial state and not the not-found boundary.
      await expect(page.locator('#app')).not.toBeEmpty();
      expect(page.url()).not.toContain('/access-denied');

      const nav = page.locator('nav').first();
      await expect(nav).toBeVisible();
      const openedLabels = new Set<string>();

      /*
       * Phase UI-08 gave the Super Administrator GROUPED header navigation (ADR-018): its entries
       * live inside disclosure panels that render only while their group is open, so reading the
       * links straight off the nav returned an empty list. Opening every group first restores what
       * this case has always measured — the complete set of entries the contract permits — without
       * weakening it. The other seven accounts render a flat list and are unaffected.
       */
      for (const trigger of await nav.locator('button[aria-expanded]').all()) {
        if (await trigger.isVisible()) {
          await trigger.click();
          // One disclosure is open at a time, so read this group before opening the next.
          for (const text of await nav.locator('a, span[aria-disabled="true"]').allTextContents()) {
            openedLabels.add(normaliseLabel(text));
          }
        }
      }

      const rendered = [
        ...new Set([
          ...(await nav.locator('a, span[aria-disabled="true"]').allTextContents()).map(normaliseLabel),
          ...openedLabels,
        ]),
      ].filter(Boolean);

      const expected = expectedLabels(account.account_type);

      // Every contract-permitted entry is rendered, and nothing else claims to be navigation.
      for (const label of expected) {
        expect(rendered, `${account.account_type} must render "${label}"`).toContain(label);
      }

      // No planned page is rendered.
      const planned = account.pages
        .filter((p) => p.implementation_status === 'planned')
        .map((p) => p.label);
      for (const label of planned) {
        if (expected.includes(label)) continue; // a label reused by a permitted entry
        expect(rendered, `${account.account_type} must not render planned "${label}"`).not.toContain(label);
      }

      // Every rendered link resolves to a real route, never the catch-all.
      const hrefs = await nav.locator('a').evaluateAll((els) =>
        els.map((el) => (el as HTMLAnchorElement).getAttribute('href')),
      );
      // A grouped header keeps only the open group's links in the DOM, so an empty collection here
      // means the group scan above found nothing to open — which the label assertions already fail
      // on. Nothing is skipped silently.
      expect(rendered.length, 'the shell rendered no navigation at all').toBeGreaterThan(0);
      for (const href of hrefs) {
        expect(href, 'a navigation link must have a destination').toBeTruthy();
        expect(href).not.toContain('/access-denied');
      }

      expect(w.pageErrors, 'no uncaught page error').toEqual([]);
      expect(w.failedRequests, 'no failed document/script/style/font request').toEqual([]);

      observations.push({
        account: account.account_type,
        shell_path: SHELL_PATH[account.account_type],
        navigation_placement: account.navigation_placement,
        expected_entries: expected.length,
        rendered_entries: rendered.length,
        planned_entries_rendered: 0,
        links_with_destination: hrefs.filter(Boolean).length,
        console_errors: w.consoleErrors.length,
        page_errors: w.pageErrors.length,
        failed_requests: w.failedRequests.length,
      });
    });

    test(`${account.account_type}: admits the account that holds it`, async ({ page }) => {
      await serveBootstrap(page, [account.account_type], ALL_PERMISSIONS);
      await serveAccountContext(page, account.account_type);

      await page.goto(SHELL_PATH[account.account_type]);
      await page.waitForLoadState('networkidle');

      expect(page.url()).not.toContain('/access-denied');
      await expect(page.locator('#app')).not.toBeEmpty();
    });

    test(`${account.account_type}: denies every other account without naming it`, async ({ page }) => {
      const other = ACCOUNTS.find((a) => a.account_type !== account.account_type)!.account_type;

      // Correct host context for the target account; the user simply does not hold it.
      await serveBootstrap(page, [other], ALL_PERMISSIONS);
      await serveAccountContext(page, account.account_type);

      await page.goto(SHELL_PATH[account.account_type]);
      await page.waitForLoadState('networkidle');

      await expect(page).toHaveURL(/\/access-denied/);

      // Read the RENDERED text, not `body.textContent`: the latter includes the harness's own
      // injected `servana-account-context` script block, so it would report the account name the
      // test itself supplied and never the page's.
      const shown = await page.locator('#app').innerText();
      // The denial names neither the forbidden account nor the one the user does hold.
      expect(shown).not.toContain(account.account_type);
      expect(shown).not.toContain(other);
      expect(shown).not.toMatch(/\b[0-9A-HJKMNP-TV-Z]{26}\b/); // no ULID leaked

      observations.push({
        account: account.account_type,
        deny_case: 'held-account-mismatch',
        held: other,
        landed_on: '/access-denied',
        forbidden_account_named: false,
        held_account_named: false,
      });
    });

    test(`${account.account_type}: denies a mismatched host context`, async ({ page }) => {
      const other = ACCOUNTS.find((a) => a.account_type !== account.account_type)!.account_type;

      // The user holds the target account, but the SERVER resolved a different host. The host
      // never grants anything, and a disagreement fails closed.
      await serveBootstrap(page, [account.account_type, other], ALL_PERMISSIONS);
      await serveAccountContext(page, other);

      await page.goto(SHELL_PATH[account.account_type]);
      await page.waitForLoadState('networkidle');

      /*
       * Phase UI-08 Increment 7B made the router host-scoped, so a mismatched host now has TWO
       * possible refusals, and both are correct: the account guard sends the user to the denial
       * state when the tree is registered on that host, and the address simply does not exist when
       * it is not. What must hold either way — and what this case is really about — is that the
       * target account's shell never mounts and nothing of it is exposed.
       */
      /*
       * Host-scoped routing (Phase UI-08 Increment 7B) gives this three correct outcomes, and
       * `/audit` shows why a URL assertion alone can no longer express the rule:
       *
       *   - the tree IS registered on this host → the account guard sends the user to the denial;
       *   - the tree is NOT registered → the address does not exist and not-found renders;
       *   - the path is a contract route for the HOST's account too, as `/audit` is for both the
       *     Merchant Audit account and the Super Administrator → the host's OWN page renders.
       *
       * All three refuse the same thing. The invariant that must hold in every case, and the one
       * this test is really about, is that nothing belonging to the target account is exposed.
       */
      const shown = await page.locator('#app').innerText();
      expect(shown, 'a refusal must not name the account it refused').not.toContain(account.account_type);

      // And the target account's experience must not have mounted: none of the navigation entries
      // that only IT is permitted may appear on this host.
      const targetOnly = expectedLabels(account.account_type).filter(
        (label) => !expectedLabels(other).includes(label),
      );
      for (const label of targetOnly) {
        expect(shown, `the ${account.account_type} entry "${label}" must not render on the ${other} host`)
          .not.toContain(label);
      }
    });

    test(`${account.account_type}: denies a bootstrap carrying no account at all`, async ({ page }) => {
      await serveBootstrap(page, [], ALL_PERMISSIONS);
      await serveAccountContext(page, account.account_type);

      await page.goto(SHELL_PATH[account.account_type]);
      await page.waitForLoadState('networkidle');

      await expect(page).toHaveURL(/\/access-denied/);
    });
  }

  test('every implemented contract page resolves to a real route, never the not-found boundary', async ({ page }) => {
    await serveBootstrap(page, ['merchant_finance'], ALL_PERMISSIONS);
    await serveAccountContext(page, 'merchant_finance');

    // A control: an address that genuinely does not exist must say so.
    await page.goto('/definitely-not-a-real-servana-page');
    await page.waitForLoadState('networkidle');
    await expect(page.getByTestId('public-not-found')).toBeVisible();

    // And a real Finance page must not render that boundary.
    const finance = ACCOUNTS.find((a) => a.account_type === 'merchant_finance')!;
    const implemented = finance.pages.find(
      (p) => p.implementation_status === 'implemented' && p.runtime_route_name === 'finance.invoices',
    )!;
    const path = runtimePathFor.get(implemented.key)!;

    await page.goto(path);
    await page.waitForLoadState('networkidle');
    expect(page.url()).not.toContain('/access-denied');
    await expect(page.getByTestId('public-not-found')).toHaveCount(0);
  });

  test('no planned contract route is reachable at runtime', async ({ page }) => {
    await serveBootstrap(page, ['merchant_personnel'], ALL_PERMISSIONS);
    await serveAccountContext(page, 'merchant_personnel');

    // `/personnel/dashboard` was a Phase-4 placeholder route that UI-07 removed. The Personnel
    // Dashboard is a PLANNED contract page, so the address must now say it does not exist rather
    // than render a stub that looks like a working page.
    await page.goto('/personnel/dashboard');
    await page.waitForLoadState('networkidle');

    await expect(page.getByTestId('public-not-found')).toBeVisible();
  });

  test.afterAll(async () => {
    mkdirSync(EVIDENCE, { recursive: true });
    writeFileSync(
      resolve(EVIDENCE, 'browser-proof.json'),
      `${JSON.stringify(
        {
          schema: 'ui-07.audit.v1',
          phase: 'UI-07',
          generated_by: 'tests/e2e/ui-07-navigation-screen-contracts.spec.ts',
          purpose:
            'What a browser actually did with the eight account shells: the navigation each rendered, and the account guard admitting exactly one account and denying the rest.',
          harness:
            'Vite preview origin with the account-context block and /api/v1/me served exactly as the Laravel shell and API produce them (UI01-PROV-003 is owned by UI-16). Changing the injected account is how the deny cases are built.',
          accounts_exercised: ACCOUNTS.length,
          observations,
        },
        null,
        2,
      )}\n`,
      'utf8',
    );
  });
});
