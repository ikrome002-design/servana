import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CompensationHistory from './CompensationHistory.vue';
import HrAuditActivity from './HrAuditActivity.vue';
import HrDashboard from './HrDashboard.vue';
import { apiClient } from '@/services/apiClient';
import type { HrWorkspaceOverview } from '@/stores/hrWorkspaceStore';

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

const routeNames = ['hr.staff-invite', 'hr.staff', 'hr.eligibility', 'hr.availability', 'hr.compensation', 'hr.payouts'];

async function routerAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'hr.dashboard', component: { template: '<div />' } },
      { path: '/compensation/:staffUlid/history', name: 'hr.compensation-history', component: { template: '<div />' } },
      ...routeNames.map((name, index) => ({ path: `/target-${index}`, name, component: { template: '<div />' } })),
    ],
  });
  await router.push(path);
  await router.isReady();
  return router;
}

describe('UI-11 Human Resource experience pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('renders truthful branch readiness and preserves the payout maker/checker handoff', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: HR_OVERVIEW } } });
    const wrapper = mount(HrDashboard, { global: { plugins: [await routerAt('/dashboard')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Westlands Studio');
    expect(wrapper.text()).toContain('Eligibility gaps');
    expect(wrapper.text()).toContain('2 awaiting Finance');
    expect(wrapper.text()).toContain('Human Resource drafts and submits');
    expect(wrapper.text()).not.toContain('Approve payout');
    expect(wrapper.text()).not.toContain('Earnings');
  });

  it('renders only masked HR event context and explicitly denies export scope', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({
      data: {
        data: [{ id: 'event-1', action: 'membership.suspended', severity: 'warning', actor: 'p***@example.test', subject_type: 'MerchantUser', context: { reason: 'Policy review' }, created_at: '2026-08-13T05:00:00Z' }],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
      },
    });
    const wrapper = mount(HrAuditActivity);
    await flushPromises();

    expect(apiClient.get).toHaveBeenCalledWith('/hr/audit-activity', { params: { sort: '-created_at', page: 1, per_page: 20 } });
    expect(wrapper.text()).toContain('membership suspended');
    expect(wrapper.text()).toContain('p***@example.test');
    expect(wrapper.text()).toContain('Human Resource cannot export client or payment data');
    expect(wrapper.text()).not.toContain('private@example.test');
  });

  it('renders the canonical staff compensation history from append-only server events', async () => {
    vi.spyOn(apiClient, 'get').mockImplementation(async (url: string) => {
      if (url === '/staff/staff-1') {
        return { data: { data: { id: 'staff-1', display_name: 'Jane Doe' } } };
      }
      if (url === '/compensation-plans') {
        return { data: { data: [{ id: 'plan-1' }] } };
      }
      if (url === '/compensation-plans/plan-1/history') {
        return {
          data: {
            data: [{
              id: 'event-1',
              event: 'approved',
              event_label: 'Approved',
              from_status: 'pending_approval',
              to_status: 'active',
              changed_fields: null,
              was_backdated: false,
              change_reason: 'Annual review',
              effective_from: '2026-08-01',
              actor_display_name: 'Ada HR',
              occurred_at: '2026-08-13T05:00:00Z',
            }],
          },
        };
      }
      throw new Error(`Unexpected request: ${url}`);
    });

    const wrapper = mount(CompensationHistory, {
      global: { plugins: [await routerAt('/compensation/staff-1/history')] },
    });
    await flushPromises();

    expect(apiClient.get).toHaveBeenCalledWith('/compensation-plans', {
      params: { staff_profile_id: 'staff-1', per_page: 100, sort: '-created_at' },
    });
    expect(wrapper.text()).toContain('Jane Doe change history');
    expect(wrapper.text()).toContain('Approved');
    expect(wrapper.text()).toContain('Ada HR · plan plan-1');
    expect(wrapper.text()).toContain('Annual review');
  });
});
