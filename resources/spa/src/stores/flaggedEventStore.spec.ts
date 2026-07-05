import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { useFlaggedEventStore } from '@/stores/flaggedEventStore';

const flagged = {
  id: 'f1',
  status: 'open' as const,
  review_notes: null,
  assigned_to: null,
  resolved_by: null,
  created_at: '2026-07-05T00:00:00Z',
  updated_at: '2026-07-05T00:00:00Z',
  audit_event: { id: 'a1', action: 'invoice.created', severity: 'info', actor: 'j***@x.co', subject_type: 'Invoice', context: {}, occurred_at: null },
  can: { update_status: true, resolve_metadata: false },
};

describe('flaggedEventStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lists flagged events with the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [flagged] } });
    const store = useFlaggedEventStore();
    store.filterStatus = 'under_review';

    await store.fetchAll();

    expect(get).toHaveBeenCalledWith('/audit-flagged-events', { params: { sort: '-created_at', status: 'under_review' } });
    expect(store.items).toEqual([flagged]);
  });

  it('flags an audit event with the note field (not review_notes)', async () => {
    post.mockResolvedValueOnce({ data: { data: flagged } });
    const store = useFlaggedEventStore();

    await store.flag({ audit_log: 'a1', note: 'looks off' });

    expect(post).toHaveBeenCalledWith('/audit-flagged-events', { audit_log: 'a1', note: 'looks off' });
  });

  it('drives each lifecycle transition to its own route', async () => {
    post.mockResolvedValue({ data: { data: flagged } });
    const store = useFlaggedEventStore();

    await store.transition('f1', 'start-review');
    expect(post).toHaveBeenLastCalledWith('/audit-flagged-events/f1/start-review', {});

    await store.transition('f1', 'resolve', { review_notes: 'done' });
    expect(post).toHaveBeenLastCalledWith('/audit-flagged-events/f1/resolve', { review_notes: 'done' });

    await store.transition('f1', 'dismiss', { review_notes: 'n/a' });
    expect(post).toHaveBeenLastCalledWith('/audit-flagged-events/f1/dismiss', { review_notes: 'n/a' });

    await store.transition('f1', 'reopen');
    expect(post).toHaveBeenLastCalledWith('/audit-flagged-events/f1/reopen', {});
  });

  it('reports a load error without throwing', async () => {
    get.mockRejectedValueOnce(new Error('x'));
    const store = useFlaggedEventStore();
    await store.fetchAll();
    expect(store.error).toBe('Unable to load flagged events.');
  });
});
