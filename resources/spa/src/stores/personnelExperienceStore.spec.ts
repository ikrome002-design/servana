import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { usePersonnelExperienceStore } from './personnelExperienceStore';

vi.mock('@/services/apiClient', () => ({ apiClient: { get: vi.fn() } }));
const get = vi.mocked(apiClient.get);
const page = { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } };

describe('Phase UI-14 Personnel experience store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    get.mockResolvedValue({ data: page } as never);
  });

  it('uses only own-scope endpoint paths and never sends a staff selector', async () => {
    const store = usePersonnelExperienceStore();
    await store.fetchHistory();
    await store.fetchPreferredRequests();
    await store.fetchClients();
    await store.fetchCommissions();
    await store.fetchSalary();

    const serialized = JSON.stringify(get.mock.calls);
    expect(serialized).toContain('/personnel/me/service-history');
    expect(serialized).toContain('/personnel/me/preferred-requests');
    expect(serialized).toContain('/personnel/me/served-clients');
    expect(serialized).toContain('/personnel/me/commissions');
    expect(serialized).toContain('/personnel/me/salary');
    expect(serialized).not.toContain('staff_profile');
    expect(serialized).not.toContain('phone');
  });

  it('keeps the availability operation read-only', async () => {
    get.mockResolvedValueOnce({ data: { data: { can: { update: false } } } } as never);
    const store = usePersonnelExperienceStore();
    await store.fetchAvailability();

    expect(get).toHaveBeenCalledWith('/personnel/me/availability');
    expect(store.availability?.can.update).toBe(false);
    expect(store).not.toHaveProperty('updateAvailability');
    expect(store).not.toHaveProperty('setLiveStatus');
  });

  it('persists no client or earnings projection in browser storage', async () => {
    localStorage.clear();
    sessionStorage.clear();
    const store = usePersonnelExperienceStore();
    await store.fetchClients();
    await store.fetchCommissions();
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });

  it('treats a workspace payload without the contract shape as a load error, never as data', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } } as never);
    const store = usePersonnelExperienceStore();
    await store.fetchWorkspace(true);
    expect(store.workspace).toBeNull();
    expect(store.errors.workspace).toBe('Unable to load your workspace.');
  });
});
