import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { prepare, SCREENS, type AuditScreen, type Theme } from './releaseAudit';
import { stubPlatformApi, stubSuperAdmin } from './ui08Platform';
import { BRANCH_ID, INVOICE_ID, stubMerchant, stubMerchantApi } from './ui09Merchant';
import { stubBranch, stubBranchApi } from './ui10Branch';

/**
 * Phase UI-16 cross-account visual harness.
 *
 * ONE place decides how each of the 132 live contract pages is put into a populated, deterministic
 * state for the responsive / theme / accessibility sweep and the visual baselines. It composes the
 * existing harnesses rather than inventing new fixtures:
 *
 *  - every page starts from the Phase 23 release-audit `prepare()` (pinned Nairobi clock, fixed
 *    identifiers, fixed theme, stubbed `/me` and `/api/v1/**`);
 *  - the three older accounts whose phases shipped richer synthetic data (UI-08 platform, UI-09
 *    merchant, UI-10 branch) layer those stubs on top — Playwright runs the LAST registered route
 *    first, so the account stub wins over the generic fallback;
 *  - Finance and HR receive the permission lists their own phase specs grant, so their pages render
 *    the authorized populated state instead of a misleading forbidden one.
 *
 * Everything here is UX input to drive the real frontend. Authorization is proven by the backend
 * suite; no permission list below is a security claim.
 */

const platformDashboard = JSON.parse(
  readFileSync(new URL('./remdep003-platform-dashboard.json', import.meta.url), 'utf8'),
) as unknown;

/** The first-time-setup options, in the shape `FirstTimeSetup.spec.ts` already pins. Synthetic. */
const FIRST_TIME_SETUP_OPTIONS = {
  data: {
    options: {
      service_fee_tiers: [
        { value: 'split_tier', label: 'Split tier' },
        { value: 'business_centric', label: 'Business-centric' },
        { value: 'customer_centric', label: 'Customer-centric' },
      ],
      subscription_plans: [{
        id: '01JPLAN0000000000000000000',
        name: 'Starter',
        description: 'Starter plan',
        tier: 'starter',
        prices: [{ id: '01JPRICE000000000000000000', amount_minor: 250000, currency: 'KES', billing_interval: 'monthly' }],
      }],
    },
  },
};

export const FINANCE_PERMISSIONS = [
  'customer_payment.view', 'customer_payment.validate', 'customer_payment.reject',
  'customer_payment.reference_correct', 'customer_payment.duplicate_override',
  'invoice.view', 'receipt.view', 'receipt.download', 'receipt.reissue',
  'finance_dispute.manage', 'refund.create', 'refund.approve', 'refund.finalize',
  'cash_up.view', 'cash_up.approve', 'cash_up.reject',
  'period_lock.create', 'period_lock.reopen',
  'payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid',
  'compensation.liability.view', 'compensation.adjustment.create', 'earnings_query.respond',
  'finance_export.create', 'finance_export.download', 'finance.audit.view',
];

export const HR_PERMISSIONS = [
  'staff.view', 'staff.invite', 'staff.suspend',
  'personnel.eligibility.manage', 'personnel.availability.manage',
  'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
  'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
  'compensation.plan.cancel', 'compensation.history.view',
  'payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft',
];

export interface ContractPage {
  /** Canonical contract key, e.g. `merchant_finance.payments-validations`. */
  contractKey: string;
  account: string;
  ownerPhase: string;
  label: string;
  routePath: string;
  screen: AuditScreen;
}

interface MatrixPage {
  key: string;
  label: string;
  navigation_group: string;
  navigation_visibility: 'primary' | 'contextual_child' | 'detail_route';
  route_path: string;
  implementation_status: string;
  runtime_route_name: string;
}

interface MatrixAccount {
  account_type: string;
  owner_phase: string;
  pages: MatrixPage[];
}

const MATRIX = JSON.parse(
  readFileSync(new URL('../../../docs/frontend/audits/ui-07/account-page-matrix.json', import.meta.url), 'utf8'),
) as { accounts: MatrixAccount[] };

/** The generated UI-07 contract projection — the 160 rows, in canonical order. */
export function contractRows(): Array<MatrixPage & { account: string; owner_phase: string }> {
  return MATRIX.accounts.flatMap((a) => a.pages.map((p) => ({ ...p, account: a.account_type, owner_phase: a.owner_phase })));
}

/**
 * Every IMPLEMENTED contract page joined to the release-audit screen that renders it. Derived at
 * run time from the canonical projection, so a newly implemented page cannot escape the sweep and
 * a gated page can never be enrolled as live.
 */
export function liveContractPages(): ContractPage[] {
  const byRoute = new Map(SCREENS.filter((s) => s.route).map((s) => [s.route!, s]));
  return contractRows()
    .filter((p) => p.implementation_status === 'implemented')
    .map((p) => {
      const screen = byRoute.get(p.runtime_route_name);
      if (!screen) throw new Error(`UI-16: no release-audit screen renders ${p.key} (${p.runtime_route_name})`);
      return {
        contractKey: p.key,
        account: p.account,
        ownerPhase: p.owner_phase,
        label: p.label,
        routePath: p.route_path,
        screen: withRolePermissions(screen),
      };
    });
}

function withRolePermissions(screen: AuditScreen): AuditScreen {
  // The UI-09 merchant stub answers its OWN record identifiers; the release-audit paths use the
  // generic IDS, which would fall through to an empty fallback and render a blank record.
  if (screen.key === 'merchant-branch-detail') return { ...screen, path: `/branches/${BRANCH_ID}` };
  if (screen.key === 'subscription-invoice-detail') return { ...screen, path: `/subscription/invoices/${INVOICE_ID}` };
  if (screen.role === 'merchant_finance') {
    return { ...screen, bootstrap: { ...screen.bootstrap, permissions: FINANCE_PERMISSIONS } };
  }
  if (screen.role === 'merchant_human_resource') {
    return { ...screen, bootstrap: { ...screen.bootstrap, permissions: HR_PERMISSIONS } };
  }
  return screen;
}

/** Prepare one contract page in its populated, deterministic state. Call before navigating. */
export async function prepareVisual(page: Page, screen: AuditScreen, theme: Theme = 'light'): Promise<void> {
  await prepare(page, screen, { theme });
  if (screen.role === 'super_administrator') {
    await stubSuperAdmin(page);
    await stubPlatformApi(page);
    await page.route('**/api/v1/platform/dashboard', (route) => route.fulfill({ json: platformDashboard }));
  } else if (screen.key === 'first-time-setup') {
    // The mandatory pre-dashboard setup reads its server-owned options; the generic fallback answers
    // an empty list, which renders the (legitimate, separately tested) load-error state instead.
    await page.route('**/api/v1/merchant-registration/first-time-setup', (route) => route.fulfill({ json: FIRST_TIME_SETUP_OPTIONS }));
  } else if (screen.role === 'merchant_administrator') {
    await stubMerchant(page);
    await stubMerchantApi(page);
  } else if (screen.role === 'merchant_branch') {
    await stubBranch(page);
    await stubBranchApi(page);
  }
}
