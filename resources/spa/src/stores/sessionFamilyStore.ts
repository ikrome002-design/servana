import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * The signed-in user's OWN active sessions (Phase UI-03 backend; Phase UI-08 page §5.4.22).
 *
 * Authorization is OWNERSHIP, and it is the server's: every query in `HostSessionController` is
 * filtered by the authenticated principal's `user_id`, and a foreign session ULID returns 404 — not
 * 403 — so a caller cannot confirm that another user's session exists. This store therefore carries
 * no user identifier in any request, and there is no method that could take one.
 *
 * What the API deliberately never sends, and this store consequently never holds: the raw session
 * id, the family id, the IP address and the user-agent string. A session list is a security screen,
 * not telemetry, and a screenshot of it must not help anyone.
 */
export interface HostSessionView {
  id: string;
  account_key: string;
  host: string;
  merchant_name: string | null;
  branch_name: string | null;
  created_at: string | null;
  last_activity_at: string;
  revoked: boolean;
  is_current: boolean;
}

export const useSessionFamilyStore = defineStore('sessionFamily', () => {
  const sessions = ref<HostSessionView[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const revoking = ref<string | null>(null);

  const otherSessions = computed(() => sessions.value.filter((session) => !session.is_current));

  function $reset(): void {
    sessions.value = [];
    loading.value = false;
    error.value = null;
    revoking.value = null;
  }

  async function fetchSessions(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: HostSessionView[] }>('/auth/sessions');
      sessions.value = data.data;
    } catch {
      error.value = 'We couldn’t load your active sessions.';
    } finally {
      loading.value = false;
    }
  }

  /**
   * Revoke one session. Idempotent server-side, so a double submit is harmless; the list is
   * re-read afterwards rather than patched locally, because the server decides what is still
   * active and a locally removed row could disagree with it.
   */
  async function revoke(id: string): Promise<void> {
    revoking.value = id;
    error.value = null;
    try {
      await apiClient.delete(`/auth/sessions/${id}`);
      await fetchSessions();
    } catch {
      error.value = 'We couldn’t end that session.';
    } finally {
      revoking.value = null;
    }
  }

  return { sessions, otherSessions, loading, error, revoking, $reset, fetchSessions, revoke };
});
