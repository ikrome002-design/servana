import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { apiClient } from '@/services/apiClient';
import { useFrontOfficeWorkspaceStore } from './frontOfficeWorkspaceStore';

describe('UI-13 Front Office workspace store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('deduplicates concurrent workspace reads', async () => {
    const get = vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: { data: { overview: { branch: { id: 'branch-1', name: 'Westlands', code: 'WST', town: 'Nairobi' } } } },
    });
    const store = useFrontOfficeWorkspaceStore();
    await Promise.all([store.fetchOverview(), store.fetchOverview()]);
    expect(get).toHaveBeenCalledTimes(1);
    expect(get).toHaveBeenCalledWith('/front-office/workspace');
    expect(store.overview?.branch.name).toBe('Westlands');
  });

  it('keeps payment status and activity pagination independent', async () => {
    const get = vi.spyOn(apiClient, 'get').mockImplementation((url: string) => Promise.resolve({
      data: { data: url.endsWith('activity') ? [{ id: 'event-1' }] : [{ id: 'group-1' }], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } },
    }) as never);
    const store = useFrontOfficeWorkspaceStore();
    await Promise.all([store.fetchActivity({ domain: 'queue' }), store.fetchPaymentStatus({ status: 'pending_validation' })]);
    expect(store.activity).toHaveLength(1);
    expect(store.paymentStatuses).toHaveLength(1);
    expect(get).toHaveBeenCalledWith('/front-office/activity', { params: { domain: 'queue', sort: '-created_at', per_page: 20 } });
    expect(get).toHaveBeenCalledWith('/front-office/payment-status', { params: { status: 'pending_validation', sort: '-recorded_at', per_page: 20 } });
  });
});
