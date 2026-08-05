import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 20G Increment 6A E2E — the HR commission-rule SELECTED-SERVICES multi-select (Plan §61 §9.1).
 | The options come from the NARROW compensation-scoped endpoint `/commission-rule-service-options`
 | (authorized by compensation.plan.view; HR has no service.view) — the general `/services` catalogue is
 | never called from this flow. The SPA preview has no backend; `/me` + `/api/v1` are stubbed to drive the
 | REAL frontend. Genuine authorization, branch scope, membership persistence and immutability are proven
 | by the backend Feature suite (tests/Feature/Compensation/CommissionRuleSelectedServices*Test); these
 | prove frontend behaviour, option loading, validation, hydration, accessibility.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}

const HR_KEYS = [
  'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
  'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
  'compensation.plan.cancel', 'compensation.history.view', 'staff.view',
];

const SVC_A = '01SVCAAA000000000000000001';
const SVC_B = '01SVCBBB000000000000000002';
const serviceOptions = [{ ulid: SVC_A, name: 'Alpha cut' }, { ulid: SVC_B, name: 'Beta colour' }];

async function stubMe(page: Page, permissions: string[] = HR_KEYS): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, 'hr', false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'hr@citrus.co.ke', name: 'Ada HR', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: { id: 'mm1', role: 'hr', status: 'active' },
        memberships: [{ id: 'mm1', role: 'hr', status: 'active' }],
        account_keys: [accountKeyForRole('hr', false)],
        permissions,
        setup: { required: false, current_step: null, completed_at: null },
        branch_ids: ['b1'],
        mfa: { required: false, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: true, step_up_fresh_until: null, recovery_codes_remaining: 5 },
      },
    })),
  );
}

function selectedRule(overrides: Record<string, unknown> = {}) {
  return {
    id: '01RULESEL0000000000000001', status: 'draft', status_label: 'Draft', calculation_type: 'percentage',
    calculation_basis: 'service_price', applies_to: 'selected_services',
    selected_service_ulids: [SVC_A], selected_services: [{ ulid: SVC_A, name: 'Alpha cut' }],
    percentage_basis_points: 1500, fixed_amount_minor: null, currency: null,
    applies_to_preferred_personnel_fee: false, effective_from: '2026-08-01', effective_to: null,
    notes: null, change_reason: 'Launch', is_editable: true, created_at: '2026-07-01T00:00:00+00:00', approved_at: null, ...overrides,
  };
}

let servicesCalled = false;
let optionsCalled = false;
let lastRulePost: Record<string, unknown> | null = null;

async function stubHr(page: Page, rules: unknown[] = []): Promise<void> {
  servicesCalled = false; optionsCalled = false; lastRulePost = null;
  await page.route(/\/api\/v1\/staff(\?|$)/, (r) => r.fulfill(ok({ data: [{ id: '01STAFF0000000000000000001', display_name: 'Jane Doe', status: 'active' }] })));
  // The narrow compensation-scoped option source.
  await page.route(/\/api\/v1\/commission-rule-service-options(\?|$)/, (r) => { optionsCalled = true; return r.fulfill(ok({ data: serviceOptions })); });
  // The general catalogue must NEVER be called from this flow.
  await page.route(/\/api\/v1\/services(\?|$)/, (r) => { servicesCalled = true; return r.fulfill(ok({ data: [] })); });
  await page.route(/\/api\/v1\/commission-rules\/[^/]+\/draft$/, (r) => r.fulfill(ok({ data: selectedRule() })));
  await page.route(/\/api\/v1\/commission-rules(\?|$)/, (r) => {
    if (r.request().method() === 'POST') { lastRulePost = r.request().postDataJSON(); return r.fulfill(created({ data: selectedRule() })); }
    return r.fulfill(ok({ data: rules }));
  });
  await page.route(/\/api\/v1\/compensation-plans(\?|$)/, (r) => r.fulfill(ok({ data: [] })));
}

async function gotoHr(page: Page, rules: unknown[] = []): Promise<void> {
  await stubMe(page);
  await stubHr(page, rules);
  await page.goto('/hr/compensation');
  await expect(page.getByRole('heading', { name: 'Compensation', level: 1 })).toBeVisible();
}

async function openSelectedServicesForm(page: Page): Promise<void> {
  await page.getByTestId('open-rule-create').click();
  await page.locator('#comp-rule-applies-to').selectOption('selected_services');
  await expect(page.getByTestId('selected-services')).toBeVisible();
}

test.describe('HR selected-services multi-select', () => {
  test('loads branch options from the compensation endpoint, never /services', async ({ page }) => {
    await gotoHr(page);
    await openSelectedServicesForm(page);
    await expect(page.locator(`#svc-${SVC_A}`)).toBeVisible();
    await expect(page.locator(`#svc-${SVC_B}`)).toBeVisible();
    expect(optionsCalled).toBe(true);
    expect(servicesCalled).toBe(false);
  });

  test('requires at least one service and then saves the selection', async ({ page }) => {
    await gotoHr(page);
    await openSelectedServicesForm(page);
    await page.locator('#comp-rule-bp').fill('1500');
    await page.locator('#comp-rule-from').fill('2026-09-01');
    await page.locator('#comp-rule-reason').fill('Selected rule');
    // No service selected → client validation blocks submit.
    await page.getByTestId('save-rule').click();
    await expect(page.getByTestId('selected-services-error')).toBeVisible();
    // Select a service → save succeeds with selected_service_ulids.
    await page.locator(`#svc-${SVC_A}`).check();
    await page.getByTestId('save-rule').click();
    await expect.poll(() => lastRulePost?.applies_to).toBe('selected_services');
    expect(lastRulePost?.selected_service_ulids).toEqual([SVC_A]);
  });

  test('clears the stale selection when applies_to changes away', async ({ page }) => {
    await gotoHr(page);
    await openSelectedServicesForm(page);
    await page.locator(`#svc-${SVC_A}`).check();
    await page.locator('#comp-rule-applies-to').selectOption('all_services');
    await expect(page.getByTestId('selected-services')).toBeHidden();
    await page.locator('#comp-rule-bp').fill('1500');
    await page.locator('#comp-rule-from').fill('2026-09-01');
    await page.locator('#comp-rule-reason').fill('Now all');
    await page.getByTestId('save-rule').click();
    await expect.poll(() => JSON.stringify(lastRulePost?.selected_service_ulids)).toBe('[]');
  });

  test('hydrates the server-returned selection when editing a draft and shows a read-only line', async ({ page }) => {
    await gotoHr(page, [selectedRule()]);
    // Read-only membership line in the list.
    await expect(page.getByTestId('rule-selected-services-01RULESEL0000000000000001')).toContainText('Alpha cut');
    // Editing hydrates the checkbox from the server selection.
    await page.getByTestId('edit-rule-01RULESEL0000000000000001').click();
    await expect(page.locator(`#svc-${SVC_A}`)).toBeChecked();
  });

  test.describe('responsive, zoom, keyboard, a11y', () => {
    for (const width of [360, 768, 1280]) {
      test(`no horizontal overflow at ${width}px with the multi-select open`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await gotoHr(page);
        await openSelectedServicesForm(page);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        expect(overflow).toBe(false);
      });
    }

    test('remains usable at 200% zoom', async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await gotoHr(page);
      await openSelectedServicesForm(page);
      await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
      await expect(page.locator(`#svc-${SVC_A}`)).toBeVisible();
    });

    test('the multi-select is keyboard operable', async ({ page }) => {
      await gotoHr(page);
      await openSelectedServicesForm(page);
      await page.locator(`#svc-${SVC_A}`).focus();
      await page.keyboard.press('Space');
      await expect(page.locator(`#svc-${SVC_A}`)).toBeChecked();
    });

    for (const scheme of ['light', 'dark'] as const) {
      test(`selected-services form passes axe (${scheme})`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await gotoHr(page);
        await openSelectedServicesForm(page);
        await page.locator(`#svc-${SVC_A}`).check();
        const results = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
        const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
        expect(serious).toEqual([]);
      });
    }
  });
});
