import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 22 E2E — the global search screen (Plan §68, §80 Phase 22, §27.1; ADR-010).
 |
 | The SPA preview has no backend, so `/me` + `/api/v1/search` are stubbed to drive the REAL frontend.
 | Tenant/branch isolation, per-type authority, the blind-index phone path, engine filtering and the
 | catalogue allowlists are all proven by the backend suite (tests/Feature/Search/*). These prove
 | FRONTEND behaviour: the request the browser actually sends, the states it renders, keyboard and
 | accessibility, and that NOTHING resembling contact data or an export control ever reaches the page,
 | its storage or its URL.
 |
 | THE FIXTURE PHONE `+254712345678` IS DELIBERATE: every assertion checks that neither it nor its
 | national form appears anywhere, so a regression that starts returning contact fails here too.
 */

const FULL_PHONE = '+254712345678';
const NATIONAL_PHONE = '712345678';
const CLIENT_ID = '01HZZCLIENT0000000000000001';
const INVOICE_ID = '01HZZINVOICE000000000000001';

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

function envelope(status: number, code: string, message: string) {
  return {
    status,
    contentType: 'application/json',
    body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }),
  };
}

interface MeOpts {
  role?: string | null;
  permissions?: string[];
  branchIds?: string[];
}

async function stubMe(page: Page, opts: MeOpts = {}): Promise<void> {
  // Phase UI-07: /search is a cross-account utility route, but a result LINKS INTO the owning
  // account's tree (a client result opens /front-office/clients/:id), so the harness must serve
  // that account's host context exactly as the shell does.
  const membershipRole = opts.role === null ? null : (opts.role ?? 'front_office');
  await stubAccountContextForRole(page, membershipRole, false);
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@citrus.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: membershipRole === null ? null : { id: 'mm1', role: membershipRole, status: 'active' },
        memberships: membershipRole === null ? [] : [{ id: 'mm1', role: membershipRole, status: 'active' }],
        account_keys: membershipRole === null ? [] : [accountKeyForRole(membershipRole, false)],
        permissions: opts.permissions ?? ['client.view', 'front_office.search', 'invoice.view'],
        setup: { required: false, current_step: null, completed_at: null },
        branch_ids: opts.branchIds ?? ['b1'],
        mfa: { required: false, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: null, recovery_codes_remaining: 5 },
      },
    })),
  );
}

/** A client result exactly as the Resource returns it — no contact field exists in the schema. */
const clientResult = {
  type: 'client',
  type_label: 'Client',
  ulid: CLIENT_ID,
  title: 'Amina Wanjiku',
  subtitle: null,
  snippet: null,
  status: 'active',
  date: '2026-07-25T10:00:00+00:00',
  amount: null,
  route: { name: 'front-office.clients.detail', id: CLIENT_ID },
  branch: { ulid: 'b1', name: 'Westlands Branch' },
};

const invoiceResult = {
  type: 'invoice',
  type_label: 'Invoice',
  ulid: INVOICE_ID,
  title: 'INV-000123',
  subtitle: 'Amina Wanjiku',
  snippet: null,
  status: 'issued',
  date: '2026-07-24T08:00:00+00:00',
  amount: { amount: 500000, currency: 'KES', formatted: 'KES 5,000.00' },
  route: { name: 'front-office.invoices.detail', id: INVOICE_ID },
  branch: { ulid: 'b1', name: 'Westlands Branch' },
};

function searchBody(rows: unknown[], query = 'Amina') {
  return {
    data: rows,
    meta: { query, types: ['client', 'invoice'], limit: 5, next_cursor: null },
  };
}

async function stubSearch(page: Page, rows: unknown[] = [clientResult, invoiceResult]): Promise<void> {
  await page.route('**/api/v1/search*', (r) => r.fulfill(ok(searchBody(rows))));
}

async function gotoSearch(page: Page, opts: MeOpts = {}): Promise<void> {
  await stubMe(page, opts);
  await page.goto('/search');
}

async function runSearch(page: Page, term = 'Amina'): Promise<void> {
  await page.locator('#search-q').fill(term);
  await page.getByTestId('search-submit').click();
}

/* ================================================================== states */

test('shows the idle prompt before any search', async ({ page }) => {
  await gotoSearch(page);

  await expect(page.getByTestId('search-idle')).toBeVisible();
  await expect(page.getByTestId('search-results')).toHaveCount(0);
});

test('renders grouped results with type labels, status, branch and money', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);

  await expect(page.getByTestId('search-results')).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Client' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Invoice' })).toBeVisible();
  await expect(page.getByText('Amina Wanjiku').first()).toBeVisible();
  await expect(page.getByText('INV-000123')).toBeVisible();
  await expect(page.getByText('KES 5,000.00')).toBeVisible();
  await expect(page.getByText('Westlands Branch').first()).toBeVisible();
});

test('navigates to the result target route', async ({ page }) => {
  await stubSearch(page, [clientResult]);
  await gotoSearch(page);
  await runSearch(page);

  await page.getByTestId('search-result-client').first().click();

  await expect(page).toHaveURL(new RegExp(`/front-office/clients/${CLIENT_ID}$`));
});

test('shows a safe empty state that does not distinguish "no match" from "no access"', async ({ page }) => {
  await stubSearch(page, []);
  await gotoSearch(page);
  await runSearch(page, 'Zzzznotathing');

  await expect(page.getByTestId('search-empty')).toBeVisible();
  await expect(page.getByTestId('search-empty')).toContainText('do not have access');
});

test('renders a rate-limited refusal as actionable copy, not a raw code', async ({ page }) => {
  await page.route('**/api/v1/search*', (r) => r.fulfill(envelope(429, 'rate_limited', 'Slow down.')));
  await gotoSearch(page);
  await runSearch(page);

  const banner = page.getByTestId('search-rate-limited');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText('Too many searches');
  await expect(banner).not.toContainText('rate_limited');
  await expect(banner).not.toContainText('429');
});

test('renders a forbidden refusal as actionable copy', async ({ page }) => {
  await page.route('**/api/v1/search*', (r) => r.fulfill(envelope(403, 'forbidden', 'No.')));
  await gotoSearch(page);
  await runSearch(page);

  await expect(page.getByTestId('search-forbidden')).toContainText('access changed');
});

test('renders a server error as actionable copy', async ({ page }) => {
  await page.route('**/api/v1/search*', (r) => r.fulfill(envelope(500, 'internal_error', 'Boom.')));
  await gotoSearch(page);
  await runSearch(page);

  await expect(page.getByTestId('search-error')).toContainText('unavailable');
});

test('shows the empty state to a role with no searchable authority, never a 403 screen', async ({ page }) => {
  // The server answers 200 + empty for a caller with no searchable type (decision D-22-01), so the
  // frontend must render the ordinary empty state rather than an access-denied surface.
  await stubSearch(page, []);
  await gotoSearch(page, { role: 'merchant_audit', permissions: ['receipt.view'] });
  await runSearch(page);

  await expect(page.getByTestId('search-empty')).toBeVisible();
  await expect(page.getByTestId('search-forbidden')).toHaveCount(0);
});

/* ============================================================ the request */

test('sends only the allowlisted parameters', async ({ page }) => {
  const urls: string[] = [];
  await page.route('**/api/v1/search*', (r) => {
    urls.push(r.request().url());
    return r.fulfill(ok(searchBody([clientResult])));
  });

  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  expect(urls.length).toBeGreaterThan(0);

  for (const url of urls) {
    const params = new URL(url).searchParams;

    expect([...params.keys()].sort()).toEqual(['q']);

    for (const forbidden of [
      'merchant_id', 'merchant_ulid', 'branch_id', 'branch_ids',
      'staff_profile_id', 'staff_profile_ulid', 'permission', 'permissions',
      'role', 'filter', 'filters', 'raw_filter', 'index', 'api_key',
      'include_sensitive', 'include_phone', 'include_email',
      'export', 'download', 'print', 'copy',
    ]) {
      expect(params.has(forbidden)).toBe(false);
    }
  }
});

test('does not query the API for a single character', async ({ page }) => {
  let calls = 0;
  await page.route('**/api/v1/search*', (r) => {
    calls += 1;
    return r.fulfill(ok(searchBody([])));
  });

  await gotoSearch(page);
  await page.locator('#search-q').fill('a');
  await page.getByTestId('search-submit').click();
  await expect(page.getByTestId('search-idle')).toBeVisible();

  expect(calls).toBe(0);
});

test('never contacts a search engine directly from the browser', async ({ page }) => {
  const hosts: string[] = [];
  page.on('request', (request) => hosts.push(new URL(request.url()).host));

  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  for (const host of hosts) {
    expect(host).not.toContain('7700');
    expect(host).not.toContain('meili');
  }
});

/* ============================================== contact protection (ADR-010) */

test('shows no contact value anywhere on the screen', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  const html = await page.content();

  expect(html).not.toContain(FULL_PHONE);
  expect(html).not.toContain(NATIONAL_PHONE);
  expect(html).not.toContain('5678');
  expect(html).not.toContain('phone_masked');
  expect(html).not.toContain('phone_last_four');
});

test('offers no export, download, print or copy control', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  for (const label of [/export/i, /download/i, /print/i, /copy/i, /csv/i, /xlsx/i, /vcard/i]) {
    await expect(page.getByRole('button', { name: label })).toHaveCount(0);
    await expect(page.getByRole('link', { name: label })).toHaveCount(0);
  }
});

test('never calls the clipboard API or window.print', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);

  await page.evaluate(() => {
    const w = window as unknown as { __calls: string[] };
    w.__calls = [];
    window.print = () => w.__calls.push('print');

    if (navigator.clipboard) {
      Object.defineProperty(navigator.clipboard, 'writeText', {
        configurable: true,
        value: () => {
          w.__calls.push('clipboard');
          return Promise.resolve();
        },
      });
    }
  });

  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  const calls = await page.evaluate(() => (window as unknown as { __calls: string[] }).__calls);
  expect(calls).toEqual([]);
});

test('puts nothing into storage and no contact into the URL', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  const storage = await page.evaluate(() => ({
    local: JSON.stringify(window.localStorage),
    session: JSON.stringify(window.sessionStorage),
  }));

  expect(storage.local).not.toContain('Amina');
  expect(storage.local).not.toContain(NATIONAL_PHONE);
  expect(storage.session).not.toContain('Amina');
  expect(storage.session).not.toContain(NATIONAL_PHONE);

  // The SPA route itself carries no query string, so the term never lands in browser history.
  expect(page.url()).not.toContain('q=');
  expect(page.url()).not.toContain(NATIONAL_PHONE);
});

test('clears results when the term is cleared', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  await page.getByTestId('search-clear').click();

  await expect(page.getByTestId('search-results')).toHaveCount(0);
  await expect(page.getByTestId('search-idle')).toBeVisible();
});

/* ====================================== responsive · dark · accessibility */

const VIEWPORTS = [
  { name: 'mobile', width: 360, height: 780 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 900 },
];

for (const viewport of VIEWPORTS) {
  for (const scheme of ['light', 'dark'] as const) {
    test(`is accessible with no horizontal overflow at ${viewport.name} in ${scheme} mode`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await stubSearch(page);
      await gotoSearch(page);
      await runSearch(page);
      await expect(page.getByTestId('search-results')).toBeVisible();

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
      );
      expect(overflow).toBe(false);

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious).toEqual([]);
    });
  }
}

test('is accessible at 200% zoom without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 640, height: 780 });
  await stubSearch(page);
  await gotoSearch(page);
  await runSearch(page);
  await expect(page.getByTestId('search-results')).toBeVisible();

  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );
  expect(overflow).toBe(false);
});

test('is fully operable by keyboard', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);

  const input = page.locator('#search-q');
  await input.focus();
  await expect(input).toBeFocused();

  await page.keyboard.type('Amina');
  await page.keyboard.press('Enter');

  await expect(page.getByTestId('search-results')).toBeVisible();

  // Tab reaches the submit and clear controls, and then the first result link.
  await input.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByTestId('search-submit')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByTestId('search-clear')).toBeFocused();
});

test('announces every outcome in a live region', async ({ page }) => {
  await stubSearch(page);
  await gotoSearch(page);

  const status = page.getByTestId('search-status');
  await expect(status).toHaveAttribute('aria-live', 'polite');
  await expect(status).toHaveAttribute('role', 'status');

  await runSearch(page);
  await expect(status).toContainText('2 results');
});

test('labels the search landmark and its input', async ({ page }) => {
  await gotoSearch(page);

  await expect(page.getByRole('search')).toBeVisible();
  await expect(page.locator('#search-q')).toBeVisible();
  await expect(page.getByLabel('Search', { exact: true })).toBeVisible();
});
