import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
}));
vi.mock('axios', () => ({
  default: { isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError) },
}));

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

import BranchCreate from '@/pages/branch/BranchCreate.vue';

const mountPage = () => mount(BranchCreate, { global: { stubs: { RouterLink: true } } });

describe('BranchCreate.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    push.mockReset();
  });

  it('renders accessible name and code fields', () => {
    const wrapper = mountPage();
    expect(wrapper.find('#name').exists()).toBe(true);
    expect(wrapper.find('#code').exists()).toBe(true);
    expect(wrapper.find('label[for="name"]').exists()).toBe(true);
  });

  it('submits and redirects to the branch list', async () => {
    post.mockResolvedValueOnce({ data: { data: { id: 'b1', name: 'Kilimani', code: 'KIL001' } } });
    const wrapper = mountPage();

    await wrapper.find('#name').setValue('Kilimani');
    await wrapper.find('#code').setValue('KIL001');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/branches', expect.objectContaining({ name: 'Kilimani', code: 'KIL001' }));
    expect(push).toHaveBeenCalledWith({ name: 'branch.list' });
  });

  it('maps server validation errors onto the fields', async () => {
    post.mockRejectedValueOnce(Object.assign(new Error('422'), {
      isAxiosError: true,
      apiError: { code: 'validation_failed', message: 'Invalid', fields: { code: ['The code has already been taken.'] }, meta: {} },
    }));
    const wrapper = mountPage();

    await wrapper.find('#name').setValue('Kilimani');
    await wrapper.find('#code').setValue('DUP');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(push).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('The code has already been taken.');
  });
});
