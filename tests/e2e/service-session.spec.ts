import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 16C E2E — Front Office service sessions (list, completion preview wording,
 | cancellation) and Personnel own-scope My sessions (Plan §25.2, §80). The SPA
 | preview has no live backend, so /me + /api/v1 are stubbed to drive the REAL
 | frontend. Genuine backend authorization / isolation / coupling / atomicity / audit
 | is proven by tests/Feature/ServiceSession/*. Linux CI is the authoritative browser
 | gate (local Windows Playwright is not claimed).
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}

const CLIENT = { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678' };
const SERVICE = { id: 'sv1', name: 'Haircut', duration_minutes: 30 };
const PERSONNEL = { id: 'st1', display_name: 'Bob Stylist' };

function completedSession() {
  return {
    id: 'ss1',
    status: 'completed',
    queue_entry_id: 'qe1',
    started_at: '2026-07-06T07:00:00+00:00',
    completed_at: '2026-07-06T07:30:00+00:00',
    cancelled_at: null,
    cancellation_reason: null,
    notes: null,
    preferred_personnel_honored: true,
    service: SERVICE,
    client: CLIENT,
    personnel: PERSONNEL,
    commission_preview: { preview_status: 'not_configured', reason: 'compensation_not_configured', earned: false, payable: false, amount_minor: null, currency: null },
    can: { view: true, complete: false, cancel: false, update_notes: false },
  };
}

async function stubMe(page: Page, role: string, permissions: string[]): Promise<void> {
  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(
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

test.describe('Front Office service sessions', () => {
  test('shows the non-payable completion preview wording', async ({ page }) => {
    await stubMe(page, 'front_office', ['service_session.view', 'service_session.cancel', 'service_session.complete']);
    await page.route('**/api/v1/service-sessions**', (r) => r.fulfill(ok({ data: [completedSession()] })));
    await page.goto('/front-office/sessions');

    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    await expect(page.getByTestId('commission-preview')).toContainText('Preview — not earned or payable');
    await expect(page.getByTestId('commission-preview')).toContainText('Commission is not configured yet.');
  });

  test('has no serious or critical accessibility violations (light + dark)', async ({ page }) => {
    await stubMe(page, 'front_office', ['service_session.view']);
    await page.route('**/api/v1/service-sessions**', (r) => r.fulfill(ok({ data: [completedSession()] })));
    await page.goto('/front-office/sessions');
    await expect(page.getByText('Service sessions')).toBeVisible();

    for (const theme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: theme });
      const results = await new AxeBuilder({ page }).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(serious, `${theme}: ${serious.map((v) => v.id).join(', ')}`).toEqual([]);
    }
  });

  test('is usable at a 360px mobile width with no horizontal overflow', async ({ page }) => {
    await stubMe(page, 'front_office', ['service_session.view']);
    await page.route('**/api/v1/service-sessions**', (r) => r.fulfill(ok({ data: [completedSession()] })));
    await page.setViewportSize({ width: 360, height: 720 });
    await page.goto('/front-office/sessions');

    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
  });
});

test.describe('Personnel My sessions (own scope)', () => {
  test('lists only own sessions, read-only, with no commission preview', async ({ page }) => {
    await stubMe(page, 'personnel', ['personnel.my_sessions.view']);
    await page.route('**/api/v1/personnel/me/sessions**', (r) =>
      r.fulfill(ok({ data: [{ id: 'ss1', status: 'in_progress', started_at: '2026-07-06T07:00:00+00:00', completed_at: null, cancelled_at: null, service: SERVICE, client: CLIENT }] })),
    );
    await page.goto('/personnel/sessions');

    await expect(page.getByText('My sessions')).toBeVisible();
    await expect(page.getByText('Amina Yusuf')).toBeVisible();
    await expect(page.getByTestId('session-status-badge')).toHaveText('In progress');
    // No commission preview, no mutation controls.
    await expect(page.getByText('Preview')).toHaveCount(0);
    await expect(page.locator('button')).toHaveCount(0);
  });
});
