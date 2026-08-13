import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useHrWorkspaceStore, type HrWorkspaceOverview } from './hrWorkspaceStore';

const HR_OVERVIEW: HrWorkspaceOverview = {
  branch: { id: 'branch-1', name: 'Westlands Studio', code: 'WST', town: 'Nairobi' },
  staff: { total: 8, active: 6, by_access_status: { active: 6, invited: 1, suspended: 1 }, pending_invitations: 1 },
  readiness: { eligible_staff: 5, without_eligibility: 1, available_staff: 4, without_availability: 2, configured_compensation: 3, without_compensation: 3 },
  compensation: { by_status: { active: 3, draft: 2 }, drafts_requiring_action: 2 },
  payouts: { by_status: { draft: 1, submitted: 2 }, awaiting_finance: 2 },
  tasks: [{ key: 'eligibility-gaps', label: 'Active staff without service eligibility', count: 1, route_name: 'hr.eligibility' }],
  get_started: { staff_invited: true, eligibility_configured: true, availability_configured: true, compensation_configured: true, missing_compensation_reviewed: false },
  reports: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
  notifications: { available: false, reason: 'Phase 21N is blocked by External Gate W' },
};

describe('hrWorkspaceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('loads only the server-owned branch workspace read model', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: HR_OVERVIEW } } });
    const store = useHrWorkspaceStore();

    await store.fetchOverview();

    expect(apiClient.get).toHaveBeenCalledWith('/hr/workspace');
    expect(store.overview?.branch.name).toBe('Westlands Studio');
    expect(store.overview?.payouts.awaiting_finance).toBe(2);
    expect(store.overview).not.toHaveProperty('earnings');
  });

  it('uses validated paginated server filters for the masked HR timeline', async () => {
    const event = { id: 'event-1', action: 'membership.suspended', severity: 'warning', actor: 'p***@example.test', subject_type: 'MerchantUser', context: { reason: 'masked' }, created_at: '2026-08-13T05:00:00Z' };
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [event], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    const store = useHrWorkspaceStore();

    await store.fetchAudit({ domain: 'staff', sort: '-created_at', per_page: 20 });

    expect(apiClient.get).toHaveBeenCalledWith('/hr/audit-activity', { params: { domain: 'staff', sort: '-created_at', per_page: 20 } });
    expect(store.auditEvents[0]?.actor).toBe('p***@example.test');
    expect(store.auditMeta?.total).toBe(1);
  });
});
