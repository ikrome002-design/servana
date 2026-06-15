import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));
vi.mock('axios', () => ({
  default: { isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError) },
}));

import StaffInvitations from '@/pages/hr/StaffInvitations.vue';
import { useAuthStore } from '@/stores/authStore';

const branch = {
  id: 'b1', name: 'Kilimani', code: 'KIL001', address: null, town: null,
  phone: null, email: null, business_category: null, status: 'active',
  status_reason: null, archived_at: null,
};

function mountPage() {
  // /staff-invitations (index) and /branches are both fetched on mount.
  get.mockImplementation((url: string) =>
    url === '/branches'
      ? Promise.resolve({ data: { data: [branch] } })
      : Promise.resolve({ data: { data: [] } }),
  );
  return mount(StaffInvitations, { global: { stubs: { RouterLink: true } } });
}

describe('StaffInvitations.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    const auth = useAuthStore();
    auth.membership = { id: 'm1', role: 'merchant_admin', status: 'active' };
  });

  it('renders the invitation form with email, branch and role fields', async () => {
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('#email').exists()).toBe(true);
    expect(wrapper.find('#branch_id').exists()).toBe(true);
    expect(wrapper.find('#role').exists()).toBe(true);
  });

  it('submits an invitation', async () => {
    const wrapper = mountPage();
    await flushPromises();

    post.mockResolvedValueOnce({
      data: { data: { id: 'i1', email: 'staff@salon.co.ke', role: 'hr', role_title: null, branch_id: 'b1', status: 'pending', resend_count: 0, expires_at: '2026-06-18T00:00:00Z', last_sent_at: null } },
    });

    await wrapper.find('#email').setValue('staff@salon.co.ke');
    await wrapper.find('#branch_id').setValue('b1');
    await wrapper.find('#role').setValue('hr');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/staff-invitations', {
      email: 'staff@salon.co.ke',
      branch_id: 'b1',
      role: 'hr',
    });
  });
});
