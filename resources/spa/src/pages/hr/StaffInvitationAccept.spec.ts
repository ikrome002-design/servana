import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: () => Promise.resolve(),
}));
vi.mock('axios', () => ({
  default: { isAxiosError: (e: unknown) => Boolean((e as { isAxiosError?: boolean })?.isAxiosError) },
}));

let routeQuery: Record<string, string> = {};
vi.mock('vue-router', () => ({ useRoute: () => ({ query: routeQuery }) }));

import StaffInvitationAccept from '@/pages/hr/StaffInvitationAccept.vue';

const mountPage = () => mount(StaffInvitationAccept, { global: { stubs: { RouterLink: true } } });

describe('StaffInvitationAccept.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    routeQuery = {};
  });

  it('shows the form for a present token and accepts successfully', async () => {
    routeQuery = { token: 'good-token' };
    post.mockResolvedValueOnce({ data: { message: 'Your account is ready.' } });

    const wrapper = mountPage();
    await wrapper.find('#first_name').setValue('Amina');
    await wrapper.find('#last_name').setValue('Mwangi');
    await wrapper.find('#phone').setValue('+254700111222');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/staff-invitations/accept', {
      token: 'good-token',
      first_name: 'Amina',
      last_name: 'Mwangi',
      phone: '+254700111222',
    });
    expect(wrapper.find('[data-testid="accept-success"]').exists()).toBe(true);
  });

  it('shows the error state when no token is present', () => {
    routeQuery = {};
    const wrapper = mountPage();

    expect(wrapper.find('[data-testid="accept-error"]').exists()).toBe(true);
    expect(wrapper.find('#first_name').exists()).toBe(false);
  });

  it('shows a uniform error for an invalid or expired token', async () => {
    routeQuery = { token: 'dead-token' };
    post.mockRejectedValueOnce(Object.assign(new Error('422'), {
      isAxiosError: true,
      apiError: { code: 'invalid_or_expired_invitation', message: 'nope', fields: {}, meta: {} },
    }));

    const wrapper = mountPage();
    await wrapper.find('#first_name').setValue('Amina');
    await wrapper.find('#last_name').setValue('Mwangi');
    await wrapper.find('#phone').setValue('+254700111222');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(wrapper.find('[data-testid="accept-error"]').exists()).toBe(true);
  });
});
