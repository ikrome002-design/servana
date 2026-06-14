import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const primeCsrfCookie = vi.fn(() => Promise.resolve());
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: () => primeCsrfCookie(),
}));

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

import Login from '@/pages/auth/Login.vue';

describe('Login.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    push.mockReset();
    primeCsrfCookie.mockClear();
  });

  it('renders an accessible email field and submit button', () => {
    const wrapper = mount(Login);
    const input = wrapper.find('#email');
    expect(input.exists()).toBe(true);
    expect(wrapper.find('label[for="email"]').exists()).toBe(true);
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
  });

  it('submits the email and advances to the check-email screen', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });
    const wrapper = mount(Login);

    await wrapper.find('#email').setValue('owner@salon.co.ke');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/auth/magic-link', { email: 'owner@salon.co.ke' });
    expect(push).toHaveBeenCalledWith({
      name: 'auth.check-email',
      query: { email: 'owner@salon.co.ke' },
    });
  });
});
