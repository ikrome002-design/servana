import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';
import { useMerchantStore } from '@/stores/merchantStore';
import type {
  AuthenticatedUser,
  BootstrapPayload,
  MerchantMembership,
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
  const setup = ref<SetupState | null>(null);
  const loading = ref(false);
  const bootstrapped = ref(false);

  const isAuthenticated = (): boolean => user.value !== null;

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
    setup.value = payload.setup;
    useMerchantStore().setMerchant(payload.merchant);
  }

  function clear(): void {
    user.value = null;
    membership.value = null;
    memberships.value = [];
    permissions.value = [];
    branchIds.value = [];
    setup.value = null;
    useMerchantStore().$reset();
  }

  /** Whether the signed-in member is a merchant admin (UX gating only). */
  const isMerchantAdmin = (): boolean => membership.value?.role === 'merchant_admin';

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
    setup,
    loading,
    bootstrapped,
    isAuthenticated,
    isMerchantAdmin,
    setupRequired,
    applyBootstrap,
    bootstrap,
    requestMagicLink,
    verifyMagicLink,
    logout,
    $reset,
  };
});
