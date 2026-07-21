import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...a: unknown[]) => get(...a) } }));

import { useMerchantCompensationSummaryStore } from '@/stores/merchantCompensationSummaryStore';

const summary = {
  outstanding_liability_by_currency: [
    { currency: 'KES', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 300000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 50000, compensation_adjustment_minor: 0, combined_net_liability_minor: 350000 },
    { currency: 'USD', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 40000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 0, compensation_adjustment_minor: 0, combined_net_liability_minor: 40000 },
  ],
  paid_by_currency: [{ currency: 'KES', paid_gross_minor: 350000, run_count: 1 }],
  payout_runs_by_status: { draft: 2, paid: 1, approved: 0 },
  pending_high_value_approvals: 3,
};

describe('merchantCompensationSummaryStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('loads the server summary verbatim and keeps currencies separate', async () => {
    get.mockResolvedValueOnce({ data: { data: summary } });
    const store = useMerchantCompensationSummaryStore();
    await store.fetchSummary();
    expect(get).toHaveBeenCalledWith('/merchant/compensation-summary');
    expect(store.summary.outstanding_liability_by_currency).toHaveLength(2);
    expect(store.summary.outstanding_liability_by_currency[1].currency).toBe('USD');
    expect(store.summary.pending_high_value_approvals).toBe(3);
    expect(store.loaded).toBe(true);
  });

  it('marks a forbidden read', async () => {
    const err = new AxiosError('Forbidden');
    err.response = { status: 403, data: {}, statusText: '', headers: {}, config: {} as never };
    get.mockRejectedValueOnce(err);
    const store = useMerchantCompensationSummaryStore();
    await store.fetchSummary();
    expect(store.forbidden).toBe(true);
    expect(store.error).toBeNull();
  });

  it('surfaces a friendly error without leaking internals', async () => {
    get.mockRejectedValueOnce(new Error('violates unique constraint "x"'));
    const store = useMerchantCompensationSummaryStore();
    await store.fetchSummary();
    expect(store.error).toBe('Unable to load the compensation summary.');
    expect(store.error).not.toContain('constraint');
  });

  it('resets to an empty summary', () => {
    const store = useMerchantCompensationSummaryStore();
    store.summary.pending_high_value_approvals = 9;
    store.$reset();
    expect(store.summary.pending_high_value_approvals).toBe(0);
    expect(store.loaded).toBe(false);
  });
});
