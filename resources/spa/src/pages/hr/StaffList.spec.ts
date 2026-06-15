import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import StaffList from '@/pages/hr/StaffList.vue';

function member(status: string) {
  return {
    id: 's-'.concat(status),
    first_name: 'A',
    last_name: 'B',
    display_name: `Staff ${status}`,
    phone: '+254700000000',
    role: 'front_office',
    role_title: null,
    status,
    employment_type: 'full_time',
    employment_status: 'employed',
    primary_branch_id: 'b1',
    is_active: status === 'active',
  };
}

const mountPage = () => mount(StaffList, { global: { stubs: { RouterLink: true } } });

describe('StaffList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders staff with their status badges', async () => {
    get.mockResolvedValueOnce({
      data: { data: [member('invited'), member('active'), member('suspended'), member('deactivated')] },
    });

    const wrapper = mountPage();
    await flushPromises();

    const badges = wrapper.findAll('[data-testid="staff-status"]').map((b) => b.text());
    expect(badges).toEqual(['invited', 'active', 'suspended', 'deactivated']);
  });

  it('shows an empty state when there is no staff', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No staff yet.');
  });
});
