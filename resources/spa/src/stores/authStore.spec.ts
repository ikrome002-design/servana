import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AuthenticatedUser } from '@/types/models';

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

const user: AuthenticatedUser = {
  id: '01J0000000000000000000USER',
  email: 'owner@salon.co.ke',
  name: 'Owner',
  status: 'active',
  email_verified_at: '2026-06-14T00:00:00+00:00',
  memberships: [],
  permissions: [],
  is_platform_staff: false,
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

  it('bootstraps the user from /me', async () => {
    get.mockResolvedValueOnce({ data: { data: user } });
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(get).toHaveBeenCalledWith('/me');
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.user?.email).toBe('owner@salon.co.ke');
    expect(auth.bootstrapped).toBe(true);
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
    post.mockResolvedValueOnce({ data: { data: user } });
    const auth = useAuthStore();

    await auth.verifyMagicLink('raw-token');

    expect(primeCsrfCookie).toHaveBeenCalledOnce();
    expect(post).toHaveBeenCalledWith('/auth/magic-link/verify', { token: 'raw-token' });
    expect(auth.isAuthenticated()).toBe(true);
  });

  it('clears the user on logout even if the request fails', async () => {
    get.mockResolvedValueOnce({ data: { data: user } });
    const auth = useAuthStore();
    await auth.bootstrap();
    expect(auth.isAuthenticated()).toBe(true);

    post.mockRejectedValueOnce(new Error('network'));
    await auth.logout();

    expect(auth.isAuthenticated()).toBe(false);
  });
});
