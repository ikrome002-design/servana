import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Post-Phase-20F deferred-hardening E2E — the dark-mode heading/warning-badge contrast sweep.
 |
 | `--color-brand-deep` and `--color-warning` are deliberately NOT dark-overridden (style.css),
 | so `text-brand-deep` headings on adaptive surfaces and the `bg-warning/15 text-warning`
 | badge pair rendered at roughly 1.07-2.14:1. Phase 20F fixed its own surfaces and recorded
 | the rest as deferred; this branch fixes them repo-wide.
 |
 | The HR staff roster had NO e2e coverage at all, which is exactly how its `invited` and
 | `suspended` warning badges stayed invisible to every axe run. Every membership status is
 | rendered at once, in light and dark, so a failing colour pair cannot hide behind a fixture
 | that never renders it.
 |
 | The SPA preview has no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend
 | (repository-standard). Authority and branch scope are proven by the backend suite.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

async function stubMe(page: Page): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, 'hr', false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(
      ok({
        data: {
          user: {
            id: '01JUSER0000000000000000000',
            email: 'hr@citrus.co.ke',
            name: 'Ada',
            status: 'active',
            email_verified_at: '2026-06-14T00:00:00+00:00',
            is_platform_staff: false,
          },
          merchant: {
            id: 'm1',
            name: 'Glow Studio',
            slug: 'glow',
            status: 'active',
            service_fee_tier: null,
            setup_completed_at: '2026-01-01T00:00:00Z',
          },
          membership: { id: 'mm1', role: 'hr', status: 'active' },
          memberships: [{ id: 'mm1', role: 'hr', status: 'active' }],
          account_keys: [accountKeyForRole('hr', false)],
          permissions: ['staff.view', 'staff.manage'],
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: {
            required: false,
            enrolled: true,
            confirmed: true,
            verified: true,
            enrollment_required: false,
            challenge_required: false,
          },
        },
      }),
    ),
  );
}

function member(id: string, status: string, name: string) {
  return {
    id,
    first_name: name,
    last_name: 'Test',
    display_name: name,
    phone: '+254700000000',
    role: 'personnel',
    role_title: 'Stylist',
    status,
    employment_type: 'permanent',
    employment_status: 'employed',
    primary_branch_id: 'b1',
    is_active: status === 'active',
    can: { view: true, manage: true },
  };
}

// Every membership status the badge map can paint, rendered in one list.
const ALL_STATUSES = [
  member('01STAFF0000000000000000001', 'invited', 'Ivy Invited'),
  member('01STAFF0000000000000000002', 'active', 'Ana Active'),
  member('01STAFF0000000000000000003', 'suspended', 'Sam Suspended'),
  member('01STAFF0000000000000000004', 'deactivated', 'Dan Deactivated'),
];

async function gotoStaff(page: Page): Promise<void> {
  await stubMe(page);
  await page.route('**/api/v1/staff', (r) => r.fulfill(ok({ data: ALL_STATUSES })));
  await page.goto('/hr/staff');
  await expect(page.getByRole('heading', { name: 'Staff', level: 1 })).toBeVisible();
}

test.describe('HR staff roster — status badge contrast', () => {
  test('renders every membership status badge', async ({ page }) => {
    await gotoStaff(page);
    const badges = page.getByTestId('staff-status');
    await expect(badges).toHaveCount(4);
    await expect(badges.nth(0)).toHaveText('invited');
    await expect(badges.nth(1)).toHaveText('active');
    await expect(badges.nth(2)).toHaveText('suspended');
    await expect(badges.nth(3)).toHaveText('deactivated');
  });

  // Status is never conveyed by colour alone — the label text carries it.
  test('badge label text remains the status carrier', async ({ page }) => {
    await gotoStaff(page);
    await expect(page.getByTestId('staff-status').first()).toHaveText('invited');
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`passes axe with zero serious/critical violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoStaff(page);
      await expect(page.getByTestId('staff-status')).toHaveCount(4);
      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      const serious = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical',
      );
      expect(serious, JSON.stringify(serious.map((v) => v.id))).toEqual([]);
    });
  }
});
