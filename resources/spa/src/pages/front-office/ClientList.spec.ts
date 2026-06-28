import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import ClientList from '@/pages/front-office/ClientList.vue';
import { useAuthStore } from '@/stores/authStore';

const client = {
  id: 'cl1',
  full_name: 'Amina Yusuf',
  phone_masked: '••• ••• 5678',
  phone_last_four: '5678',
  email_masked: 'a••@example.com',
  has_email: true,
  notes: null,
  status: 'active',
};

const mountPage = () =>
  mount(ClientList, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('ClientList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('renders only masked client contact (never full phone/email)', async () => {
    get.mockResolvedValueOnce({ data: { data: [client] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Amina Yusuf');
    expect(wrapper.text()).toContain('••• ••• 5678');
    expect(wrapper.text()).toContain('a••@example.com');
    expect(wrapper.text()).not.toContain('254712345678');
  });

  it('searches by the q parameter', async () => {
    get.mockResolvedValue({ data: { data: [client] } });
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('#client-search').setValue('Amina');
    await wrapper.find('[data-testid="search-clients"]').trigger('submit');
    await flushPromises();

    expect(get).toHaveBeenLastCalledWith('/clients', { params: { q: 'Amina' } });
  });

  it('gates the add-client action on client.create', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="add-client"]').exists()).toBe(false);
  });
});
