import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 15A E2E — Branch Manager catalogue, HR eligibility, Front Office clients
 | (Plan §35, §39). The SPA preview has no live backend, so /me + /api/v1 are
 | stubbed to drive the REAL frontend behaviour: navigation, permission-gated
 | actions, masked client contact, search, and the duplicate-client conflict. The
 | genuine backend authorization/isolation/masking is proven by the feature suites
 | (tests/Feature/Catalogue/*, tests/Feature/Clients/*). Linux CI is the
 | authoritative browser gate (local Windows Playwright is not).
 */

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, role, false);

  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: { id: 'mm1', role, status: 'active' },
          memberships: [{ id: 'mm1', role, status: 'active' }],
          account_keys: [accountKeyForRole(role, false)],
          permissions,
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
        },
      }),
    }),
  );
}

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

test.describe('Branch Manager service catalogue', () => {
  test('lists services and gates the add action on service.create', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['service.view', 'service.create', 'service.update', 'service.archive']);
    await page.route('**/api/v1/service-categories**', (r) => r.fulfill(ok({ data: [{ id: 'c1', name: 'Hair', sort_order: 0, archived: false }] })));
    await page.route('**/api/v1/services**', (r) =>
      r.fulfill(ok({ data: [{ id: 's1', category_id: 'c1', category_name: 'Hair', name: 'Gel manicure', description: null, price_minor: 250000, currency: 'KES', duration_minutes: 45, status: 'active' }] })),
    );

    await page.goto('/branch/services');
    await expect(page.getByRole('heading', { name: 'Services' })).toBeVisible();
    await expect(page.getByText('Gel manicure')).toBeVisible();
    await expect(page.getByText('KES')).toBeVisible();
    await expect(page.getByTestId('add-service')).toBeVisible();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

test.describe('HR service eligibility', () => {
  test('selects a service and lists assignable personnel', async ({ page }) => {
    await stubMe(page, 'hr', ['personnel.eligibility.manage', 'staff.view']);
    await page.route('**/api/v1/hr/service-options**', (r) =>
      r.fulfill(ok({ data: [{ ulid: 's1', name: 'Gel manicure' }] })),
    );
    await page.route(/\/api\/v1\/staff(\?|$)/, (r) => r.fulfill(ok({ data: [{ id: 'sp1', display_name: 'Brian K', first_name: 'Brian', last_name: 'K', phone: '', role: 'personnel', role_title: null, status: 'active', employment_type: 'full_time', employment_status: 'employed', primary_branch_id: 'b1', is_active: true }] })));
    await page.route('**/api/v1/services/s1/eligibility', (r) => r.fulfill(ok({ data: [] })));

    await page.goto('/eligibility');
    await expect(page.getByRole('heading', { name: 'Service eligibility' })).toBeVisible();
    await page.selectOption('#service', 's1');
    await expect(page.getByTestId('assign-eligibility')).toBeVisible();
  });
});

test.describe('Front Office clients', () => {
  test('shows only masked contact and searches by query', async ({ page }) => {
    await stubMe(page, 'front_office', ['client.view', 'client.create', 'client.update', 'front_office.search']);
    await page.route('**/api/v1/clients**', (r) =>
      r.fulfill(ok({ data: [{ id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678', email_masked: 'a••@example.com', has_email: true, notes: null, status: 'active' }] })),
    );

    await page.goto('/front-office/clients');
    await expect(page.getByRole('heading', { name: 'Clients' })).toBeVisible();
    await expect(page.getByText('••• ••• 5678')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('254712345678');
    await expect(page.getByTestId('add-client')).toBeVisible();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });

  test('surfaces a same-branch duplicate phone as a conflict', async ({ page }) => {
    await stubMe(page, 'front_office', ['client.view', 'client.create']);
    await page.route('**/api/v1/clients', (r) => {
      if (r.request().method() === 'POST') {
        return r.fulfill({
          status: 409,
          contentType: 'application/json',
          body: JSON.stringify({ error: { code: 'duplicate_client', message: 'A client with this phone number already exists in this branch.', fields: {}, meta: { client_id: 'cl-existing' } } }),
        });
      }
      return r.fulfill(ok({ data: [] }));
    });

    await page.goto('/front-office/clients/create');
    await page.fill('#full_name', 'Dup');
    await page.fill('#phone', '0712345678');
    await page.getByRole('button', { name: 'Create client' }).click();

    await expect(page.getByTestId('duplicate-warning')).toBeVisible();
    await expect(page.getByTestId('duplicate-warning')).toContainText('already exists');
  });

  test('has no horizontal overflow at mobile width', async ({ page }) => {
    await stubMe(page, 'front_office', ['client.view']);
    await page.route('**/api/v1/clients**', (r) => r.fulfill(ok({ data: [] })));
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/front-office/clients');
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
  });
});
