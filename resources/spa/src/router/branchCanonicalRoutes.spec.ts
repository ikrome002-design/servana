import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';

const LIVE_BRANCH_ROUTES = {
  'branch.dashboard': '/dashboard',
  'branch.get-started': '/get-started',
  'branch.branch-profile': '/branch/profile',
  'branch.branch-calendar': '/branch/calendar',
  'branch.branch-day': '/branch/day',
  'branch.services': '/services',
  'branch.staff': '/staff',
  'branch.operations-queue': '/operations/queue',
  'branch.operations-appointments': '/operations/appointments',
  'branch.finance-invoices': '/finance/invoices',
  'branch.finance-payments': '/finance/payments',
  'branch.finance-receipts': '/finance/receipts',
  'branch.cash-up': '/cash-up',
  'branch.audit': '/audit',
  'branch.account': '/account',
} as const;

describe('UI-10 Branch canonical routes', () => {
  it('registers exactly the fifteen live contract destinations at host-relative paths', () => {
    const router = createAppRouter('merchant_branch');
    for (const [name, path] of Object.entries(LIVE_BRANCH_ROUTES)) {
      expect(router.hasRoute(name), name).toBe(true);
      expect(router.resolve({ name }).path, name).toBe(path);
      expect(router.getRoutes().find((route) => route.name === name)?.meta.screenKey, name).toBeTruthy();
    }
  });

  it('keeps reports, subscription payment and notifications disabled with no live route', () => {
    const router = createAppRouter('merchant_branch');
    for (const name of ['branch.reports', 'branch.subscription-payment', 'branch.notifications']) {
      expect(router.hasRoute(name), name).toBe(false);
    }
  });

  it('keeps legacy URLs as guarded redirects without reviving the retired branch directory', () => {
    const router = createAppRouter('merchant_branch');
    expect(router.resolve('/branch/services').matched.at(-1)?.redirect).toBeDefined();
    expect(router.hasRoute('branch.list')).toBe(false);
    expect(router.hasRoute('branch.create')).toBe(false);
    expect(router.hasRoute('branch.detail')).toBe(false);
  });
});
