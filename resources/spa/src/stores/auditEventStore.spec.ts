import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import { useAuditEventStore } from '@/stores/auditEventStore';

const row = {
  id: 'a1',
  action: 'invoice.created',
  severity: 'info',
  actor: 'j***@salon.co.ke',
  branch: 'b1',
  subject_type: 'Invoice',
  context: { amount: '***' },
  correlation_id: 'c1',
  created_at: '2026-07-05T00:00:00Z',
  can: { view: true },
};

describe('auditEventStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('reads the GENERAL segment endpoint and parses page meta', async () => {
    get.mockResolvedValueOnce({ data: { data: [row], meta: { current_page: 2, last_page: 5, total: 120 } } });
    const store = useAuditEventStore();

    await store.fetchEvents('general', 2);

    expect(get).toHaveBeenCalledWith('/audit-logs', { params: expect.objectContaining({ page: 2, sort: '-created_at' }) });
    expect(store.events).toEqual([row]);
    expect(store.meta).toEqual({ current_page: 2, last_page: 5, total: 120 });
  });

  it('routes finance and compensation domains to their own segment endpoints', async () => {
    get.mockResolvedValue({ data: { data: [] } });
    const store = useAuditEventStore();

    await store.fetchEvents('finance', 1);
    expect(get).toHaveBeenLastCalledWith('/audit-logs/finance', expect.anything());

    await store.fetchEvents('compensation', 1);
    expect(get).toHaveBeenLastCalledWith('/audit-logs/compensation', expect.anything());
  });

  it('only sends allowlisted, non-empty filter params (no merchant-level toggles)', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const store = useAuditEventStore();
    store.filters = { sort: '-created_at', severity: 'high', action: '', date_from: '2026-07-01' };

    await store.fetchEvents('general', 1);

    const params = get.mock.calls[0][1].params;
    expect(params).toMatchObject({ severity: 'high', date_from: '2026-07-01', sort: '-created_at', page: 1 });
    expect(params).not.toHaveProperty('action'); // empty filters are dropped
    expect(params).not.toHaveProperty('branch_id'); // never a merchant-level bypass
  });

  it('fetches a single event detail', async () => {
    get.mockResolvedValueOnce({ data: { data: row } });
    const store = useAuditEventStore();

    await store.fetchEvent('a1');

    expect(get).toHaveBeenCalledWith('/audit-logs/a1');
    expect(store.current).toEqual(row);
  });

  it('surfaces a friendly error and never throws to the caller', async () => {
    get.mockRejectedValueOnce(new Error('500'));
    const store = useAuditEventStore();

    await store.fetchEvents('general', 1);

    expect(store.error).toBe('Unable to load audit events.');
    expect(store.loading).toBe(false);
  });
});
