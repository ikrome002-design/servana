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
    theme_preference: null,
    resolved_theme: 'light' as const,
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
  branch_ids: [],
  setup: { required: false, current_step: 'done', completed_at: '2026-06-14T00:00:00+00:00' },
  mfa: {
    required: true,
    enrolled: true,
    confirmed: true,
    verified: true,
    enrollment_required: false,
    challenge_required: false,
    step_up_fresh: true,
    step_up_fresh_until: '2026-06-14T00:05:00+00:00',
    recovery_codes_remaining: 10,
  },
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

  it('flags enrollment required from the bootstrap MFA state', async () => {
    get.mockResolvedValueOnce({
      data: {
        data: {
          ...bootstrap,
          mfa: { ...bootstrap.mfa, confirmed: false, verified: false, enrollment_required: true },
        },
      },
    });
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(auth.mfaEnrollmentRequired()).toBe(true);
    expect(auth.mfaChallengeRequired()).toBe(false);
  });

  it('flags challenge required from the bootstrap MFA state', async () => {
    get.mockResolvedValueOnce({
      data: {
        data: { ...bootstrap, mfa: { ...bootstrap.mfa, verified: false, challenge_required: true } },
      },
    });
    const auth = useAuthStore();

    await auth.bootstrap();

    expect(auth.mfaChallengeRequired()).toBe(true);
  });

  it('starts enrollment and returns the secret + otpauth uri', async () => {
    post.mockResolvedValueOnce({
      data: {
        data: {
          // Low-entropy placeholder — not a real secret (apiClient is mocked).
          secret: 'AAAABBBBCCCCDDDD',
          otpauth_uri: 'otpauth://totp/Servana:owner@salon.co.ke?secret=AAAABBBBCCCCDDDD',
          mfa: { ...bootstrap.mfa, confirmed: false, enrollment_required: true },
        },
      },
    });
    const auth = useAuthStore();

    const result = await auth.startMfaEnrollment();

    expect(post).toHaveBeenCalledWith('/auth/mfa/enroll');
    expect(result.secret).toBe('AAAABBBBCCCCDDDD');
    expect(result.otpauth_uri).toContain('otpauth://totp/');
  });

  it('confirms enrollment and returns the one-time recovery codes', async () => {
    post.mockResolvedValueOnce({
      data: { data: { recovery_codes: ['AAAAA-BBBBB', 'CCCCC-DDDDD'], mfa: bootstrap.mfa } },
    });
    const auth = useAuthStore();

    const codes = await auth.confirmMfaEnrollment('123456');

    expect(post).toHaveBeenCalledWith('/auth/mfa/confirm', { code: '123456' });
    expect(codes).toEqual(['AAAAA-BBBBB', 'CCCCC-DDDDD']);
  });

  it('applies the refreshed bootstrap after a successful challenge', async () => {
    post.mockResolvedValueOnce({ data: { data: bootstrap } });
    const auth = useAuthStore();

    await auth.mfaChallenge('123456');

    expect(post).toHaveBeenCalledWith('/auth/mfa/challenge', { code: '123456' });
    expect(auth.isAuthenticated()).toBe(true);
    expect(auth.mfa?.verified).toBe(true);
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
