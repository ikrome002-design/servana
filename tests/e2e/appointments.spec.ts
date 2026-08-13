import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 16A E2E — Front Office appointments, Branch Manager read-only visibility,
 | and Personnel own-scope (Plan §36, §25.2, §80). The SPA preview has no live
 | backend, so /me + /api/v1 are stubbed to drive the REAL frontend: the
 | appointment list, create flow (incl. conflict feedback), capability-gated detail
 | actions (assign/transfer/reschedule/check-in/cancel/no-show), the invalid-
 | transition denial, the Branch Manager read-only surface (no mutation controls),
 | and the Personnel own-scope list. Genuine backend authorization / isolation /
 | conflict-prevention / audit is proven by tests/Feature/Scheduling/*. Linux CI is
 | the authoritative browser gate (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

function err(status: number, code: string, message: string) {
  return { status, contentType: 'application/json', body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }) };
}

const CLIENT = { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' };
const SERVICE = { id: 'sv1', name: 'Haircut', duration_minutes: 60 };
const PERSONNEL = { id: 'st1', display_name: 'Bob Stylist' };

function appointment(status: string, can: Record<string, boolean>, assigned: typeof PERSONNEL | null = null) {
  return {
    id: 'ap1',
    status,
    starts_at: '2026-07-06T07:00:00+00:00',
    ends_at: '2026-07-06T08:00:00+00:00',
    checked_in_at: null,
    cancelled_at: null,
    no_show_at: null,
    cancellation_reason: null,
    service: SERVICE,
    client: CLIENT,
    preferred_personnel: null,
    assigned_personnel: assigned,
    can,
  };
}

const ALL_CAN = { view: true, assign: true, transfer: true, reschedule: true, check_in: true, cancel: true, mark_no_show: true };
const READONLY_CAN = { view: true, assign: false, transfer: false, reschedule: false, check_in: false, cancel: false, mark_no_show: false };

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, role, false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(
      ok({
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
    ),
  );
}

async function stubAppointments(page: Page, opts: { listStatus?: string; detailCan?: Record<string, boolean>; createConflict?: boolean; checkInInvalid?: boolean } = {}): Promise<void> {
  await page.route('**/api/v1/clients**', (r) => r.fulfill(ok({ data: [CLIENT] })));
  await page.route('**/api/v1/services**', (r) => {
    if (r.request().url().includes('/eligibility')) {
      return r.fulfill(ok({ data: [{ service_id: 'sv1', staff_profile_id: 'st1', staff_name: 'Bob Stylist', active: true }] }));
    }
    return r.fulfill(ok({ data: [{ ...SERVICE, status: 'active', price_minor: 100000, currency: 'KES', description: null }] }));
  });

  const detail = () => appointment('confirmed', opts.detailCan ?? ALL_CAN, PERSONNEL);

  // Action endpoints (registered before the broad list/detail routes).
  await page.route('**/api/v1/appointments/ap1/check-in', (r) =>
    opts.checkInInvalid
      ? r.fulfill(err(422, 'invalid_state_transition', 'An appointment cannot move from scheduled to checked_in.'))
      : r.fulfill(ok({ data: appointment('checked_in', ALL_CAN, PERSONNEL) })),
  );
  for (const action of ['assign', 'transfer', 'reschedule', 'cancel', 'no-show']) {
    await page.route(`**/api/v1/appointments/ap1/${action}`, (r) => r.fulfill(ok({ data: detail() })));
  }
  await page.route('**/api/v1/appointments/ap1', (r) => r.fulfill(ok({ data: detail() })));

  await page.route('**/api/v1/appointments**', (r) => {
    if (new URL(r.request().url()).pathname !== '/api/v1/appointments') {
      return r.fallback();
    }

    if (r.request().method() === 'POST') {
      return opts.createConflict
        ? r.fulfill(err(409, 'appointment_schedule_conflict', 'This personnel member already has an appointment during the requested time.'))
        : r.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: detail() }) });
    }
    return r.fulfill(ok({ data: [appointment(opts.listStatus ?? 'confirmed', ALL_CAN, PERSONNEL)] }));
  });

  await page.route('**/api/v1/personnel/me/appointments**', (r) =>
    r.fulfill(ok({ data: [{ id: 'ap1', status: 'confirmed', starts_at: '2026-07-06T07:00:00+00:00', ends_at: '2026-07-06T08:00:00+00:00', service: SERVICE, client: CLIENT }] })),
  );
}

const FO_PERMS = ['appointment.view', 'appointment.create', 'appointment.assign', 'appointment.transfer', 'appointment.reschedule', 'appointment.cancel', 'appointment.check_in', 'front_office.search'];

test.describe('Front Office appointments', () => {
  test('opens the appointments screen and lists masked appointments', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubAppointments(page);
    await page.goto('/front-office/appointments');

    // This is the first lazy route in the whole-product run. On a cold Windows
    // production preview its route chunk can mount after Playwright's 5-second
    // expect default, while the app still exposes only the empty toast status
    // landmark. Keep the semantic readiness assertion, but give the cold chunk
    // the same bounded startup tolerance already afforded to the preview build.
    await expect(page.getByRole('heading', { name: 'Appointments' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    await expect(page.getByTestId('status-badge').first()).toHaveText('Confirmed');

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });

  test('books an appointment and lands on the detail screen', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubAppointments(page);
    await page.goto('/front-office/appointments/create');

    await page.selectOption('#client', 'cl1');
    await page.selectOption('#service', 'sv1');
    await page.locator('#starts_at').fill('2026-07-06T10:00');
    await expect(page.getByTestId('duration-preview')).toBeVisible();
    await page.getByRole('button', { name: 'Book appointment' }).click();

    await expect(page.getByTestId('status-badge')).toBeVisible();
  });

  test('surfaces a scheduling conflict on overlapping booking', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubAppointments(page, { createConflict: true });
    await page.goto('/front-office/appointments/create');

    await page.selectOption('#client', 'cl1');
    await page.selectOption('#service', 'sv1');
    await page.locator('#starts_at').fill('2026-07-06T10:00');
    await page.getByRole('button', { name: 'Book appointment' }).click();

    await expect(page.getByTestId('form-error')).toContainText('already has an appointment');
  });

  test('checks a client in from the detail screen', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubAppointments(page);
    await page.goto('/front-office/appointments/ap1');

    await page.getByTestId('action-check-in').click();
    await expect(page.getByTestId('status-badge')).toHaveText('Checked in');
  });

  test('keeps the status on an invalid transition (denied)', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubAppointments(page, { checkInInvalid: true });
    await page.goto('/front-office/appointments/ap1');

    await page.getByTestId('action-check-in').click();
    // The denied transition leaves the appointment unchanged.
    await expect(page.getByTestId('status-badge')).toHaveText('Confirmed');
  });

  test('an unauthorized role cannot use the Front Office appointment screen', async ({ page }) => {
    await stubMe(page, 'hr', ['staff.view']);
    await stubAppointments(page);
    await page.goto('/front-office/appointments');
    // The Front Office layout is not available to HR — the create button never shows.
    await expect(page.getByTestId('add-appointment')).toHaveCount(0);
  });

  for (const width of [360, 768, 1280]) {
    test(`is usable with no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await stubMe(page, 'front_office', FO_PERMS);
      await stubAppointments(page);
      await page.goto('/front-office/appointments');
      await expect(page.getByText('Amina Yusuf')).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  for (const theme of ['light', 'dark'] as const) {
    test(`is axe-clean in ${theme} mode`, async ({ page }) => {
      await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);
      await stubMe(page, 'front_office', FO_PERMS);
      await stubAppointments(page);
      await page.goto('/front-office/appointments/ap1');
      await expect(page.getByTestId('status-badge')).toBeVisible();

      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });
  }
});

test.describe('Branch Manager read-only appointments', () => {
  test('shows appointments with no mutation controls', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.dashboard.view', 'service.view']);
    await stubAppointments(page, { detailCan: READONLY_CAN });
    await page.goto('/branch/appointments');

    await expect(page.getByRole('heading', { name: 'Appointments' })).toBeVisible();
    await expect(page.getByText('Amina Yusuf')).toBeVisible();

    await expect(page.getByTestId('add-appointment')).toHaveCount(0);
    await expect(page.getByTestId('action-assign')).toHaveCount(0);
    await expect(page.getByTestId('action-cancel')).toHaveCount(0);

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

test.describe('Personnel own appointments', () => {
  test('shows only the personnel member’s own appointments, read-only', async ({ page }) => {
    await stubMe(page, 'personnel', ['personnel.my_appointments.view']);
    await stubAppointments(page);
    await page.goto('/personnel/appointments');

    await expect(page.getByRole('heading', { name: 'My appointments' })).toBeVisible();
    await expect(page.getByText('Haircut')).toBeVisible();
    await expect(page.getByTestId('action-assign')).toHaveCount(0);
    await expect(page.getByTestId('add-appointment')).toHaveCount(0);
  });
});
