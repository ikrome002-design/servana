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

  it('verifies a present token and routes an active merchant to the role landing', async () => {
    routeQuery = { token: 'good-token' };
    post.mockResolvedValueOnce({
      data: {
        data: {
          user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'active', service_fee_tier: 'split_tier', setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: { id: 'mm1', role: 'merchant_admin', status: 'active' },
          memberships: [{ id: 'mm1', role: 'merchant_admin', status: 'active' }],
          permissions: [],
          setup: { required: false, current_step: 'done', completed_at: '2026-01-01T00:00:00Z' },
        },
      },
    });

    mount(Verify);
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/auth/magic-link/verify', { token: 'good-token' });
    expect(replace).toHaveBeenCalledWith({ name: 'merchant.landing' });
  });

  it('routes a pending_setup owner to the first-time setup wizard', async () => {
    routeQuery = { token: 'good-token' };
    post.mockResolvedValueOnce({
      data: {
        data: {
          user: { id: 'u1', email: 'a@b.co', name: 'A', status: 'active', email_verified_at: null, is_platform_staff: false },
          merchant: { id: 'm1', name: 'Glow', slug: 'glow', status: 'pending_setup', service_fee_tier: null, setup_completed_at: null },
          membership: { id: 'mm1', role: 'merchant_admin', status: 'active' },
          memberships: [{ id: 'mm1', role: 'merchant_admin', status: 'active' }],
          permissions: [],
          setup: { required: true, current_step: 'service_fee_tier', completed_at: null },
        },
      },
    });

    mount(Verify);
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ name: 'onboarding.first-time-setup' });
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
