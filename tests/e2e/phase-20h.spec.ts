import AxeBuilder from '@axe-core/playwright';
import { accountKeyForRole, stubAccountContextForRole } from './support/roleBootstrap';
import { expect, test, type Page } from '@playwright/test';

/*
 | Phase 20H E2E — the payout-run + earnings frontend (Plan §62/§63, §80, §27.1): HR payout drafts,
 | Finance verify/approve/reject/mark-paid, Merchant-Administrator compensation summary + high-value
 | approval, and Personnel own-scope earnings/statements/queries + the Finance responder queue. The SPA
 | preview has no backend; `/me` + `/api/v1` are stubbed to drive the REAL frontend. Genuine authorization,
 | MFA/fresh step-up, server-side scope, idempotency, the payout state machine and the ledger settlement
 | are proven by the backend Feature suite (tests/Feature/Compensation/*); these prove frontend behaviour,
 | role gating, canonical copy and accessibility. **Servana moves no money** — there is NO Wallet/provider/
 | settlement-runtime surface.
 */

function ok(body: unknown) {
  return { status: 200, contentType: 'application/json', body: JSON.stringify(body) };
}
function created(body: unknown) {
  return { status: 201, contentType: 'application/json', body: JSON.stringify(body) };
}
function forbidden(code: string) {
  return { status: 403, contentType: 'application/json', body: JSON.stringify({ error: { code, message: 'A fresh step-up is required.', fields: {}, meta: {} } }) };
}

interface MeOpts {
  role?: string | null;
  permissions?: string[];
  stepUpFresh?: boolean;
}
async function stubMe(page: Page, opts: MeOpts = {
}): Promise<void> {
  // Phase UI-07: the account guard now covers every authenticated tree, so this spec must
  // serve the host context the Laravel shell embeds, exactly as it already stubs /me.
  await stubAccountContextForRole(page, opts.role, opts.isPlatformStaff ?? false);

  await page.route('**/sanctum/csrf-cookie', (r) => r.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (r) =>
    r.fulfill(ok({
      data: {
        user: { id: '01JUSER0000000000000000000', email: 'u@citrus.co.ke', name: 'Ada', status: 'active', email_verified_at: '2026-06-14T00:00:00+00:00', is_platform_staff: false },
        merchant: { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
        membership: opts.role ? { id: 'mm1', role: opts.role, status: 'active' } : null,
        memberships: opts.role ? [{ id: 'mm1', role: opts.role, status: 'active' }] : [],
        account_keys: [accountKeyForRole(opts.role, opts.isPlatformStaff ?? false)],
        permissions: opts.permissions ?? [],
        setup: { required: false, current_step: null, completed_at: null },
        branch_ids: ['b1'],
        mfa: { required: false, enrolled: true, confirmed: true, verified: true, enrollment_required: false, challenge_required: false, step_up_fresh: opts.stepUpFresh ?? true, step_up_fresh_until: null, recovery_codes_remaining: 5 },
      },
    })),
  );
}

const BRANCH = '01HBRANCH00000000000000000';
function meta(rows: unknown[]) { return { current_page: 1, last_page: 1, per_page: 25, total: rows.length }; }

function run(status: string, overrides: Record<string, unknown> = {}) {
  return {
    id: '01HRUN00000000000000000000', branch_id: BRANCH, period_start: '2026-07-01', period_end: '2026-07-31',
    currency: 'KES', status, gross_total_minor: 350000, high_value_threshold_snapshot_minor: null, is_high_value: false,
    rejection_reason: null, has_external_payment_reference: false, paid_at: null, item_count: 1,
    items: [{ id: '01HITEM0000000000000000000', staff_profile_id: '01HSTAFF00000000000000000', staff_display_name: 'A. Stylist', payout_run_id: '01HRUN00000000000000000000', currency: 'KES', salary_amount_minor: 300000, commission_amount_minor: 50000, adjustment_amount_minor: 0, gross_amount_minor: 350000, status, source_counts: { salary: 1, commission: 1, adjustment: 0 }, has_statement: false, statement_file_id: null, created_at: '2026-07-15T09:00:00+00:00' }],
    created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00', ...overrides,
  };
}

/* ================================================================= HR payout drafts */

test.describe('HR payout runs', () => {
  async function goto(page: Page, opts: MeOpts = {}): Promise<void> {
    await stubMe(page, { role: 'hr', permissions: ['payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft'], ...opts });
    await page.route(/\/api\/v1\/hr\/payout-runs\/[^/]+\/submit$/, (r) => r.fulfill(ok({ data: run('submitted') })));
    await page.route(/\/api\/v1\/hr\/payout-runs\/[^/]+$/, (r) => {
      if (r.request().method() === 'PATCH') return r.fulfill(ok({ data: run('draft') }));
      return r.fulfill(ok({ data: run('draft') }));
    });
    await page.route(/\/api\/v1\/hr\/payout-runs(\?|$)/, (r) => {
      if (r.request().method() === 'POST') return r.fulfill(created({ data: run('draft') }));
      return r.fulfill(ok({ data: [run('draft')], meta: meta([run('draft')]) }));
    });
    await page.goto('/payouts');
    await expect(page.getByRole('heading', { name: 'Payout run preparation' })).toBeVisible();
  }

  test('creates a draft, sees server-snapshotted items, and submits — never a mark-paid control', async ({ page }) => {
    await goto(page);
    await page.getByTestId('open-create').click();
    await page.locator('#create-branch').fill(BRANCH);
    await page.locator('#create-start').fill('2026-07-01');
    await page.locator('#create-end').fill('2026-07-31');
    await page.locator('#create-currency').fill('KES');
    await page.getByTestId('create-submit').click();
    // The detail opens on the created run with server-snapshotted items.
    await expect(page.getByTestId('payout-item-row').first()).toBeVisible();
    await page.getByTestId('submit-draft').click();
    await expect(page.getByTestId('payout-status')).toContainText('submitted');
    // HR never verifies or marks paid.
    await expect(page.getByTestId('mark-paid')).toHaveCount(0);
    await expect(page.getByTestId('verify-run')).toHaveCount(0);
  });

  test('shows a safe forbidden state without the payout permission', async ({ page }) => {
    await stubMe(page, { role: 'hr', permissions: [] });
    await page.goto('/payouts');
    await expect(page.getByTestId('payout-forbidden')).toBeVisible();
  });
});

/* ================================================================= Finance payouts */

test.describe('Finance payout runs', () => {
  async function goto(page: Page, listStatus = 'approved', opts: MeOpts = {}, postResult?: 'step_up'): Promise<void> {
    await stubMe(page, { role: 'finance', permissions: ['payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid'], ...opts });
    for (const action of ['verify', 'approve', 'reject', 'mark-paid']) {
      await page.route(new RegExp(`/api/v1/finance/payout-runs/[^/]+/${action}$`), (r) => {
        if (postResult === 'step_up') return r.fulfill(forbidden('step_up_required'));
        return r.fulfill(ok({ data: run(action === 'mark-paid' ? 'paid' : action === 'verify' ? 'finance_verified' : action === 'approve' ? 'approved' : 'rejected', { has_external_payment_reference: action === 'mark-paid' }) }));
      });
    }
    await page.route(/\/api\/v1\/finance\/payout-runs\/[^/]+$/, (r) => r.fulfill(ok({ data: run(listStatus) })));
    await page.route(/\/api\/v1\/finance\/payout-runs(\?|$)/, (r) => r.fulfill(ok({ data: [run(listStatus)], meta: meta([run(listStatus)]) })));
    await page.goto('/finance/payout-runs');
    await expect(page.getByRole('heading', { name: 'Payout runs' })).toBeVisible();
  }

  test('marks an approved run paid with an external reference + not-future paid date, stating no money moves', async ({ page }) => {
    await goto(page, 'approved');
    await page.getByTestId('run-details-01HRUN00000000000000000000').click();
    await page.getByTestId('mark-paid').click();
    await expect(page.getByTestId('mark-paid-warning')).toContainText('does not transfer any funds');
    await page.locator('#mark-paid-reference').fill('MPESA-REF-778');
    await page.locator('#mark-paid-date').fill('2026-07-15');
    await page.getByTestId('mark-paid-submit').click();
    await expect(page.getByTestId('payout-status')).toContainText('external settlement');
    // No provider action exists. Gate W navigation may name the unavailable capability truthfully,
    // but it remains disabled and cannot become a payment runtime control.
    for (const banned of ['Wallet', 'STK', 'PayBill', 'Daraja']) {
      await expect(page.getByRole('button', { name: new RegExp(banned, 'i') })).toHaveCount(0);
      await expect(page.locator('a[href]').filter({ hasText: banned })).toHaveCount(0);
    }
    const gated = page.locator('[aria-disabled="true"]').filter({ hasText: 'External Gate W' }).first();
    await expect(gated).toBeVisible();
    await expect(gated).not.toHaveAttribute('href');
  });

  test('surfaces a safe fresh step-up state when mark-paid is stale, with a reachable verify flow', async ({ page }) => {
    await goto(page, 'approved', { stepUpFresh: false }, 'step_up');
    await page.getByTestId('run-details-01HRUN00000000000000000000').click();
    await page.getByTestId('mark-paid').click();
    await page.locator('#mark-paid-reference').fill('REF');
    await page.locator('#mark-paid-date').fill('2026-07-15');
    await page.getByTestId('mark-paid-submit').click();
    await expect(page.getByTestId('mark-paid-step-up')).toBeVisible();
    await expect(page.getByTestId('mark-paid-verify')).toBeVisible();
  });
});

/* ================================================================= Merchant Administrator */

test.describe('Merchant Administrator compensation summary', () => {
  const summary = {
    outstanding_liability_by_currency: [
      { currency: 'KES', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 300000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 50000, compensation_adjustment_minor: 0, combined_net_liability_minor: 350000 },
      { currency: 'USD', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 40000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 0, compensation_adjustment_minor: 0, combined_net_liability_minor: 40000 },
    ],
    paid_by_currency: [{ currency: 'KES', paid_gross_minor: 350000, run_count: 1 }],
    payout_runs_by_status: { pending_merchant_admin_approval: 1, paid: 1 },
    pending_high_value_approvals: 1,
  };
  const hvRun = run('pending_merchant_admin_approval', { gross_total_minor: 900000, high_value_threshold_snapshot_minor: 100000, is_high_value: true });

  async function goto(page: Page): Promise<void> {
    await stubMe(page, { role: 'merchant_admin', permissions: ['merchant.compensation_summary.view', 'merchant.payout.approve_high_value'] });
    await page.route(/\/api\/v1\/merchant\/compensation-summary(\?|$)/, (r) => r.fulfill(ok({ data: summary })));
    await page.route(/\/api\/v1\/merchant\/payout-runs\/[^/]+\/approve-high-value$/, (r) => r.fulfill(ok({ data: run('approved') })));
    await page.route(/\/api\/v1\/merchant\/payout-runs(\?|$)/, (r) => r.fulfill(ok({ data: [hvRun], meta: meta([hvRun]) })));
    await page.goto('/compensation');
    await expect(page.getByRole('heading', { name: 'Compensation summary' })).toBeVisible();
  }

  test('keeps summary and high-value approval responsibilities separate, with no mark-paid control', async ({ page }) => {
    await goto(page);
    await expect(page.getByTestId('outstanding-card')).toHaveCount(2);
    await expect(page.getByTestId('pending-high-value')).toHaveText('1');
    await expect(page.getByText('Mark paid')).toHaveCount(0);

    await page.goto('/compensation/payout-approvals');
    await expect(page.getByRole('heading', { level: 1, name: 'High-value payout approvals' })).toBeVisible();
    await expect(page.getByText('Mark paid')).toHaveCount(0);
    await page.getByTestId('approve-high-value-01HRUN00000000000000000000').click();
    await page.getByTestId('approve-submit').click();
    await expect(page.getByTestId('summary-status')).toContainText('approved');
  });
});

/* ================================================================= Personnel earnings */

test.describe('Personnel earnings', () => {
  const overview = {
    tab_visibility: { model: 'salary_plus_commission', has_current_plan: true, conflicting: false, salary_tab: true, commission_tab: true },
    currencies: [{ currency: 'KES', salary_unpaid_minor: 300000, salary_paid_minor: 0, commission_unpaid_minor: 50000, commission_paid_minor: 0, adjustment_unpaid_minor: 0, adjustment_paid_minor: 0, unpaid_minor: 350000, paid_minor: 0, net_minor: 350000 }],
  };
  const item = { id: '01HITEM0000000000000000000', staff_profile_id: null, staff_display_name: null, payout_run_id: null, currency: 'KES', salary_amount_minor: 0, commission_amount_minor: 50000, adjustment_amount_minor: 0, gross_amount_minor: 50000, status: 'paid', source_counts: { salary: 0, commission: 1, adjustment: 0 }, has_statement: false, statement_file_id: null, created_at: '2026-07-15T09:00:00+00:00' };
  const query = { id: '01HQUERY00000000000000000', staff_profile_id: '01HSTAFF00000000000000000', subject_type: 'commission_ledger', query_type: 'commission_disagreement', body: 'short by 500', status: 'open', assigned_role: 'finance', resolution_note: null, resolved_adjustment_id: null, responded_at: null, created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00' };

  async function goto(page: Page): Promise<void> {
    await stubMe(page, { role: 'personnel', permissions: ['personnel.my_earnings.view', 'personnel.my_compensation.view', 'personnel.my_payouts.view', 'personnel.my_statements.download', 'personnel.my_earnings_query.create'] });
    await page.route(/\/api\/v1\/personnel\/me\/earnings-queries\/[^/]+$/, (r) => r.fulfill(ok({ data: query })));
    await page.route(/\/api\/v1\/personnel\/me\/earnings-queries(\?|$)/, (r) => r.request().method() === 'POST' ? r.fulfill(created({ data: query })) : r.fulfill(ok({ data: [query], meta: meta([query]) })));
    await page.route(/\/api\/v1\/personnel\/me\/earnings(\?|$)/, (r) => r.fulfill(ok({ data: overview })));
    await page.route(/\/api\/v1\/personnel\/me\/compensation(\?|$)/, (r) => r.fulfill(ok({ data: { has_current_plan: true, conflicting: false, compensation_model: 'salary_plus_commission' } })));
    await page.route(/\/api\/v1\/personnel\/me\/payouts(\?|$)/, (r) => r.fulfill(ok({ data: [item], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } })));
    await page.route(/\/api\/v1\/personnel\/me\/payout-items\/[^/]+\/statement$/, (r) => r.fulfill(ok({ data: { statement: { id: '01HFILE000000000000000000', filename: 'statement.pdf', mime_type: 'application/pdf', size_bytes: 100, generated_at: '2026-07-16T00:00:00+00:00' }, download: { url: '/api/v1/files/01HFILE000000000000000000/download?sig=x', expires_at: '2026-07-16T00:05:00+00:00' } } })));
    await page.goto('/personnel/earnings');
    await expect(page.getByRole('heading', { level: 1, name: 'My earnings' })).toBeVisible();
  }

  test('shows own per-currency earnings + both tabs and generates a statement link, with no staff selector', async ({ page }) => {
    await goto(page);
    await expect(page.getByTestId('earnings-currency-card')).toHaveCount(1);
    await expect(page.getByText('Salary (unpaid / paid)')).toBeVisible();
    await expect(page.getByText('Commission (unpaid / paid)')).toBeVisible();
    await page.getByTestId('statement-01HITEM0000000000000000000').click();
    await expect(page.getByTestId('statement-link-01HITEM0000000000000000000')).toBeVisible();
  });

  test('raises an own-scope earnings query', async ({ page }) => {
    await goto(page);
    await page.getByTestId('open-query').click();
    await page.locator('#query-subject-ulid').fill('01HLEDGER0000000000000000A');
    await page.locator('#query-body').fill('My commission looks short this period.');
    await page.getByTestId('query-submit').click();
    await expect(page.getByTestId('earnings-status')).toContainText('submitted');
  });
});

/* ================================================================= Finance earnings-query responder */

test.describe('Finance earnings-query responder', () => {
  function query(status: string, overrides: Record<string, unknown> = {}) {
    return { id: '01HQUERY00000000000000000', staff_profile_id: '01HSTAFF00000000000000000', subject_type: 'commission_ledger', query_type: 'commission_disagreement', body: 'short by 500', status, assigned_role: 'finance', resolution_note: null, resolved_adjustment_id: null, responded_at: null, created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00', ...overrides };
  }
  async function goto(page: Page): Promise<void> {
    await stubMe(page, { role: 'finance', permissions: ['earnings_query.respond'] });
    await page.route(/\/api\/v1\/finance\/earnings-queries\/[^/]+\/respond$/, (r) => r.fulfill(ok({ data: query('resolved', { resolved_adjustment_id: '01HADJ0000000000000000000' }) })));
    await page.route(/\/api\/v1\/finance\/earnings-queries\/[^/]+$/, (r) => r.fulfill(ok({ data: query('open') })));
    await page.route(/\/api\/v1\/finance\/earnings-queries(\?|$)/, (r) => r.fulfill(ok({ data: [query('open')], meta: meta([query('open')]) })));
    await page.goto('/finance/earnings-queries');
    await expect(page.getByRole('heading', { name: 'Earnings queries' })).toBeVisible();
  }

  test('resolves a query with an additive correction — no ledger editor is offered', async ({ page }) => {
    await goto(page);
    await page.getByTestId('query-open-01HQUERY00000000000000000').click();
    await page.locator('#respond-note').fill('Confirmed a shortfall; issuing a correction.');
    await page.getByTestId('with-correction').check();
    await page.locator('#correction-amount').fill('5');
    await page.locator('#correction-currency').fill('KES');
    await page.locator('#correction-reason').fill('Commission shortfall correction');
    await expect(page.getByTestId('correction-preview')).toContainText('does not edit the original earnings');
    await page.getByTestId('respond-submit').click();
    await expect(page.getByTestId('query-status')).toContainText('resolved');
  });
});

/* ================================================================= role denial */

test.describe('Role denial (frontend UX; the API is the boundary)', () => {
  /*
   | Phase UI-07: still a denial case, denied EARLIER.
   |
   | Personnel is strictly own-scope, so the Finance account's payout worklist is refused at the
   | route and the screen never mounts. Previously the account guard did not exist, so the test
   | reached the screen and asserted its 403-backed forbidden state. The API still answers 403 —
   | that stub stays, and `payout_run.verify` carries its own negative cases in
   | docs/auth/permission-matrix.yaml — but the browser no longer gets that far, which is the
   | stricter outcome. Defence in depth: the backend remains the boundary.
   */
  test('personnel cannot reach the Finance payout worklist', async ({ page }) => {
    await stubMe(page, { role: 'personnel', permissions: ['personnel.my_earnings.view'] });
    await page.route(/\/api\/v1\/finance\/payout-runs(\?|$)/, (r) => r.fulfill(forbidden('forbidden')));
    await page.goto('/finance/payout-runs');

      /*
       * Phase UI-08 Increment 7B made the router host-scoped, so this refusal is now STRICTER
       * than a denial page: the account that owns this route has no tree registered on the
       * served host, so the address does not exist there at all. The surface still never
       * mounts, which is what this case is about.
       */
    await expect(page.getByTestId('public-not-found')).toBeVisible();
    await expect(page.getByTestId('payout-forbidden')).toHaveCount(0);
    // Role-safe: neither the forbidden account nor the held one is disclosed.
    const shown = await page.locator('#app').innerText();
    // The refusal must not name the account that OWNS the screen, nor confirm it exists.
    expect(shown).not.toContain('merchant_finance');
    // It may name the viewer's OWN served account — the not-found page offers a way home, and
    // telling someone which account they are already on discloses nothing new.
    expect(shown).not.toContain('payout');
  });

  test('a Finance user without mark-paid never sees the mark-paid control', async ({ page }) => {
    await stubMe(page, { role: 'finance', permissions: ['payout_run.verify'] });
    await page.route(/\/api\/v1\/finance\/payout-runs\/[^/]+$/, (r) => r.fulfill(ok({ data: run('approved') })));
    await page.route(/\/api\/v1\/finance\/payout-runs(\?|$)/, (r) => r.fulfill(ok({ data: [run('approved')], meta: meta([run('approved')]) })));
    await page.goto('/finance/payout-runs');
    await page.getByTestId('run-details-01HRUN00000000000000000000').click();
    await expect(page.getByTestId('mark-paid')).toHaveCount(0);
  });
});

/* ================================================================= responsive / zoom / keyboard / a11y */

test.describe('Responsive, zoom, keyboard and accessibility', () => {
  async function gotoFinance(page: Page): Promise<void> {
    await stubMe(page, { role: 'finance', permissions: ['payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid'] });
    await page.route(/\/api\/v1\/finance\/payout-runs\/[^/]+$/, (r) => r.fulfill(ok({ data: run('approved') })));
    await page.route(/\/api\/v1\/finance\/payout-runs(\?|$)/, (r) => r.fulfill(ok({ data: [run('approved')], meta: meta([run('approved')]) })));
    await page.goto('/finance/payout-runs');
    await expect(page.getByRole('heading', { name: 'Payout runs' })).toBeVisible();
  }
  async function gotoHr(page: Page): Promise<void> {
    await stubMe(page, { role: 'hr', permissions: ['payout_run.create'] });
    await page.route(/\/api\/v1\/hr\/payout-runs(\?|$)/, (r) => r.fulfill(ok({ data: [run('draft')], meta: meta([run('draft')]) })));
    await page.goto('/payouts');
    await expect(page.getByRole('heading', { name: 'Payout run preparation' })).toBeVisible();
  }

  for (const width of [360, 768, 1280]) {
    test(`has no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await gotoFinance(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('remains usable at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoFinance(page);
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    await expect(page.getByRole('heading', { name: 'Payout runs' })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(overflow).toBe(false);
  });

  test('opens the create dialog by keyboard and closes on Escape', async ({ page }) => {
    await gotoHr(page);
    await page.getByTestId('open-create').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`Finance payout screen passes axe (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoFinance(page);
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });

    test(`mark-paid dialog passes axe (${scheme})`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: scheme });
      await gotoFinance(page);
      await page.getByTestId('run-details-01HRUN00000000000000000000').click();
      await page.getByTestId('mark-paid').click();
      await expect(page.getByRole('dialog').filter({ hasText: 'Mark payout run paid' })).toBeVisible();
      const results = await new AxeBuilder({ page }).analyze();
      expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
    });
  }
});
