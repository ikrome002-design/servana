import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 15B E2E — HR personnel availability + Branch Manager read-only schedule
 | (Plan §13.7, §80). The SPA preview has no live backend, so /me + /api/v1 are
 | stubbed to drive the REAL frontend: staff selection, the weekly editor (split
 | shifts, breaks), date exceptions, atomic save, emergency unavailability, and the
 | Branch Manager read-only surface. Genuine backend authorization/isolation/audit
 | is proven by tests/Feature/Scheduling/*. Linux CI is the authoritative browser
 | gate (local Windows Playwright is not claimed as a pass).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

function schedule(currentState = 'available', canUpdate = true) {
  return {
    staff: { id: 'p1', display_name: 'Jane Doe', employment_status: 'employed', is_active: true },
    timezone: 'Africa/Nairobi',
    current_state: currentState,
    recurring: [
      { weekday: 1, start_time: '09:00', end_time: '13:00', available: true },
      { weekday: 1, start_time: '14:00', end_time: '17:00', available: true },
      { weekday: 1, start_time: '15:00', end_time: '15:30', available: false },
    ],
    exceptions: [{ date: '2026-07-10', start_time: '09:00', end_time: '12:00', available: false }],
    eligible_services: [{ id: 's1', name: 'Haircut' }],
    can: { update: canUpdate },
  };
}

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill(
      ok({
        data: {
          user: { id: '01JUSER0000000000000000000', email: 'u@salon.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: { id: 'mm1', role, status: 'active' },
          memberships: [{ id: 'mm1', role, status: 'active' }],
          permissions,
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: { required: false, enrolled: false, confirmed: false, verified: false, enrollment_required: false, challenge_required: false, step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0 },
        },
      }),
    ),
  );
}

async function stubAvailability(page: Page, opts: { canUpdate?: boolean } = {}): Promise<void> {
  const canUpdate = opts.canUpdate ?? true;
  await page.route('**/api/v1/staff?**', (r) => r.fulfill(ok({ data: [{ id: 'p1', display_name: 'Jane Doe' }] })));
  await page.route('**/api/v1/staff', (r) => r.fulfill(ok({ data: [{ id: 'p1', display_name: 'Jane Doe' }] })));
  // Specific routes registered AFTER the broad ones take precedence in Playwright.
  await page.route('**/api/v1/staff/p1/availability/emergency-unavailable', (r) =>
    r.fulfill(ok({ data: schedule('unavailable', canUpdate) })),
  );
  await page.route('**/api/v1/staff/p1/availability', (r) => {
    if (r.request().method() === 'PUT') return r.fulfill(ok({ data: schedule('available', canUpdate) }));
    return r.fulfill(ok({ data: schedule('available', canUpdate) }));
  });
}

test.describe('HR personnel availability', () => {
  test('edits a weekly schedule with split shifts, a break, an exception, and saves', async ({ page }) => {
    await stubMe(page, 'hr', ['personnel.availability.manage', 'staff.view']);
    await stubAvailability(page);

    await page.goto('/hr/availability');
    await expect(page.getByRole('heading', { name: 'Availability' })).toBeVisible();
    await page.selectOption('#staff', 'p1');

    await expect(page.getByTestId('current-state')).toHaveText('Available');
    await expect(page.getByText('Haircut')).toBeVisible();
    // Split shift already present (two working intervals on Monday); add a third.
    await page.getByTestId('add-working-1').click();
    await page.getByTestId('add-break-1').click();
    await page.getByTestId('add-exception').click();
    await expect(page.getByTestId('unsaved-indicator')).toBeVisible();

    await page.locator('#change-reason').fill('Weekly schedule update');
    await page.getByTestId('save-availability').click();
    await expect(page.getByTestId('unsaved-indicator')).toBeHidden();

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });

  test('reload shows the persisted schedule', async ({ page }) => {
    await stubMe(page, 'hr', ['personnel.availability.manage', 'staff.view']);
    await stubAvailability(page);
    await page.goto('/hr/availability');
    await page.selectOption('#staff', 'p1');
    await expect(page.getByTestId('working-1').first()).toBeVisible();

    await page.reload();
    await page.selectOption('#staff', 'p1');
    await expect(page.getByTestId('working-1').first()).toBeVisible();
  });

  test('emergency unavailability changes the derived state', async ({ page }) => {
    await stubMe(page, 'hr', ['personnel.availability.manage', 'staff.view']);
    await stubAvailability(page);
    await page.goto('/hr/availability');
    await page.selectOption('#staff', 'p1');
    await expect(page.getByTestId('current-state')).toHaveText('Available');

    await page.getByTestId('open-emergency').click();
    await page.locator('#em-date').fill('2026-07-13');
    await page.locator('#em-start').fill('14:00');
    await page.locator('#em-end').fill('17:00');
    await page.locator('#em-reason').fill('Family emergency');
    await page.getByTestId('submit-emergency').click();

    await expect(page.getByTestId('current-state')).toHaveText('Unavailable');
  });

  test('an unauthorized role cannot use the HR screen', async ({ page }) => {
    await stubMe(page, 'front_office', ['client.view']);
    await stubAvailability(page);
    await page.goto('/hr/availability');
    await expect(page.getByTestId('no-permission')).toBeVisible();
    await expect(page.getByTestId('save-availability')).toBeHidden();
  });

  for (const width of [360, 768, 1280]) {
    test(`is usable with no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await stubMe(page, 'hr', ['personnel.availability.manage', 'staff.view']);
      await stubAvailability(page);
      await page.goto('/hr/availability');
      await page.selectOption('#staff', 'p1');
      await expect(page.getByTestId('save-availability')).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  for (const theme of ['light', 'dark'] as const) {
    test(`is axe-clean in ${theme} mode`, async ({ page }) => {
      await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);
      await stubMe(page, 'hr', ['personnel.availability.manage', 'staff.view']);
      await stubAvailability(page);
      await page.goto('/hr/availability');
      await page.selectOption('#staff', 'p1');
      await expect(page.getByTestId('save-availability')).toBeVisible();

      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });
  }
});

test.describe('Branch Manager read-only personnel schedule', () => {
  test('shows availability but no edit controls', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.dashboard.view', 'service.view']);
    await stubAvailability(page, { canUpdate: false });

    await page.goto('/branch/personnel-schedule');
    await expect(page.getByRole('heading', { name: 'Personnel schedule' })).toBeVisible();
    await page.selectOption('#bm-staff', 'p1');

    await expect(page.getByTestId('bm-current-state')).toBeVisible();
    await expect(page.getByTestId('bm-today')).toBeVisible();
    await expect(page.getByText('Haircut')).toBeVisible();

    await expect(page.getByTestId('save-availability')).toHaveCount(0);
    await expect(page.getByTestId('open-emergency')).toHaveCount(0);
    await expect(page.getByTestId('add-working-1')).toHaveCount(0);

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});
