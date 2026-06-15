import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    put: (...a: unknown[]) => put(...a),
  },
}));

import { useBranchStore } from '@/stores/branchStore';

const branch = {
  id: 'b1',
  name: 'Kilimani',
  code: 'KIL001',
  address: null,
  town: 'Nairobi',
  phone: null,
  email: null,
  business_category: null,
  status: 'active' as const,
  status_reason: null,
  archived_at: null,
};

describe('branchStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
  });

  it('fetches branches', async () => {
    get.mockResolvedValueOnce({ data: { data: [branch] } });
    const store = useBranchStore();

    await store.fetchBranches();

    expect(get).toHaveBeenCalledWith('/branches');
    expect(store.branches).toHaveLength(1);
    expect(store.branches[0]?.name).toBe('Kilimani');
  });

  it('creates a branch and appends it', async () => {
    post.mockResolvedValueOnce({ data: { data: branch } });
    const store = useBranchStore();

    const created = await store.createBranch({ name: 'Kilimani', code: 'KIL001' });

    expect(post).toHaveBeenCalledWith('/branches', { name: 'Kilimani', code: 'KIL001' });
    expect(created.id).toBe('b1');
    expect(store.branches).toHaveLength(1);
  });

  it('fetches and saves operating hours', async () => {
    const hours = [{ weekday: 1, opens_at: '08:00', closes_at: '18:00', is_closed: false, break_start: null, break_end: null }];
    get.mockResolvedValueOnce({ data: { data: hours } });
    put.mockResolvedValueOnce({ data: { data: hours } });
    const store = useBranchStore();

    await store.fetchOperatingHours('b1');
    expect(get).toHaveBeenCalledWith('/branches/b1/operating-hours');
    expect(store.operatingHours).toHaveLength(1);

    await store.saveOperatingHours('b1', hours);
    expect(put).toHaveBeenCalledWith('/branches/b1/operating-hours', { hours });
  });
});
