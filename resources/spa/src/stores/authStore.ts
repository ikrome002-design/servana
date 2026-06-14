import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';
import type { AuthenticatedUser, Membership } from '@/types/models';

/**
 * Authentication state + Magic Link flow (Plan §6.2, §9.1).
 *
 * `memberships`/`activeMembership` remain on the store for router-guard
 * compatibility; they stay empty/null until Phase 6 resolves tenant context.
 * The API is the security boundary — these are UX state only.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthenticatedUser | null>(null);
  const memberships = ref<Membership[]>([]);
  const activeMembership = ref<Membership | null>(null);
  const loading = ref(false);
  const bootstrapped = ref(false);

  const isAuthenticated = (): boolean => user.value !== null;

  function setUser(next: AuthenticatedUser | null): void {
    user.value = next;
    memberships.value = next?.memberships ?? [];
    activeMembership.value = next?.memberships[0] ?? null;
  }

  function $reset(): void {
    setUser(null);
    bootstrapped.value = false;
    loading.value = false;
  }

  /** Resolve the current session on app start. 401 is expected (logged out). */
  async function bootstrap(): Promise<void> {
    loading.value = true;
    try {
      const { data } = await apiClient.get<{ data: AuthenticatedUser }>('/me');
      setUser(data.data);
    } catch {
      setUser(null);
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
    const { data } = await apiClient.post<{ data: AuthenticatedUser }>(
      '/auth/magic-link/verify',
      { token },
    );
    setUser(data.data);
    bootstrapped.value = true;
  }

  async function logout(): Promise<void> {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // Always clear client state, even if the network call fails.
    } finally {
      setUser(null);
    }
  }

  return {
    user,
    memberships,
    activeMembership,
    loading,
    bootstrapped,
    isAuthenticated,
    bootstrap,
    requestMagicLink,
    verifyMagicLink,
    logout,
    $reset,
  };
});
