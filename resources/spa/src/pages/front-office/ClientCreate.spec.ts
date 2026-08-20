import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
}));

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

import ClientCreate from '@/pages/front-office/ClientCreate.vue';

const mountPage = () =>
  mount(ClientCreate, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('ClientCreate.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    push.mockReset();
  });

  it('creates a client and navigates to the detail screen', async () => {
    post.mockResolvedValueOnce({ data: { data: { id: 'cl9' } } });
    const wrapper = mountPage();

    await wrapper.find('#full_name').setValue('Amina');
    await wrapper.find('#phone').setValue('0712345678');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/clients', expect.objectContaining({ full_name: 'Amina', phone: '0712345678' }));
    expect(push).toHaveBeenCalledWith({ name: 'front-office.client-detail', params: { clientUlid: 'cl9' } });
  });

  it('surfaces a same-branch duplicate as a link to the existing client', async () => {
    post.mockRejectedValueOnce(
      Object.assign(new Error('dup'), {
        isAxiosError: true,
        apiError: { code: 'duplicate_client', message: 'exists', fields: {}, meta: { client_id: 'cl-existing' } },
      }),
    );
    const wrapper = mountPage();

    await wrapper.find('#full_name').setValue('Dup');
    await wrapper.find('#phone').setValue('0712345678');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    const warning = wrapper.find('[data-testid="duplicate-warning"]');
    expect(warning.exists()).toBe(true);
    expect(warning.text()).toContain('already exists');
    expect(push).not.toHaveBeenCalled();
  });
});
