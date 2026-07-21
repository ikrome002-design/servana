import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) } }));

import { useEarningsQueryStore } from '@/stores/earningsQueryStore';

const query = {
  id: '01QUERY000000000000000000',
  staff_profile_id: '01STAFF000000000000000000',
  subject_type: 'commission_ledger',
  query_type: 'commission_disagreement',
  body: 'short by 500',
  status: 'open',
  assigned_role: 'finance',
  resolution_note: null,
  resolved_adjustment_id: null,
  responded_at: null,
  created_at: '2026-07-15T09:00:00+00:00',
  updated_at: '2026-07-15T09:00:00+00:00',
};

function paged<T>(rows: T[]) {
  return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } };
}

describe('earningsQueryStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lists personnel own queries and the finance responder queue from distinct route trees', async () => {
    const store = useEarningsQueryStore();
    get.mockResolvedValue(paged([query]));
    await store.fetchQueries('personnel', 1);
    expect(get).toHaveBeenCalledWith('/personnel/me/earnings-queries', { params: { page: 1 } });
    store.statusFilter = 'open';
    await store.fetchQueries('finance', 1);
    expect(get).toHaveBeenCalledWith('/finance/earnings-queries', { params: { page: 1, status: 'open' } });
  });

  it('creates a personnel own-scope query with only the contract fields', async () => {
    const store = useEarningsQueryStore();
    post.mockResolvedValueOnce({ data: { data: query } });
    await store.createQuery({ subject_type: 'commission_ledger', subject_ulid: '01LEDGER00000000000000000', query_type: 'commission_disagreement', body: 'short by 500' });
    const [url, body] = post.mock.calls[0];
    expect(url).toBe('/personnel/me/earnings-queries');
    expect(Object.keys(body).sort()).toEqual(['body', 'query_type', 'subject_type', 'subject_ulid']);
    for (const f of ['staff_profile_id', 'status', 'assigned_role', 'merchant_id']) expect(body).not.toHaveProperty(f);
  });

  it('responds with an Idempotency-Key; a correction is a nested additive adjustment payload', async () => {
    const store = useEarningsQueryStore();
    post.mockResolvedValue({ data: { data: { ...query, status: 'resolved' } } });
    await store.respond(query.id, { decision: 'resolved', resolution_note: 'agreed', correction: { amount_minor: 500, currency: 'KES', reason: 'shortfall' } });
    const [url, body, config] = post.mock.calls[0];
    expect(url).toBe(`/finance/earnings-queries/${query.id}/respond`);
    expect(body.correction).toEqual({ amount_minor: 500, currency: 'KES', reason: 'shortfall' });
    expect(typeof config.headers['Idempotency-Key']).toBe('string');
  });

  it('reuses the respond Idempotency-Key on a same-payload retry and remints on change', async () => {
    const store = useEarningsQueryStore();
    post.mockRejectedValueOnce(new AxiosError('network'));
    const payload = { decision: 'resolved' as const, resolution_note: 'once', correction: { amount_minor: 250, currency: 'KES', reason: 'x' } };
    await expect(store.respond(query.id, payload)).rejects.toBeTruthy();
    const firstKey = post.mock.calls[0][2].headers['Idempotency-Key'];
    post.mockResolvedValueOnce({ data: { data: query } });
    await store.respond(query.id, payload);
    expect(post.mock.calls[1][2].headers['Idempotency-Key']).toBe(firstKey);
    post.mockResolvedValueOnce({ data: { data: query } });
    await store.respond(query.id, { ...payload, resolution_note: 'changed' });
    expect(post.mock.calls[2][2].headers['Idempotency-Key']).not.toBe(firstKey);
  });

  it('rejects a query without a correction payload', async () => {
    const store = useEarningsQueryStore();
    post.mockResolvedValueOnce({ data: { data: { ...query, status: 'rejected' } } });
    await store.respond(query.id, { decision: 'rejected', resolution_note: 'not upheld' });
    expect(post.mock.calls[0][1]).toEqual({ decision: 'rejected', resolution_note: 'not upheld' });
  });

  it('marks a forbidden read and clears stale data on reset', async () => {
    const err = new AxiosError('Forbidden');
    err.response = { status: 403, data: {}, statusText: '', headers: {}, config: {} as never };
    get.mockRejectedValueOnce(err);
    const store = useEarningsQueryStore();
    await store.fetchQueries('finance');
    expect(store.forbidden).toBe(true);
    store.$reset();
    expect(store.queries).toHaveLength(0);
    expect(store.forbidden).toBe(false);
  });
});
