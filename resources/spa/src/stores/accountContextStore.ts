import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';

/**
 * Server-derived account contexts and the switch flow (Phase UI-03; ADR-018; UI/UX plan §5.3).
 *
 * THIS STORE COMPUTES NOTHING ABOUT AUTHORITY. It holds what the server said, nothing more:
 *
 *  - it never derives, filters or infers which contexts a user may enter;
 *  - it never builds a target host URL — the server returns an allowlisted absolute URL, and a
 *    frontend that constructed its own could be made to send a user off-origin;
 *  - it never holds a permission set, and it never persists the handoff token anywhere.
 *
 * The API is the security boundary. Everything here is UX.
 */

/** One context, exactly as the server described it. */
export interface AccountContext {
  context_id: string;
  account_key: string;
  display_name: string;
  target_host: string;
  default_route: string;
  requires_mfa: boolean;
  merchant_id: string | null;
  merchant_name: string | null;
  branch_id: string | null;
  branch_name: string | null;
  role_label: string | null;
  is_current: boolean;
}

export const useAccountContextStore = defineStore('accountContext', () => {
  const contexts = ref<AccountContext[]>([]);
  const loading = ref(false);
  const switching = ref(false);
  const error = ref<string | null>(null);
  const loaded = ref(false);

  /** The switch control exists only when there is somewhere else to go. */
  const canSwitch = computed(() => contexts.value.length > 1);

  const otherContexts = computed(() => contexts.value.filter((context) => !context.is_current));

  const currentContext = computed(
    () => contexts.value.find((context) => context.is_current) ?? null,
  );

  async function fetchContexts(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: AccountContext[] }>('/auth/account-contexts');
      contexts.value = data.data;
      loaded.value = true;
    } catch {
      // A failure here must not strand the user in a half-populated switcher.
      contexts.value = [];
      error.value = 'We could not load your accounts. Please try again.';
    } finally {
      loading.value = false;
    }
  }

  /**
   * Mint a handoff and navigate to the target host.
   *
   * `switching` is the double-submit guard: a second click must not mint a second token, because
   * minting one supersedes the first and the user would arrive with a token the server has already
   * retired.
   *
   * `resetStores` is injected by the caller rather than imported, so this store stays free of a
   * dependency on every account-specific store in the app. It runs BEFORE the redirect so no
   * source-account data survives into the target host's first paint.
   */
  async function switchTo(
    contextId: string,
    options: { redirect?: string | null; resetStores?: () => void } = {},
  ): Promise<void> {
    if (switching.value) {
      return;
    }

    switching.value = true;
    error.value = null;

    try {
      await primeCsrfCookie();

      const { data } = await apiClient.post<{
        data: { target_url: string; target_account_key: string; requires_mfa: boolean };
      }>('/auth/account-contexts/switch', {
        context_id: contextId,
        ...(options.redirect ? { redirect: options.redirect } : {}),
      });

      options.resetStores?.();

      // The server's URL, verbatim. Same tab, so the target host's own session cookie is set on a
      // top-level navigation exactly as the handoff contract requires.
      window.location.assign(data.data.target_url);
    } catch {
      // Stay where we are, with the control usable again — a failed switch must not leave the user
      // stranded between two accounts.
      error.value = 'We could not switch accounts. Please try again.';
      switching.value = false;
    }
  }

  function $reset(): void {
    contexts.value = [];
    loading.value = false;
    switching.value = false;
    error.value = null;
    loaded.value = false;
  }

  return {
    contexts,
    loading,
    switching,
    error,
    loaded,
    canSwitch,
    otherContexts,
    currentContext,
    fetchContexts,
    switchTo,
    $reset,
  };
});
