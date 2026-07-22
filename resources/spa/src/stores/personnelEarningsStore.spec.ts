import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) } }));

import { usePersonnelEarningsStore } from '@/stores/personnelEarningsStore';

const overview = {
  tab_visibility: { model: 'salary_plus_commission', has_current_plan: true, conflicting: false, salary_tab: true, commission_tab: true },
  currencies: [
    { currency: 'KES', salary_unpaid_minor: 300000, salary_paid_minor: 0, commission_unpaid_minor: 50000, commission_paid_minor: 0, adjustment_unpaid_minor: 0, adjustment_paid_minor: 0, unpaid_minor: 350000, paid_minor: 0, net_minor: 350000 },
  ],
};
const item = { id: '01ITEM0000000000000000000', staff_profile_id: null, staff_display_name: null, payout_run_id: null, currency: 'KES', salary_amount_minor: 0, commission_amount_minor: 50000, adjustment_amount_minor: 0, gross_amount_minor: 50000, status: 'paid', source_counts: { salary: 0, commission: 1, adjustment: 0 }, has_statement: false, statement_file_id: null, created_at: '2026-07-15T09:00:00+00:00' };

describe('personnelEarningsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('loads the own-scope overview without ever sending a staff reference', async () => {
    get.mockResolvedValueOnce({ data: { data: overview } });
    const store = usePersonnelEarningsStore();
    await store.fetchOverview();
    expect(get).toHaveBeenCalledWith('/personnel/me/earnings');
    // No params object with a staff id is ever attached to the own-scope read.
    expect(get.mock.calls[0][1]).toBeUndefined();
    expect(store.overview.currencies[0].currency).toBe('KES');
    expect(store.overview.tab_visibility.salary_tab).toBe(true);
  });

  it('loads compensation terms and own payout history', async () => {
    const store = usePersonnelEarningsStore();
    get.mockResolvedValueOnce({ data: { data: { has_current_plan: true, conflicting: false, compensation_model: 'salary_plus_commission' } } });
    await store.fetchTerms();
    expect(get).toHaveBeenCalledWith('/personnel/me/compensation');
    expect(store.terms?.compensation_model).toBe('salary_plus_commission');

    get.mockResolvedValueOnce({ data: { data: [item], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } } });
    await store.fetchPayouts(1);
    expect(get).toHaveBeenCalledWith('/personnel/me/payouts', { params: { page: 1 } });
    expect(store.payouts[0].id).toBe(item.id);
  });

  it('generates a statement and returns the signed download link', async () => {
    post.mockResolvedValueOnce({ data: { data: { statement: { id: '01FILE000000000000000000', filename: 'earnings-statement.pdf', mime_type: 'application/pdf', size_bytes: 1000, generated_at: '2026-07-16T00:00:00+00:00' }, download: { url: '/api/v1/files/01FILE000000000000000000/download?sig=x', expires_at: '2026-07-16T00:05:00+00:00' } } } });
    const store = usePersonnelEarningsStore();
    const result = await store.generateStatement(item.id);
    expect(post).toHaveBeenCalledWith(`/personnel/me/payout-items/${item.id}/statement`);
    expect(result.download.url).toContain('/files/');
    expect(result.statement.mime_type).toBe('application/pdf');
  });

  it('marks a forbidden read and clears stale data on reset', async () => {
    const err = new AxiosError('Forbidden');
    err.response = { status: 403, data: {}, statusText: '', headers: {}, config: {} as never };
    get.mockRejectedValueOnce(err);
    const store = usePersonnelEarningsStore();
    await store.fetchOverview();
    expect(store.forbidden).toBe(true);
    store.$reset();
    expect(store.overview.currencies).toHaveLength(0);
    expect(store.forbidden).toBe(false);
  });
});
