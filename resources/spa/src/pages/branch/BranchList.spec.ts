import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import BranchList from '@/pages/branch/BranchList.vue';
import { useAuthStore } from '@/stores/authStore';

const branch = {
  id: 'b1',
  name: 'Kilimani',
  code: 'KIL001',
  address: null,
  town: 'Nairobi',
  phone: null,
  email: null,
  business_category: null,
  status: 'active',
  status_reason: null,
  archived_at: null,
};

// Render the RouterLink slot so link/button text (e.g. "Add branch") is asserted.
const mountPage = () =>
  mount(BranchList, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  });

describe('BranchList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders the merchant own-branch list', async () => {
    get.mockResolvedValueOnce({ data: { data: [branch] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/branches');
    expect(wrapper.text()).toContain('Kilimani');
    expect(wrapper.find('[data-testid="branch-status"]').text()).toBe('active');
  });

  it('shows the add-branch action for a user with the branches.create permission', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = ['branches.create'];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Add branch');
  });

  it('hides the add-branch action without the branches.create permission', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = [];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).not.toContain('Add branch');
  });
});
