import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useMerchantDashboardStore, type MerchantDashboardOverview } from './merchantDashboardStore';

const overview: MerchantDashboardOverview = {
  subscription: null,
  billing: { next_invoice: null, outstanding_by_currency: [], payment_runtime: { available: false, reason: 'External Gate W' } },
  branches: { total: 2, active: 2, suspended: 0, archived: 0, limit: 3, remaining_capacity: 1 },
  staff: { active: 4, invited: 0, suspended: 0, deactivated: 0, pending_owner_invitations: 0 },
  get_started: {
    setup_complete: true,
    subscription_selected: true,
    profile_complete: true,
    logo_uploaded: false,
    billing_phone_confirmed: true,
    first_branch_created: true,
    initial_team_invited: true,
    initial_team_active: true,
    operational_roles_active: false,
    daily_reports: { available: false, reason: 'External Gate W' },
  },
  compensation: null,
  reporting: { available: false, reason: 'External Gate W', omitted_metrics: ['validated_revenue'] },
};

describe('merchantDashboardStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('loads the server-owned overview without manufacturing gated metrics', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview } } } as never);
    const store = useMerchantDashboardStore();

    await store.fetchOverview();

    expect(apiClient.get).toHaveBeenCalledWith('/merchant/dashboard');
    expect(store.overview?.branches.total).toBe(2);
    expect(store.overview?.reporting.available).toBe(false);
    expect(store.overview).not.toHaveProperty('validated_revenue_minor');
  });

  it('turns a malformed response into a retryable error instead of crashing the page', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: { branches: [] } } } } as never);
    const store = useMerchantDashboardStore();

    await store.fetchOverview();

    expect(store.overview).toBeNull();
    expect(store.error).toContain('load the ownership overview');
  });
});
