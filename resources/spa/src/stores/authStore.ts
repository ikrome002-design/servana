import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';
import { useMerchantStore } from '@/stores/merchantStore';
import type {
  AuthenticatedUser,
  BootstrapPayload,
  MerchantMembership,
  MfaState,
  SetupState,
} from '@/types/models';

/**
 * Authentication state + Magic Link flow (Plan §6.2, §8.1, §9.1).
 *
 * Phase 6 bootstrap carries the resolved tenant context: the user, their
 * merchant (held in merchantStore), their membership, and first-time setup
 * state. The API is the security boundary — this state is UX only.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthenticatedUser | null>(null);
  const membership = ref<MerchantMembership | null>(null);
  const memberships = ref<MerchantMembership[]>([]);
  const permissions = ref<string[]>([]);
  const branchIds = ref<string[]>([]);
  /**
   * The account experiences this user may enter, as reported by `/me` (Phase UI-03).
   *
   * Server-derived, never computed here. The frontend deliberately holds NO role→account mapping:
   * one would be a second authority that could disagree with the database.
   */
  const accountKeys = ref<string[]>([]);
  const setup = ref<SetupState | null>(null);
  const mfa = ref<MfaState | null>(null);
  const loading = ref(false);
  const bootstrapped = ref(false);

  const isAuthenticated = (): boolean => user.value !== null;

  /** A mandatory-MFA user who must finish enrollment before privileged routes. */
  const mfaEnrollmentRequired = (): boolean => mfa.value?.enrollment_required === true;

  /** A confirmed mandatory-MFA user who must challenge this session. */
  const mfaChallengeRequired = (): boolean => mfa.value?.challenge_required === true;

  // Retained alias for router-guard compatibility (Phase 4 guards).
  const activeMembership = membership;

  /** True when the signed-in owner still needs to complete first-time setup. */
  const setupRequired = (): boolean => setup.value?.required === true;

  function applyBootstrap(payload: BootstrapPayload): void {
    user.value = payload.user;
    membership.value = payload.membership;
    memberships.value = payload.memberships ?? [];
    permissions.value = payload.permissions ?? [];
    branchIds.value = payload.branch_ids ?? [];
    accountKeys.value = payload.account_keys ?? [];
    setup.value = payload.setup;
    mfa.value = payload.mfa ?? null;
    useMerchantStore().setMerchant(payload.merchant);
  }

  function clear(): void {
    user.value = null;
    membership.value = null;
    memberships.value = [];
    permissions.value = [];
    branchIds.value = [];
    accountKeys.value = [];
    setup.value = null;
    mfa.value = null;
    useMerchantStore().$reset();
  }

  /** Whether the signed-in member is a merchant admin (UX gating only). */
  const isMerchantAdmin = (): boolean => membership.value?.role === 'merchant_admin';

  /**
   * Whether the server says this user may enter an account experience (Phase UI-03).
   *
   * A membership check against the server's own list — not a role comparison, and not a mapping.
   * Used by the account-entry route guard to refuse a wrong-account surface before it mounts.
   */
  const holdsAccount = (accountKey: string): boolean => accountKeys.value.includes(accountKey);

  function $reset(): void {
    clear();
    bootstrapped.value = false;
    loading.value = false;
  }

  /** Resolve the current session on app start. 401 is expected (logged out). */
  async function bootstrap(): Promise<void> {
    loading.value = true;
    try {
      const { data } = await apiClient.get<{ data: BootstrapPayload }>('/me');
      applyBootstrap(data.data);
    } catch {
      clear();
    } finally {
      bootstrapped.value = true;
      loading.value = false;
    }
  }

  /** Request a Magic Link. Always resolves (server returns a uniform 202). */
  async function requestMagicLink(email: string): Promise<void> {
    await primeCsrfCookie();
    await apiClient.post('/auth/magic-link', { email });
  }

  /** Consume a token from the verify link; on success the user is logged in. */
  async function verifyMagicLink(token: string): Promise<void> {
    await primeCsrfCookie();
    const { data } = await apiClient.post<{ data: BootstrapPayload }>(
      '/auth/magic-link/verify',
      { token },
    );
    applyBootstrap(data.data);
    bootstrapped.value = true;
  }

  /** Refresh just the MFA state (Plan §18) — e.g. after a step-up re-challenge. */
  async function fetchMfaStatus(): Promise<void> {
    const { data } = await apiClient.get<{ data: { mfa: MfaState } }>('/auth/mfa');
    mfa.value = data.data.mfa;
  }

  /** Start TOTP enrollment; returns the secret + otpauth URI to display once. */
  async function startMfaEnrollment(): Promise<{ secret: string; otpauth_uri: string }> {
    const { data } = await apiClient.post<{
      data: { secret: string; otpauth_uri: string; mfa: MfaState };
    }>('/auth/mfa/enroll');
    mfa.value = data.data.mfa;
    return { secret: data.data.secret, otpauth_uri: data.data.otpauth_uri };
  }

  /** Confirm enrollment with a TOTP code; returns the one-time recovery codes. */
  async function confirmMfaEnrollment(code: string): Promise<string[]> {
    const { data } = await apiClient.post<{
      data: { recovery_codes: string[]; mfa: MfaState };
    }>('/auth/mfa/confirm', { code });
    mfa.value = data.data.mfa;
    return data.data.recovery_codes;
  }

  /** Challenge the session with a TOTP code; applies the refreshed bootstrap. */
  async function mfaChallenge(code: string): Promise<void> {
    const { data } = await apiClient.post<{ data: BootstrapPayload }>(
      '/auth/mfa/challenge',
      { code },
    );
    applyBootstrap(data.data);
  }

  /** Challenge the session with a recovery code (same contract as TOTP). */
  async function mfaRecoveryChallenge(code: string): Promise<void> {
    const { data } = await apiClient.post<{ data: BootstrapPayload }>(
      '/auth/mfa/recovery-challenge',
      { code },
    );
    applyBootstrap(data.data);
  }

  async function logout(): Promise<void> {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // Always clear client state, even if the network call fails.
    } finally {
      clear();
    }
  }

  return {
    user,
    membership,
    memberships,
    activeMembership,
    permissions,
    branchIds,
    accountKeys,
    setup,
    mfa,
    loading,
    bootstrapped,
    isAuthenticated,
    isMerchantAdmin,
    holdsAccount,
    setupRequired,
    mfaEnrollmentRequired,
    mfaChallengeRequired,
    applyBootstrap,
    bootstrap,
    requestMagicLink,
    verifyMagicLink,
    fetchMfaStatus,
    startMfaEnrollment,
    confirmMfaEnrollment,
    mfaChallenge,
    mfaRecoveryChallenge,
    logout,
    $reset,
  };
});
