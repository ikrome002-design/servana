import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const primeCsrfCookie = vi.fn(() => Promise.resolve());
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: () => primeCsrfCookie(),
}));

// Treat any thrown object carrying `isAxiosError` as an axios error.
vi.mock('axios', () => ({
  default: {
    isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError),
  },
}));

import RegisterMerchant from '@/pages/auth/RegisterMerchant.vue';

const mountPage = () =>
  mount(RegisterMerchant, { global: { stubs: { RouterLink: true } } });

describe('RegisterMerchant.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    primeCsrfCookie.mockClear();
  });

  it('renders accessible owner, business and email fields', () => {
    const wrapper = mountPage();
    expect(wrapper.find('#owner_name').exists()).toBe(true);
    expect(wrapper.find('#business_name').exists()).toBe(true);
    expect(wrapper.find('#email').exists()).toBe(true);
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
  });

  it('submits the registration and shows the uniform success state', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });
    const wrapper = mountPage();

    await wrapper.find('#owner_name').setValue('Paul Nderitu');
    await wrapper.find('#business_name').setValue('Glow Salon');
    await wrapper.find('#email').setValue('owner@example.com');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/merchant-registration/self-register', {
      owner_name: 'Paul Nderitu',
      business_name: 'Glow Salon',
      email: 'owner@example.com',
    });
    expect(wrapper.find('[data-testid="register-success"]').exists()).toBe(true);
  });

  it('maps server validation errors onto the fields and stays on the form', async () => {
    const apiError = {
      code: 'validation_failed',
      message: 'Invalid',
      fields: { email: ['The email field is required.'] },
      meta: {},
    };
    post.mockRejectedValueOnce(
      Object.assign(new Error('422'), { isAxiosError: true, apiError }),
    );

    const wrapper = mountPage();
    await wrapper.find('#email').setValue('bad');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(wrapper.find('[data-testid="register-success"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('The email field is required.');
  });
});
