import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 20F E2E — the HR Compensation frontend (Plan §59, §80, §27.1; Scope §12.1-§12.9, §18.3): the
 | branch-scoped, HR-only compensation-plan + commission-rule configuration screen. The SPA preview has
 | no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine authorization, branch
 | scope, fresh step-up, maker/checker (approver != submitter), the backdated business-date computation,
 | supersede-on-activation and the append-only history are proven by the backend Feature suite
 | (tests/Feature/Compensation/*); these prove frontend behaviour, role gating, canonical copy and
 | accessibility.
 |
 | Configuration only: no salary ledger, commission ledger, payout, earnings statement, liability or
 | Wallet/provider surface exists here or anywhere in Phase 20F.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}
function denied(status: number, code: string, message: string) {
  return {
    status,
    contentType: 'application/json',
    body: JSON.stringify({ error: { code, message, fields: {}, meta: {} } }),
  };
}

const HR_KEYS = [
  'compensation.plan.view',
  'compensation.plan.create',
  'compensation.plan.update_draft',
  'compensation.plan.submit',
  'compensation.plan.approve',
  'compensation.plan.reject',
  'compensation.plan.cancel',
  'compensation.history.view',
  'staff.view',
];

interface MeOpts {
  role?: string | null;
  permissions?: string[];
  isPlatformStaff?: boolean;
}

async function stubMe(page: Page, opts: MeOpts = {
}): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, opts.role, opts.isPlatformStaff ?? false);

  const isPlatformStaff = opts.isPlatformStaff ?? false;
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
            is_platform_staff: isPlatformStaff,
          },
          merchant: isPlatformStaff
            ? null
            : { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
          memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
          account_keys: [accountKeyForRole(opts.role, opts.isPlatformStaff ?? false)],
          permissions: opts.permissions ?? [],
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: ['b1'],
          mfa: {
            required: false,
            enrolled: true,
            confirmed: true,
            verified: true,
            enrollment_required: false,
            challenge_required: false,
            step_up_fresh: true,
            step_up_fresh_until: null,
            recovery_codes_remaining: 5,
          },
        },
      }),
    ),
  );
}

function rule(overrides: Record<string, unknown> = {}) {
  return {
    id: '01RULE00000000000000000001',
    status: 'draft',
    status_label: 'Draft',
    calculation_type: 'percentage',
    calculation_basis: 'service_price',
    applies_to: 'all_services',
    percentage_basis_points: 1500,
    fixed_amount_minor: null,
    currency: null,
    applies_to_preferred_personnel_fee: true,
    effective_from: '2026-08-01',
    effective_to: null,
    notes: null,
    change_reason: 'Launch',
    is_editable: true,
    created_at: '2026-07-01T00:00:00+00:00',
    approved_at: null,
    ...overrides,
  };
}

function plan(overrides: Record<string, unknown> = {}) {
  return {
    id: '01PLAN00000000000000000001',
    status: 'draft',
    status_label: 'Draft',
    staff_profile_id: '01STAFF0000000000000000001',
    staff_display_name: 'Jane Doe',
    branch_id: '01BRANCH000000000000000001',
    compensation_model: 'salary_plus_commission',
    compensation_model_label: 'Salary plus commission',
    salary_amount_minor: 5000000,
    salary_currency: 'KES',
    salary_period: 'monthly',
    salary_payout_day: 28,
    commission_rule: rule(),
    effective_from: '2026-08-01',
    effective_to: null,
    is_backdated: false,
    notes: null,
    change_reason: 'Promotion',
    submitted_at: null,
    approved_at: null,
    rejected_at: null,
    created_at: '2026-07-01T00:00:00+00:00',
    updated_at: '2026-07-01T00:00:00+00:00',
    capabilities: {
      can_update_draft: true,
      can_submit: true,
      can_approve: false,
      can_reject: false,
      can_cancel: true,
      is_terminal: false,
    },
    ...overrides,
  };
}

const pendingCaps = {
  can_update_draft: false,
  can_submit: false,
  can_approve: true,
  can_reject: true,
  can_cancel: false,
  is_terminal: false,
};

const history = [
  {
    id: '01HIST0000000000000000001',
    event: 'created',
    event_label: 'Created',
    from_status: null,
    to_status: 'draft',
    changed_fields: null,
    was_backdated: false,
    change_reason: 'Promotion',
    effective_from: '2026-08-01',
    actor_display_name: 'Ada HR',
    occurred_at: '2026-07-01T00:00:00+00:00',
  },
];

interface StubOpts {
  plans?: unknown[];
  approveResponse?: { status: number; code?: string; message?: string; body?: unknown };
}

async function stubCompensation(page: Page, opts: StubOpts = {}): Promise<void> {
  const plans = opts.plans ?? [plan()];
  await page.route(/\/api\/v1\/staff(\?|$)/, (r) =>
    r.fulfill(ok({ data: [{ id: '01STAFF0000000000000000001', display_name: 'Jane Doe', status: 'active' }] })),
  );
  await page.route(/\/api\/v1\/commission-rules(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: rule() }));
    return r.fulfill(ok({ data: [rule()] }));
  });
  // Phase 20G §9.1 — the HR rule form loads branch service options from this narrow compensation-scoped
  // endpoint when opened; stub it empty so the 20F flows (all_services) render without a pass-through call.
  await page.route(/\/api\/v1\/commission-rule-service-options(\?|$)/, (r) => r.fulfill(ok({ data: [] })));
  await page.route(/\/api\/v1\/commission-rules\/[^/]+\/draft$/, (r) => r.fulfill(ok({ data: rule() })));
  await page.route(/\/api\/v1\/compensation-plans\/[^/]+\/history(\?|$)/, (r) => r.fulfill(ok({ data: history })));
  await page.route(/\/api\/v1\/compensation-plans\/[^/]+\/submit$/, (r) =>
    r.fulfill(ok({ data: plan({ status: 'pending_approval', status_label: 'Pending approval', capabilities: pendingCaps }) })),
  );
  await page.route(/\/api\/v1\/compensation-plans\/[^/]+\/approve$/, (r) => {
    const res = opts.approveResponse;
    if (res && res.status !== 200) return r.fulfill(denied(res.status, res.code ?? 'forbidden', res.message ?? 'Denied.'));
    return r.fulfill(ok({ data: plan({ status: 'active', status_label: 'Active' }) }));
  });
  await page.route(/\/api\/v1\/compensation-plans\/[^/]+\/(reject|cancel)$/, (r) =>
    r.fulfill(ok({ data: plan({ status: 'rejected', status_label: 'Rejected' }) })),
  );
  await page.route(/\/api\/v1\/compensation-plans\/[^/]+$/, (r) => r.fulfill(ok({ data: plans[0] })));
  await page.route(/\/api\/v1\/compensation-plans(\?|$)/, (r) => {
    if (r.request().method() === 'POST') return r.fulfill(created({ data: plan() }));
    return r.fulfill(ok({ data: plans }));
  });
}

async function gotoHr(page: Page, plans?: unknown[]): Promise<void> {
  await stubMe(page, { role: 'hr', permissions: HR_KEYS });
  await stubCompensation(page, plans ? { plans } : {});
  await page.goto('/hr/compensation');
  await expect(page.getByRole('heading', { name: 'Compensation', level: 1 })).toBeVisible();
}

/* ---------------------------------------------------------------- HR happy path */

test.describe('HR compensation configuration', () => {
  test('lists plans with configured terms, model and preferred-fee basis copy', async ({ page }) => {
    await gotoHr(page);
    // Scoped to the row: the model label and the preferred-fee copy also legitimately appear in the
    // filter <option> list and the commission-rule form label.
    const row = page.getByTestId('plan-row');
    await expect(row).toBeVisible();
    await expect(row.getByText('Salary plus commission')).toBeVisible();
    await expect(row.getByText('15.00% of Service price')).toBeVisible();
    await expect(row.getByText('Preferred-personnel fee included in commission basis')).toBeVisible();
  });

  test('creates a commission rule draft then a compensation plan draft', async ({ page }) => {
    await gotoHr(page);

    await page.getByRole('button', { name: 'New commission rule' }).click();
    await page.locator('#comp-rule-bp').fill('1500');
    await page.locator('#comp-rule-from').fill('2026-09-01');
    await page.locator('#comp-rule-reason').fill('Standard rate');
    await page.getByTestId('save-rule').click();
    await expect(page.getByTestId('comp-status')).toContainText('Commission rule draft created.');

    await page.getByRole('button', { name: 'New compensation plan' }).click();
    await page.locator('#comp-plan-staff').selectOption('01STAFF0000000000000000001');
    await page.locator('#comp-plan-model').selectOption('salary_only');
    await page.locator('#comp-plan-salary').fill('50000');
    await page.locator('#comp-plan-from').fill('2026-09-01');
    await page.locator('#comp-plan-reason').fill('New terms');
    await page.getByTestId('save-plan').click();
    await expect(page.getByTestId('comp-status')).toContainText('Compensation plan draft created.');
  });

  test('submits a draft and surfaces the maker/checker expectation', async ({ page }) => {
    await gotoHr(page);
    await page.getByTestId('submit-01PLAN00000000000000000001').click();
    await expect(page.getByRole('dialog')).toContainText('can never approve it');
    await page.locator('#comp-confirm-reason').fill('Ready for approval');
    await page.getByTestId('confirm-transition').click();
    await expect(page.getByTestId('comp-status')).toContainText('Pending approval');
  });

  test('approves a pending plan and shows the activated result plus history', async ({ page }) => {
    await gotoHr(page, [plan({ status: 'pending_approval', status_label: 'Pending approval', capabilities: pendingCaps })]);
    await page.getByTestId('approve-01PLAN00000000000000000001').click();
    await expect(page.getByRole('dialog')).toContainText('Approval requires a fresh step-up verification');
    await page.locator('#comp-confirm-reason').fill('Approved by a different HR approver');
    await page.getByTestId('confirm-transition').click();
    await expect(page.getByTestId('comp-status')).toContainText('Active');

    await page.getByTestId('view-01PLAN00000000000000000001').click();
    await expect(page.getByTestId('history-event').first()).toContainText('Created');
  });

  test('never offers a supersede, payout, earnings or ledger control', async ({ page }) => {
    await gotoHr(page, [plan({ status: 'active', status_label: 'Active' })]);
    for (const forbidden of ['Supersede', 'Payout', 'Earnings', 'Ledger', 'Liability', 'Wallet']) {
      await expect(page.getByRole('button', { name: forbidden })).toHaveCount(0);
    }
    await expect(page.getByText(/earned|payable|settled|settlement/i)).toHaveCount(0);
  });
});

/* ---------------------------------------------------------------- backdated approval */

test.describe('Backdated approval', () => {
  const backdated = () =>
    plan({
      status: 'pending_approval',
      status_label: 'Pending approval',
      is_backdated: true,
      effective_from: '2026-06-01',
      capabilities: pendingCaps,
    });

  test('warns, requires the impact preview, and blocks approval until acknowledged', async ({ page }) => {
    await gotoHr(page, [backdated()]);
    await expect(page.getByTestId('plan-backdated')).toBeVisible();

    await page.getByTestId('approve-01PLAN00000000000000000001').click();
    await expect(page.getByTestId('backdated-warning')).toBeVisible();
    await expect(page.getByTestId('backdated-warning')).toContainText('Impact preview');

    await page.locator('#comp-confirm-reason').fill('Retroactive agreement');
    await page.getByTestId('confirm-transition').click();
    await expect(page.getByTestId('confirm-error')).toContainText('Acknowledge the impact preview');

    await page.locator('#comp-ack-preview').check();
    await page.getByTestId('confirm-transition').click();
    await expect(page.getByTestId('comp-status')).toContainText('Active');
  });

  test('surfaces a stale step-up denial and never fakes the approval', async ({ page }) => {
    await stubMe(page, { role: 'hr', permissions: HR_KEYS });
    await stubCompensation(page, {
      plans: [backdated()],
      approveResponse: { status: 403, code: 'step_up_required', message: 'A fresh step-up is required.' },
    });
    await page.goto('/hr/compensation');
    await page.getByTestId('approve-01PLAN00000000000000000001').click();
    await page.locator('#comp-confirm-reason').fill('Retroactive agreement');
    await page.locator('#comp-ack-preview').check();
    await page.getByTestId('confirm-transition').click();

    await expect(page.getByTestId('confirm-error')).toContainText('fresh step-up verification');
    await expect(page.getByTestId('plan-status')).toHaveText('Pending approval');
  });

  test('surfaces a maker/checker denial when the submitter tries to approve', async ({ page }) => {
    await stubMe(page, { role: 'hr', permissions: HR_KEYS });
    await stubCompensation(page, {
      plans: [backdated()],
      approveResponse: {
        status: 422,
        code: 'maker_checker_violation',
        message: 'The person who submitted a compensation change cannot approve it.',
      },
    });
    await page.goto('/hr/compensation');
    await page.getByTestId('approve-01PLAN00000000000000000001').click();
    await page.locator('#comp-confirm-reason').fill('Self approval attempt');
    await page.locator('#comp-ack-preview').check();
    await page.getByTestId('confirm-transition').click();

    await expect(page.getByTestId('confirm-error')).toContainText('A different HR approver must approve this plan.');
    await expect(page.getByTestId('plan-status')).toHaveText('Pending approval');
  });
});

/* ---------------------------------------------------------------- role denial */

test.describe('Role boundaries', () => {
  for (const role of ['merchant_admin', 'branch_manager', 'finance', 'front_office', 'personnel', 'audit']) {
    test(`${role} cannot reach HR Compensation`, async ({ page }) => {
      // These roles hold NO compensation key at all (Plan §10.2) — the matrix grants the eight
      // Phase 20F keys to HR only. The backend denies regardless; the guard keeps the UI honest.
      await stubMe(page, { role, permissions: ['branch.dashboard.view'] });
      await stubCompensation(page);
      await page.goto('/hr/compensation');
      await expect(page.getByRole('heading', { name: 'Compensation', level: 1 })).toHaveCount(0);
      await expect(page.getByTestId('open-plan-create')).toHaveCount(0);
    });
  }

  test('HR without compensation.plan.view cannot reach the screen', async ({ page }) => {
    await stubMe(page, { role: 'hr', permissions: ['staff.view'] });
    await stubCompensation(page);
    await page.goto('/hr/compensation');
    await expect(page.getByRole('heading', { name: 'Compensation', level: 1 })).toHaveCount(0);
  });

  test('a view-only HR holder gets no mutation control', async ({ page }) => {
    await stubMe(page, { role: 'hr', permissions: ['compensation.plan.view'] });
    await stubCompensation(page);
    await page.goto('/hr/compensation');
    await expect(page.getByTestId('plan-row')).toBeVisible();
    await expect(page.getByTestId('open-plan-create')).toHaveCount(0);
    await expect(page.getByTestId('open-rule-create')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Submit' })).toHaveCount(0);
  });

  test('the Merchant Administrator compensation summary is supplied by Phase 20H', async ({ page }) => {
    await stubMe(page, { role: 'merchant_admin', permissions: ['merchant.compensation_summary.view'] });
    await stubCompensation(page);
    await page.goto('/merchant/compensation-summary');
    // Phase 20H now owns this surface; keep the cross-phase ownership truth current.
    await expect(page.getByRole('heading', { name: 'Compensation summary' })).toHaveCount(1);
  });
});

/* ---------------------------------------------------------------- model-shape UI */

test.describe('Compensation model shape', () => {
  test('salary_only forbids a commission rule; commission_only forbids salary', async ({ page }) => {
    await gotoHr(page);
    await page.getByRole('button', { name: 'New compensation plan' }).click();

    await page.locator('#comp-plan-model').selectOption('salary_only');
    await expect(page.locator('#comp-plan-salary')).toBeVisible();
    await expect(page.locator('#comp-plan-rule')).toHaveCount(0);
    await expect(page.getByTestId('no-rule-hint')).toContainText('cannot reference a commission rule');

    await page.locator('#comp-plan-model').selectOption('commission_only');
    await expect(page.locator('#comp-plan-salary')).toHaveCount(0);
    await expect(page.locator('#comp-plan-rule')).toBeVisible();

    await page.locator('#comp-plan-model').selectOption('salary_plus_commission');
    await expect(page.locator('#comp-plan-salary')).toBeVisible();
    await expect(page.locator('#comp-plan-rule')).toBeVisible();
  });

  test('percentage and fixed commission fields are mutually exclusive', async ({ page }) => {
    await gotoHr(page);
    await page.getByRole('button', { name: 'New commission rule' }).click();
    await expect(page.locator('#comp-rule-bp')).toBeVisible();
    await expect(page.locator('#comp-rule-fixed')).toHaveCount(0);

    await page.locator('#comp-rule-type').selectOption('fixed_amount');
    await expect(page.locator('#comp-rule-bp')).toHaveCount(0);
    await expect(page.locator('#comp-rule-fixed')).toBeVisible();
    await expect(page.locator('#comp-rule-currency')).toBeVisible();
  });

  test('states the preferred-personnel-fee basis inclusion in plain language', async ({ page }) => {
    await gotoHr(page);
    await page.getByRole('button', { name: 'New commission rule' }).click();
    // Scoped to the dialog: the same inclusion copy also appears on each plan row.
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Preferred-personnel fee included in commission basis')).toBeVisible();
    await expect(dialog.getByText('Leave unticked to exclude the preferred-personnel fee from the commission basis.')).toBeVisible();
  });
});

/* ---------------------------------------------------------------- responsive / zoom / a11y */

test.describe('Responsive, zoom, keyboard and accessibility', () => {
  for (const width of [360, 768, 1280]) {
    test(`has no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await gotoHr(page);
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      );
      expect(overflow).toBe(false);
    });
  }

  test('keeps the plan dialog operable at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoHr(page);
    await page.evaluate(() => {
      document.documentElement.style.zoom = '2';
    });
    await page.getByRole('button', { name: 'New compensation plan' }).click();
    await expect(page.locator('#comp-plan-model')).toBeVisible();
    await expect(page.getByTestId('save-plan')).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflow).toBe(false);
  });

  test('opens the plan dialog by keyboard and restores focus on Escape', async ({ page }) => {
    await gotoHr(page);
    const trigger = page.getByRole('button', { name: 'New compensation plan' });
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });

  test('completes a keyboard-only submit confirmation', async ({ page }) => {
    await gotoHr(page);
    const trigger = page.getByTestId('submit-01PLAN00000000000000000001');
    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.locator('#comp-confirm-reason').focus();
    await page.keyboard.type('Ready for approval');
    await page.getByTestId('confirm-transition').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('comp-status')).toContainText('Pending approval');
  });

  // Every status badge is rendered at once, so a badge whose colour pair fails AA cannot hide behind
  // a fixture that never renders it (exactly how the warning badge slipped through the first pass).
  const allStatuses = [
    plan({ id: '01P1', status: 'draft', status_label: 'Draft' }),
    plan({ id: '01P2', status: 'pending_approval', status_label: 'Pending approval', is_backdated: true, capabilities: pendingCaps }),
    plan({ id: '01P3', status: 'scheduled', status_label: 'Scheduled' }),
    plan({ id: '01P4', status: 'active', status_label: 'Active' }),
    plan({
      id: '01P5',
      status: 'superseded',
      status_label: 'Superseded',
      capabilities: { can_update_draft: false, can_submit: false, can_approve: false, can_reject: false, can_cancel: false, is_terminal: true },
    }),
  ];

  for (const scheme of ['light', 'dark'] as const) {
    test(`passes axe with zero serious/critical violations (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoHr(page, allStatuses);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });

    test(`passes axe on the detail + history dialog (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoHr(page);
      await page.getByTestId('view-01PLAN00000000000000000001').click();
      await expect(page.getByTestId('history-event').first()).toBeVisible();
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });

    test(`passes axe on the plan dialog (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoHr(page);
      await page.getByRole('button', { name: 'New compensation plan' }).click();
      await expect(page.locator('#comp-plan-model')).toBeVisible();
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });

    test(`passes axe on the backdated approval dialog (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoHr(page, [
        plan({ status: 'pending_approval', status_label: 'Pending approval', is_backdated: true, effective_from: '2026-06-01', capabilities: pendingCaps }),
      ]);
      await page.getByTestId('approve-01PLAN00000000000000000001').click();
      await expect(page.getByTestId('backdated-warning')).toBeVisible();
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });
  }

  for (const scheme of ['light', 'dark'] as const) {
    for (const width of [360, 768, 1280]) {
      test(`renders the list at ${width}px in ${scheme} mode`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await page.setViewportSize({ width, height: 900 });
        await gotoHr(page);
        await expect(page.getByTestId('plan-row')).toBeVisible();
        await expect(page.getByTestId('plan-status')).toBeVisible();
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        );
        expect(overflow).toBe(false);
      });
    }
  }
});
