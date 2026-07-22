import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const request = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
    request: (...a: unknown[]) => request(...a),
  },
}));

import { usePayoutRunStore } from '@/stores/payoutRunStore';

const run = {
  id: '01RUN00000000000000000000',
  branch_id: '01BRANCH00000000000000000',
  period_start: '2026-07-01',
  period_end: '2026-07-31',
  currency: 'KES',
  status: 'draft',
  gross_total_minor: 350000,
  high_value_threshold_snapshot_minor: null,
  is_high_value: false,
  rejection_reason: null,
  has_external_payment_reference: false,
  paid_at: null,
  item_count: 1,
  created_at: '2026-07-15T09:00:00+00:00',
  updated_at: '2026-07-15T09:00:00+00:00',
};

function paged<T>(rows: T[], page = 1, lastPage = 1) {
  return { data: { data: rows, meta: { current_page: page, last_page: lastPage, per_page: 25, total: rows.length } } };
}
function forbidden(): AxiosError {
  const err = new AxiosError('Forbidden');
  err.response = { status: 403, data: {}, statusText: 'Forbidden', headers: {}, config: {} as never };
  return err;
}

describe('payoutRunStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    request.mockReset();
  });

  it('lists runs from the role-owned route tree (hr/finance/merchant)', async () => {
    const store = usePayoutRunStore();
    get.mockResolvedValue(paged([run], 1, 2));
    await store.fetchRuns('hr', 1);
    expect(get).toHaveBeenCalledWith('/hr/payout-runs', { params: { page: 1 } });
    await store.fetchRuns('finance', 1);
    expect(get).toHaveBeenCalledWith('/finance/payout-runs', { params: { page: 1 } });
    await store.fetchRuns('merchant', 1);
    expect(get).toHaveBeenCalledWith('/merchant/payout-runs', { params: { page: 1 } });
    expect(store.runs).toHaveLength(1);
    expect(store.meta.last_page).toBe(2);
  });

  it('only sends non-empty filters and resets to page 1 on apply', async () => {
    const store = usePayoutRunStore();
    store.filters.status = 'submitted';
    store.filters.currency = 'kes';
    store.meta.current_page = 5;
    get.mockResolvedValue(paged([run]));
    await store.applyFilters('finance');
    expect(store.meta.current_page).toBe(1);
    const call = get.mock.calls.find((c) => c[0] === '/finance/payout-runs');
    expect(call?.[1].params).toMatchObject({ status: 'submitted', currency: 'KES', page: 1 });
    expect(call?.[1].params.branch_ulid).toBeUndefined();
  });

  it('creates a draft with only the four contract fields and NO idempotency key (branch mutation)', async () => {
    const store = usePayoutRunStore();
    post.mockResolvedValueOnce({ data: { data: run } });
    await store.createDraft({ branch_ulid: '01BRANCH00000000000000000', period_start: '2026-07-01', period_end: '2026-07-31', currency: 'KES' });
    const [url, body, config] = post.mock.calls[0];
    expect(url).toBe('/hr/payout-runs');
    expect(Object.keys(body).sort()).toEqual(['branch_ulid', 'currency', 'period_end', 'period_start']);
    for (const f of ['gross_total_minor', 'status', 'items', 'payout_item_ids', 'merchant_id']) expect(body).not.toHaveProperty(f);
    expect(config).toBeUndefined();
  });

  it('submits and cancels a draft via HR routes', async () => {
    const store = usePayoutRunStore();
    post.mockResolvedValue({ data: { data: { ...run, status: 'submitted' } } });
    await store.submitDraft(run.id);
    expect(post).toHaveBeenCalledWith(`/hr/payout-runs/${run.id}/submit`);
    await store.cancelDraft(run.id);
    expect(post).toHaveBeenCalledWith(`/hr/payout-runs/${run.id}/cancel`);
  });

  it('verify/approve carry an Idempotency-Key on the financial-mutation request', async () => {
    const store = usePayoutRunStore();
    request.mockResolvedValue({ data: { data: { ...run, status: 'finance_verified' } } });
    await store.verify(run.id);
    const verifyCall = request.mock.calls[0][0];
    expect(verifyCall.url).toBe(`/finance/payout-runs/${run.id}/verify`);
    expect(typeof verifyCall.headers['Idempotency-Key']).toBe('string');
    await store.approve(run.id);
    expect(request.mock.calls[1][0].url).toBe(`/finance/payout-runs/${run.id}/approve`);
  });

  it('reject sends the reason; mark-paid sends external reference + paid date, all with Idempotency-Key', async () => {
    const store = usePayoutRunStore();
    request.mockResolvedValue({ data: { data: run } });
    await store.reject(run.id, 'redo the draft');
    expect(request.mock.calls[0][0].data).toEqual({ reason: 'redo the draft' });
    await store.markPaid(run.id, { external_payment_reference: 'MPESA-1', paid_date: '2026-07-15' });
    const mp = request.mock.calls[1][0];
    expect(mp.url).toBe(`/finance/payout-runs/${run.id}/mark-paid`);
    expect(mp.data).toEqual({ external_payment_reference: 'MPESA-1', paid_date: '2026-07-15' });
    expect(typeof mp.headers['Idempotency-Key']).toBe('string');
  });

  it('reuses the Idempotency-Key on a same-payload retry and mints a new one when the payload changes', async () => {
    const store = usePayoutRunStore();
    request.mockRejectedValueOnce(new AxiosError('network'));
    await expect(store.markPaid(run.id, { external_payment_reference: 'R1', paid_date: '2026-07-15' })).rejects.toBeTruthy();
    const firstKey = request.mock.calls[0][0].headers['Idempotency-Key'];
    request.mockResolvedValueOnce({ data: { data: run } });
    await store.markPaid(run.id, { external_payment_reference: 'R1', paid_date: '2026-07-15' });
    expect(request.mock.calls[1][0].headers['Idempotency-Key']).toBe(firstKey);
    request.mockResolvedValueOnce({ data: { data: run } });
    await store.markPaid(run.id, { external_payment_reference: 'R2', paid_date: '2026-07-15' });
    expect(request.mock.calls[2][0].headers['Idempotency-Key']).not.toBe(firstKey);
  });

  it('approveHighValue posts to the merchant route with an Idempotency-Key', async () => {
    const store = usePayoutRunStore();
    request.mockResolvedValueOnce({ data: { data: { ...run, status: 'approved' } } });
    await store.approveHighValue(run.id);
    expect(request.mock.calls[0][0].url).toBe(`/merchant/payout-runs/${run.id}/approve-high-value`);
    expect(typeof request.mock.calls[0][0].headers['Idempotency-Key']).toBe('string');
  });

  it('marks a forbidden read and never leaks a constraint name on error', async () => {
    const store = usePayoutRunStore();
    get.mockRejectedValueOnce(forbidden());
    await store.fetchRuns('hr');
    expect(store.forbidden).toBe(true);
    get.mockRejectedValueOnce(new Error('duplicate key value violates unique constraint "x"'));
    await store.fetchRuns('finance');
    expect(store.listError).toBe('Unable to load payout runs.');
    expect(store.listError).not.toContain('constraint');
  });

  it('clears stale context on reset', async () => {
    const store = usePayoutRunStore();
    get.mockResolvedValue(paged([run]));
    await store.fetchRuns('hr');
    store.filters.currency = 'KES';
    store.$reset();
    expect(store.runs).toHaveLength(0);
    expect(store.filters.currency).toBe('');
    expect(store.forbidden).toBe(false);
  });
});
