import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';

const LIVE_FINANCE_ROUTES = {
  'finance.dashboard': '/dashboard',
  'finance.get-started': '/get-started',
  'finance.tasks': '/tasks',
  'finance.payments-validations': '/payments/validations',
  'finance.payments-validation-detail': '/payments/validations/:groupUlid',
  'finance.payments-duplicates': '/payments/duplicates',
  'finance.invoices': '/invoices',
  'finance.payments': '/payments',
  'finance.payments-partial-split': '/payments/partial-split',
  'finance.receipts': '/receipts',
  'finance.disputes': '/disputes',
  'finance.refunds': '/refunds',
  'finance.cash-up': '/cash-up',
  'finance.periods': '/periods',
  'finance.payouts': '/payouts',
  'finance.compensation-liabilities': '/compensation/liabilities',
  'finance.compensation-queries': '/compensation/queries',
  'finance.exports': '/exports',
  'finance.audit': '/audit',
  'finance.settings': '/settings',
} as const;

describe('UI-12 Finance canonical routes', () => {
  it('registers exactly the twenty live contract destinations at host-relative paths', () => {
    const router = createAppRouter('merchant_finance');
    for (const [name, path] of Object.entries(LIVE_FINANCE_ROUTES)) {
      expect(router.hasRoute(name), name).toBe(true);
      const route = router.getRoutes().find((candidate) => candidate.name === name);
      expect(route?.path, name).toBe(path);
      expect(route?.meta.screenKey, name).toBeTruthy();
    }
  });

  it('keeps the four External Gate W destinations unregistered', () => {
    const router = createAppRouter('merchant_finance');
    for (const name of [
      'finance.subscription',
      'finance.subscription-payment-attempts',
      'finance.reports',
      'finance.notifications',
    ]) {
      expect(router.hasRoute(name), name).toBe(false);
    }
  });

  it('does not retain aliases for superseded or removed Finance ownership', () => {
    const router = createAppRouter('merchant_finance');
    for (const name of [
      'finance.pending-validations',
      'finance.payment-records',
      'finance.payment-records.detail',
      'finance.platform-fees',
      'finance.liabilities',
      'finance.payout-runs',
      'finance.earnings-queries',
    ]) {
      expect(router.hasRoute(name), name).toBe(false);
    }
  });

  it('does not register the Finance account tree on another account host', () => {
    const router = createAppRouter('merchant_human_resource');
    expect(router.hasRoute('finance.dashboard')).toBe(false);
    expect(router.hasRoute('hr.dashboard')).toBe(true);
  });
});
