import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { useAuditExportStore, isTerminal } from '@/stores/auditExportStore';

const exp = {
  id: 'e1',
  branch: { id: 'b1', name: 'Westlands' },
  status: 'ready' as const,
  reason: 'quarterly review',
  scope: { domains: ['finance'], severities: [], has_date_from: false, has_date_to: false },
  row_count: 12,
  download_count: 0,
  requested_at: '2026-07-05T00:00:00Z',
  generated_at: '2026-07-05T00:01:00Z',
  expires_at: '2026-07-12T00:00:00Z',
  first_downloaded_at: null,
  last_downloaded_at: null,
  failure_code: null,
  failure_message: null,
  created_at: '2026-07-05T00:00:00Z',
  can: { view: true, download: true, revoke: true },
};

describe('auditExportStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('classifies terminal vs in-flight statuses for polling', () => {
    expect(isTerminal('queued')).toBe(false);
    expect(isTerminal('processing')).toBe(false);
    expect(isTerminal('ready')).toBe(true);
    expect(isTerminal('failed')).toBe(true);
    expect(isTerminal('expired')).toBe(true);
    expect(isTerminal('revoked')).toBe(true);
  });

  it('requests a branch-scoped export with reason + allowlisted filters (no idempotency header)', async () => {
    post.mockResolvedValueOnce({ data: { data: exp } });
    const store = useAuditExportStore();

    await store.request({ branch: 'b1', reason: 'quarterly review', domains: ['finance'], severities: ['high'] });

    expect(post).toHaveBeenCalledWith('/audit-exports', {
      branch: 'b1',
      reason: 'quarterly review',
      domains: ['finance'],
      severities: ['high'],
    });
    // The store must not invent an Idempotency-Key the backend route does not enforce.
    expect(post.mock.calls[0][2]).toBeUndefined();
  });

  it('requests a short-lived signed link on demand and returns it WITHOUT storing it', async () => {
    post.mockResolvedValueOnce({ data: { data: { url: 'https://signed/x?sig=abc', expires_at: 'soon' } } });
    const store = useAuditExportStore();

    const url = await store.downloadLink('e1');

    expect(post).toHaveBeenCalledWith('/audit-exports/e1/download-link', {});
    expect(url).toBe('https://signed/x?sig=abc');
    // The signed URL is returned to the caller, never persisted in store state.
    expect(JSON.stringify(store.$state)).not.toContain('signed/x');
  });

  it('revokes an export and updates current', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...exp, status: 'revoked' } } });
    const store = useAuditExportStore();

    const result = await store.revoke('e1');

    expect(post).toHaveBeenCalledWith('/audit-exports/e1/revoke', {});
    expect(result.status).toBe('revoked');
    expect(store.current?.status).toBe('revoked');
  });

  it('lists exports with a status filter and reports errors without throwing', async () => {
    get.mockResolvedValueOnce({ data: { data: [exp] } });
    const store = useAuditExportStore();
    store.filterStatus = 'ready';
    await store.fetchExports();
    expect(get).toHaveBeenCalledWith('/audit-exports', { params: { sort: '-created_at', status: 'ready' } });

    get.mockRejectedValueOnce(new Error('x'));
    await store.fetchExports();
    expect(store.error).toBe('Unable to load audit exports.');
  });
});
