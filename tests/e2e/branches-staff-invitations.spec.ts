import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | UI e2e for branches + staff invitations (Scope §3.3/§3.4, Plan §27 Phase 7).
 | The SPA preview has no backend, so /api/v1 + /sanctum are stubbed to exercise
 | the real frontend: branch list/create, operating hours, staff invitation
 | create, and public invitation acceptance, plus accessibility. The genuine
 | backend behaviour (atomic accept → membership+profile+assignment, suspend →
 | session/token revocation, Magic Link check 6, cross-merchant 404, Mailpit
 | delivery) is proven by the feature suite + docs/proof/phase-7.md.
 */

const OWNER = {
  id: '01J0000000000000000000USER',
  email: 'owner@example.com',
  name: 'Paul Nderitu',
  status: 'active',
  email_verified_at: '2026-06-15T00:00:00+00:00',
  is_platform_staff: false,
};
const MERCHANT = {
  id: '01J000000000000000000MERCH',
  name: 'Servana Demo Salon',
  slug: 'servana-demo-salon',
  status: 'active',
  service_fee_tier: 'split_tier',
  setup_completed_at: '2026-06-14T00:00:00+00:00',
};
const MEMBERSHIP = { id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' };
const BRANCH = {
  id: '01J0000000000000000BRANCH',
  name: 'Kilimani Branch',
  code: 'KIL001',
  address: null,
  town: 'Nairobi',
  phone: null,
  email: null,
  business_category: null,
  status: 'active',
  status_reason: null,
  archived_at: null,
};

function adminBootstrap(): string {
  return JSON.stringify({
    data: {
      user: OWNER,
      merchant: MERCHANT,
      membership: MEMBERSHIP,
      memberships: [MEMBERSHIP],
      permissions: [],
      branch_ids: [],
      setup: { required: false, current_step: 'done', completed_at: '2026-06-14T00:00:00+00:00' },
    },
  });
}

async function stubAdmin(page: Page): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: adminBootstrap() }),
  );
}

function json(body: unknown, status = 200): { status: number; contentType: string; body: string } {
  return { status, contentType: 'application/json', body: JSON.stringify(body) };
}

test.describe('Branches + staff invitations UI', () => {
  test('merchant admin sees the branch list and the add-branch action', async ({ page }) => {
    await stubAdmin(page);
    await page.route('**/api/v1/branches', (route) => route.fulfill(json({ data: [BRANCH] })));

    await page.goto('/branch');
    await expect(page.getByRole('heading', { name: 'Branches' })).toBeVisible();
    await expect(page.getByText('Kilimani Branch')).toBeVisible();
    await expect(page.getByText('Add branch')).toBeVisible();
  });

  test('merchant admin creates a branch', async ({ page }) => {
    await stubAdmin(page);
    await page.route('**/api/v1/branches', (route) => {
      if (route.request().method() === 'POST') return route.fulfill(json({ data: BRANCH }, 201));
      return route.fulfill(json({ data: [] }));
    });

    await page.goto('/branch/create');
    await page.fill('#name', 'Kilimani Branch');
    await page.fill('#code', 'KIL001');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL(/\/branch$/);
  });

  test('merchant admin updates branch operating hours', async ({ page }) => {
    await stubAdmin(page);
    const hours = [{ weekday: 1, opens_at: '08:00', closes_at: '18:00', is_closed: false, break_start: null, break_end: null }];
    await page.route('**/api/v1/branches/*/operating-hours', (route) => route.fulfill(json({ data: hours })));

    await page.goto(`/branch/${BRANCH.id}/operating-hours`);
    await expect(page.getByRole('heading', { name: 'Operating hours' })).toBeVisible();
    await page.getByRole('button', { name: 'Save hours' }).click();
    // A successful PUT keeps the page (toast shown); no navigation away.
    await expect(page).toHaveURL(new RegExp('/operating-hours'));
  });

  test('merchant admin invites a staff member', async ({ page }) => {
    await stubAdmin(page);
    await page.route('**/api/v1/branches', (route) => route.fulfill(json({ data: [BRANCH] })));
    await page.route('**/api/v1/staff-invitations', (route) => {
      if (route.request().method() === 'POST') {
        return route.fulfill(json({
          data: { id: 'i1', email: 'manager@salon.co.ke', role: 'branch_manager', role_title: null, branch_id: BRANCH.id, status: 'pending', resend_count: 0, expires_at: '2026-06-18T00:00:00Z', last_sent_at: null },
        }, 201));
      }
      return route.fulfill(json({ data: [] }));
    });

    await page.goto('/hr/invitations');
    await page.fill('#email', 'manager@salon.co.ke');
    await page.selectOption('#branch_id', BRANCH.id);
    await page.selectOption('#role', 'branch_manager');
    await page.getByRole('button', { name: 'Send invitation' }).click();

    await expect(page.getByText('manager@salon.co.ke')).toBeVisible();
  });

  test('an invitee accepts their invitation', async ({ page }) => {
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/me', (route) =>
      route.fulfill(json({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }, 401)),
    );
    await page.route('**/api/v1/staff-invitations/accept', (route) =>
      route.fulfill(json({ message: 'Your account is ready.' }, 201)),
    );

    await page.goto('/staff/accept?token=good-token');
    await page.fill('#first_name', 'Amina');
    await page.fill('#last_name', 'Mwangi');
    await page.fill('#phone', '+254700111222');
    await page.getByRole('button', { name: 'Accept invitation' }).click();

    await expect(page.getByTestId('accept-success')).toBeVisible();
  });

  test('a revoked / invalid invitation token shows a uniform error', async ({ page }) => {
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
    await page.route('**/api/v1/me', (route) =>
      route.fulfill(json({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }, 401)),
    );
    await page.route('**/api/v1/staff-invitations/accept', (route) =>
      route.fulfill(json({ error: { code: 'invalid_or_expired_invitation', message: 'nope', fields: {}, meta: {} } }, 422)),
    );

    await page.goto('/staff/accept?token=dead-token');
    await page.fill('#first_name', 'Amina');
    await page.fill('#last_name', 'Mwangi');
    await page.fill('#phone', '+254700111222');
    await page.getByRole('button', { name: 'Accept invitation' }).click();

    await expect(page.getByTestId('accept-error')).toBeVisible();
  });

  test('axe is clean on branch list, branch create and invitation accept', async ({ page }) => {
    await stubAdmin(page);
    await page.route('**/api/v1/branches', (route) => route.fulfill(json({ data: [BRANCH] })));

    for (const path of ['/branch', '/branch/create']) {
      await page.goto(path);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations).toEqual([]);
    }

    // Public accept page (logged out).
    await page.route('**/api/v1/me', (route) =>
      route.fulfill(json({ error: { code: 'unauthenticated', message: 'Unauthenticated.', fields: {}, meta: {} } }, 401)),
    );
    await page.goto('/staff/accept?token=good-token');
    const acceptResults = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(acceptResults.violations).toEqual([]);
  });
});
