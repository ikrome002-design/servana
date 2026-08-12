import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useMerchantStaffOverviewStore, type MerchantStaffOverviewRow } from './merchantStaffOverviewStore';

const row: MerchantStaffOverviewRow = {
  id: 'membership-1',
  staff_profile_id: 'staff-1',
  display_name: 'Branch Owner',
  email: 'owner@example.test',
  role: 'branch_manager',
  status: 'active',
  account_status: 'active',
  activated_at: null,
  last_login_at: null,
  branches: [{ id: 'branch-1', name: 'Kilimani', code: 'KIL' }],
  active_session_count: 2,
  assignment_history: [],
  status_history: [],
  can: { manage_lifecycle: true },
};

describe('merchantStaffOverviewStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('sends bounded safe filters and reads pagination metadata', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [row], meta: { total: 1, current_page: 1, last_page: 1 } } } as never);
    const store = useMerchantStaffOverviewStore();

    await store.fetchRows({ search: 'Branch Owner', role: 'branch_manager', page: 1 });

    expect(apiClient.get).toHaveBeenCalledWith('/merchant/staff-overview?search=Branch+Owner&role=branch_manager&page=1');
    expect(store.rows[0]).not.toHaveProperty('phone');
    expect(store.total).toBe(1);
  });

  it('posts the lifecycle reason to the existing authorized endpoint', async () => {
    vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { data: {} } } as never);
    const store = useMerchantStaffOverviewStore();

    await store.lifecycle(row, 'suspend', 'Owner access review');

    expect(apiClient.post).toHaveBeenCalledWith('/staff/staff-1/suspend', { reason: 'Owner access review' });
  });

  it('rejects malformed rows without coercing missing history or session state', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [{ id: 'bad' }], meta: { total: 1, current_page: 1, last_page: 1 } } } as never);
    const store = useMerchantStaffOverviewStore();

    await store.fetchRows();

    expect(store.rows).toEqual([]);
    expect(store.error).toContain('staff lifecycle directory');
  });
});
