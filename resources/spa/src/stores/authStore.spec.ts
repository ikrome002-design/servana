import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { BootstrapPayload } from '@/types/models';

// Mock the API client + CSRF helper used by the store.
const get = vi.fn();
const post = vi.fn();
const primeCsrfCookie = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
  },
  primeCsrfCookie: () => primeCsrfCookie(),
}));

import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';

const bootstrap: BootstrapPayload = {
  user: {
    id: '01J0000000000000000000USER',
    email: 'owner@salon.co.ke',
    name: 'Owner',
    status: 'active',
    email_verified_at: '2026-06-14T00:00:00+00:00',
    is_platform_staff: false,
  },
  merchant: {
    id: '01J000000000000000000MERCH',
    name: 'Glow Salon',
    slug: 'glow-salon',
    status: 'active',
    service_fee_tier: 'split_tier',
    setup_completed_at: '2026-06-14T00:00:00+00:00',
  },
  membership: { id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' },
  memberships: [{ id: '01J00000000000000000MEMBER', role: 'merchant_admin', status: 'active' }],
  permissions: [],
  setup: { required: false, current_step: 'done', completed_at: '2026-06-14T00:00:00+00:00' },
};

describe('authStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    primeCsrfCookie.mockReset();
  });

  it('starts unauthenticated', () => {
    const auth = useAuthStore();
    expect(auth.isAuthenticated()).toBe(false);
    expect(auth.user).toBeNull();
  });

  it('bootstraps the user and merchant from /me', async () => {
    get.mockResolvedValueOnce({ data: { data: bootstrap } });
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(get).toHaveBeenCalledWith('/me');
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.user?.email).toBe('owner@salon.co.ke');
    expect(auth.membership?.role).toBe('merchant_admin');
    expect(auth.setupRequired()).toBe(false);
    expect(useMerchantStore().merchant?.name).toBe('Glow Salon');
    expect(auth.bootstrapped).toBe(true);
  });

  it('reflects a pending_setup owner needing setup', async () => {
    get.mockResolvedValueOnce({
      data: {
        data: {
          ...bootstrap,
          merchant: { ...bootstrap.merchant!, status: 'pending_setup', setup_completed_at: null },
          setup: { required: true, current_step: 'service_fee_tier', completed_at: null },
        },
      },
    });
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(auth.setupRequired()).toBe(true);
    expect(useMerchantStore().isPendingSetup()).toBe(true);
  });

  it('treats a failed /me as logged out', async () => {
    get.mockRejectedValueOnce(new Error('401'));
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(auth.isAuthenticated()).toBe(false);
    expect(auth.bootstrapped).toBe(true);
  });

  it('primes CSRF before requesting a magic link', async () => {
    post.mockResolvedValueOnce({ data: { message: 'ok' } });
    const auth = useAuthStore();

    await auth.requestMagicLink('owner@salon.co.ke');

    expect(primeCsrfCookie).toHaveBeenCalledOnce();
    expect(post).toHaveBeenCalledWith('/auth/magic-link', { email: 'owner@salon.co.ke' });
  });

  it('sets the user after verifying a token', async () => {
    post.mockResolvedValueOnce({ data: { data: bootstrap } });
    const auth = useAuthStore();

    await auth.verifyMagicLink('raw-token');

    expect(primeCsrfCookie).toHaveBeenCalledOnce();
    expect(post).toHaveBeenCalledWith('/auth/magic-link/verify', { token: 'raw-token' });
    expect(auth.isAuthenticated()).toBe(true);
  });

  it('clears the user and merchant on logout even if the request fails', async () => {
    get.mockResolvedValueOnce({ data: { data: bootstrap } });
    const auth = useAuthStore();
    await auth.bootstrap();
    expect(auth.isAuthenticated()).toBe(true);

    post.mockRejectedValueOnce(new Error('network'));
    await auth.logout();

    expect(auth.isAuthenticated()).toBe(false);
    expect(useMerchantStore().merchant).toBeNull();
  });
});
