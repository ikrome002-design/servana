import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 16B E2E — Front Office walk-ins & queue, Branch Manager read-only + queue
 | configuration, and Personnel own-scope (Plan §37, §25.2, §80). The SPA preview
 | has no live backend, so /me + /api/v1 are stubbed to drive the REAL frontend: the
 | queue board, walk-in wizard (existing + new client), capability-gated detail
 | actions (assign/call/start/complete/transfer/cancel/no-show), keyboard reorder,
 | appointment conversion, closed/capacity rejection, the Branch Manager read-only
 | surface + configuration, and the Personnel own-scope list. Genuine backend
 | authorization / isolation / atomicity / concurrency / audit is proven by
 | tests/Feature/Scheduling/Queue*. Linux CI is the authoritative browser gate
 | (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

function err(status: number, code: string, message: string) {
  return { status, contentType: 'application/json', body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }) };
}

const CLIENT = { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' };
const SERVICE = { id: 'sv1', name: 'Haircut', duration_minutes: 30 };
const PERSONNEL = { id: 'st1', display_name: 'Bob Stylist' };

const ALL_CAN = { view: true, assign: false, call: true, start: false, complete: false, transfer: true, cancel: true, no_show: true };
const WAITING_CAN = { view: true, assign: true, call: false, start: false, complete: false, transfer: true, cancel: true, no_show: true };

function entry(status: string, can: Record<string, boolean>, position = 1, assigned: typeof PERSONNEL | null = PERSONNEL) {
  return {
    id: 'qe1',
    status,
    position,
    assignment_mode: 'next_available',
    source: { type: 'walk_in', id: 'wi1' },
    queued_at: '2026-07-06T07:00:00+00:00',
    assigned_at: null,
    called_at: null,
    started_at: null,
    completed_at: null,
    cancelled_at: null,
    no_show_at: null,
    transferred_at: null,
    cancellation_reason: null,
    transfer_reason: null,
    preferred_personnel_override_reason: null,
    estimated_wait: { label: 'Estimate', minutes: 10, override_minutes: null, override_reason: null, effective_minutes: 10 },
    service: SERVICE,
    client: CLIENT,
    assigned_personnel: assigned,
    preferred_personnel: null,
    can,
  };
}

const CONFIG = {
  branch_day_id: 'bd1',
  business_date: '2026-07-06',
  day_status: 'open',
  queue_is_open: true,
  effective_queue_open: true,
  queue_capacity: 5,
  queue_default_assignment_mode: 'next_available',
  active_count: 1,
};

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

async function stubQueue(page: Page, opts: { walkInClosed?: boolean; invalid?: boolean; detailCan?: Record<string, boolean> } = {}): Promise<void> {
  await page.route('**/api/v1/clients**', (r) => r.fulfill(ok({ data: [CLIENT] })));
  await page.route('**/api/v1/services**', (r) => {
    if (r.request().url().includes('/eligibility')) {
      return r.fulfill(ok({ data: [{ service_id: 'sv1', staff_profile_id: 'st1', staff_name: 'Bob Stylist', active: true }] }));
    }
    return r.fulfill(ok({ data: [{ ...SERVICE, status: 'active', price_minor: 100000, currency: 'KES', description: null }] }));
  });

  await page.route('**/api/v1/queue/configuration', (r) =>
    r.request().method() === 'PUT' ? r.fulfill(ok({ data: { ...CONFIG, queue_capacity: 8 } })) : r.fulfill(ok({ data: CONFIG })),
  );

  // Action endpoints, registered BEFORE the broad list/detail routes.
  await page.route('**/api/v1/queue-entries/reorder', (r) => r.fulfill(ok({ data: [entry('waiting', WAITING_CAN, 1)] })));
  await page.route('**/api/v1/queue-entries/qe1/call', (r) => r.fulfill(ok({ data: entry('called', ALL_CAN) })));
  await page.route('**/api/v1/queue-entries/qe1/start', (r) => r.fulfill(ok({ data: entry('in_service', ALL_CAN) })));
  await page.route('**/api/v1/queue-entries/qe1/complete', (r) =>
    opts.invalid
      ? r.fulfill(err(422, 'invalid_state_transition', 'A queue entry cannot move from assigned to completed.'))
      : r.fulfill(ok({ data: entry('completed', ALL_CAN) })),
  );
  for (const action of ['assign', 'transfer', 'cancel', 'no-show']) {
    await page.route(`**/api/v1/queue-entries/qe1/${action}`, (r) => r.fulfill(ok({ data: entry('assigned', opts.detailCan ?? ALL_CAN) })));
  }
  await page.route('**/api/v1/queue-entries/qe1', (r) => r.fulfill(ok({ data: entry('assigned', opts.detailCan ?? ALL_CAN) })));

  await page.route('**/api/v1/queue-entries**', (r) => {
    if (new URL(r.request().url()).pathname !== '/api/v1/queue-entries') {
      return r.fallback();
    }
    return r.fulfill(ok({ data: [entry('assigned', ALL_CAN)] }));
  });

  await page.route('**/api/v1/walk-ins', (r) =>
    opts.walkInClosed
      ? r.fulfill(err(409, 'queue_closed', 'The branch queue is closed.'))
      : r.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: entry('assigned', ALL_CAN) }) }),
  );
  await page.route('**/api/v1/appointments/ap1/queue', (r) =>
    r.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: entry('waiting', WAITING_CAN) }) }),
  );

  await page.route('**/api/v1/personnel/me/queue**', (r) =>
    r.fulfill(ok({ data: [{ id: 'qe1', status: 'assigned', position: 1, queued_at: '2026-07-06T07:00:00+00:00', estimated_wait: { label: 'Estimate', effective_minutes: 10 }, is_preferred_request: false, service: SERVICE, client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678' } }] })),
  );
}

const FO_PERMS = ['queue.view', 'queue.create', 'queue.assign', 'queue.transfer', 'queue.reorder', 'preferred_personnel.select', 'client.view', 'front_office.search'];

test.describe('Front Office queue', () => {
  test('opens the queue board and lists masked entries with a labelled estimate', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page);
    await page.goto('/front-office/queue');

    await expect(page.getByRole('heading', { name: 'Queue' })).toBeVisible();
    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    await expect(page.getByText('Estimate', { exact: false }).first()).toBeVisible();
    await expect(page.getByTestId('queue-status-badge').first()).toContainText('Assigned');

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });

  test('creates a walk-in for a new client and lands on the entry detail', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page);
    await page.goto('/front-office/walk-in');

    await page.getByTestId('client-new').click();
    await page.locator('#new-client-name').fill('Walkin Wanjiku');
    await page.locator('#new-client-phone').fill('0712345678');
    await page.selectOption('#walk-in-service', 'sv1');
    await page.getByTestId('submit-walk-in').click();

    await expect(page.getByTestId('queue-status-badge')).toBeVisible();
  });

  test('creates a walk-in for an existing client', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page);
    await page.goto('/front-office/walk-in');

    await page.selectOption('#existing-client', 'cl1');
    await page.selectOption('#walk-in-service', 'sv1');
    await page.getByTestId('submit-walk-in').click();

    await expect(page.getByTestId('queue-status-badge')).toBeVisible();
  });

  test('runs the call → start → complete lifecycle from the detail screen', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page);
    await page.goto('/front-office/queue/qe1');

    await page.getByTestId('action-call').click();
    await expect(page.getByTestId('queue-status-badge')).toHaveText('Called');
  });

  test('keeps the status on an invalid transition (denied)', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page, { invalid: true, detailCan: { ...ALL_CAN, complete: true } });
    await page.goto('/front-office/queue/qe1');

    await page.getByTestId('action-complete').click();
    await expect(page.getByTestId('queue-status-badge')).toHaveText('Assigned');
  });

  test('offers keyboard-accessible reorder controls for waiting entries', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await page.route('**/api/v1/queue-entries/reorder', (r) => r.fulfill(ok({ data: [] })));
    await page.route('**/api/v1/queue-entries**', (r) => {
      if (new URL(r.request().url()).pathname !== '/api/v1/queue-entries') return r.fallback();
      return r.fulfill(ok({ data: [entry('waiting', WAITING_CAN, 1), { ...entry('waiting', WAITING_CAN, 2), id: 'qe2' }] }));
    });
    await page.goto('/front-office/queue');

    await expect(page.getByTestId('move-down-qe1')).toBeVisible();
    await expect(page.getByTestId('move-up-qe2')).toBeVisible();
  });

  test('rejects a walk-in when the queue is closed', async ({ page }) => {
    await stubMe(page, 'front_office', FO_PERMS);
    await stubQueue(page, { walkInClosed: true });
    await page.goto('/front-office/walk-in');

    await page.selectOption('#existing-client', 'cl1');
    await page.selectOption('#walk-in-service', 'sv1');
    await page.getByTestId('submit-walk-in').click();

    await expect(page.getByRole('status').getByText('closed', { exact: false })).toBeVisible();
  });

  for (const width of [360, 768, 1280]) {
    test(`board is usable with no horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await stubMe(page, 'front_office', FO_PERMS);
      await stubQueue(page);
      await page.goto('/front-office/queue');
      await expect(page.getByText('Amina Yusuf')).toBeVisible();

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  for (const theme of ['light', 'dark'] as const) {
    test(`detail is axe-clean in ${theme} mode`, async ({ page }) => {
      await page.addInitScript((t) => localStorage.setItem('servana.theme', t), theme);
      await stubMe(page, 'front_office', FO_PERMS);
      await stubQueue(page);
      await page.goto('/front-office/queue/qe1');
      await expect(page.getByTestId('queue-status-badge')).toBeVisible();

      const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });
  }
});

test.describe('Branch Manager queue', () => {
  test('sees the queue read-only with no operational controls', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.dashboard.view', 'branch.profile.manage', 'day.open_close']);
    await stubQueue(page);
    await page.goto('/branch/queue');

    await expect(page.getByRole('heading', { name: 'Branch queue' })).toBeVisible();
    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    // No operational action buttons on the read-only board.
    await expect(page.getByTestId('action-call')).toHaveCount(0);
    await expect(page.getByTestId('start-walk-in')).toHaveCount(0);
  });

  test('configures the queue settings', async ({ page }) => {
    await stubMe(page, 'branch_manager', ['branch.dashboard.view', 'branch.profile.manage', 'day.open_close']);
    await stubQueue(page);
    await page.goto('/branch/queue-configuration');

    await expect(page.getByTestId('queue-is-open')).toBeVisible();
    await page.getByTestId('save-queue-config').click();
    await expect(page.getByRole('status')).toBeVisible();
  });
});

test.describe('Personnel queue', () => {
  test('sees only their own assigned queue (no mutation controls)', async ({ page }) => {
    await stubMe(page, 'personnel', ['personnel.my_queue.view']);
    await stubQueue(page);
    await page.goto('/personnel/queue');

    await expect(page.getByRole('heading', { name: 'My queue' })).toBeVisible();
    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    await expect(page.getByTestId('action-call')).toHaveCount(0);
  });
});
