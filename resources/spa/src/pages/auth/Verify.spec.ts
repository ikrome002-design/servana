import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const primeCsrfCookie = vi.fn(() => Promise.resolve());
vi.mock('@/services/apiClient', () => ({
  apiClient: { post: (...a: unknown[]) => post(...a) },
  primeCsrfCookie: () => primeCsrfCookie(),
}));

const replace = vi.fn();
const push = vi.fn();
let routeQuery: Record<string, string> = {};
vi.mock('vue-router', () => ({
  useRouter: () => ({ replace, push }),
  useRoute: () => ({ query: routeQuery }),
}));

import Verify from '@/pages/auth/Verify.vue';

describe('Verify.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    post.mockReset();
    replace.mockReset();
    push.mockReset();
    routeQuery = {};
  });

  it('verifies a present token and redirects home on success', async () => {
    routeQuery = { token: 'good-token' };
    post.mockResolvedValueOnce({
      data: { data: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, memberships: [], permissions: [], is_platform_staff: false } },
    });

    mount(Verify);
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/auth/magic-link/verify', { token: 'good-token' });
    expect(replace).toHaveBeenCalledWith({ name: 'home' });
  });

  it('shows a uniform error for an invalid or expired token', async () => {
    routeQuery = { token: 'dead-token' };
    post.mockRejectedValueOnce(new Error('422'));

    const wrapper = mount(Verify);
    await flushPromises();

    expect(replace).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('invalid or has expired');
  });

  it('shows the error state when no token is present', async () => {
    routeQuery = {};

    const wrapper = mount(Verify);
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('invalid or has expired');
  });
});
