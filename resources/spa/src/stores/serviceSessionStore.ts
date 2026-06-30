import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { PersonnelServiceSession, ServiceSession } from '@/types/models';

/**
 * Service sessions (Plan §25.2; Phase 16C). UX state only — the API
 * (ServiceSessionPolicy + EnsureBranchScope + the service-session state machine) is
 * the boundary. Client contact is ALWAYS masked by the server; this store never holds
 * a full phone or email (guardrail §6.4). Front Office owns operational mutations
 * (cancel/notes); start + complete are driven by the queue board. Personnel use the
 * own-scope read endpoint only and never see the commission preview. The completion
 * preview is always "not earned or payable" in Phase 16C.
 */
export const useServiceSessionStore = defineStore('serviceSession', () => {
  const sessions = ref<ServiceSession[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    sessions.value = [];
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchSessions(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      else params.active = 'true';
      const { data } = await apiClient.get<{ data: ServiceSession[] }>('/service-sessions', { params });
      sessions.value = data.data;
    } catch {
      error.value = 'Unable to load service sessions.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchSession(id: string): Promise<ServiceSession> {
    const { data } = await apiClient.get<{ data: ServiceSession }>(`/service-sessions/${id}`);
    return data.data;
  }

  async function cancel(id: string, reason: string): Promise<ServiceSession> {
    const { data } = await apiClient.post<{ data: ServiceSession }>(`/service-sessions/${id}/cancel`, { reason });
    return data.data;
  }

  async function updateNotes(id: string, notes: string): Promise<ServiceSession> {
    const { data } = await apiClient.patch<{ data: ServiceSession }>(`/service-sessions/${id}/notes`, { notes });
    return data.data;
  }

  return {
    sessions,
    loading,
    error,
    filterStatus,
    fetchSessions,
    fetchSession,
    cancel,
    updateNotes,
    $reset,
  };
});

/** Personnel own-scope service sessions (read-only; no preview, no mutation). */
export const usePersonnelServiceSessionStore = defineStore('personnelServiceSession', () => {
  const sessions = ref<PersonnelServiceSession[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    sessions.value = [];
    loading.value = false;
    error.value = null;
  }

  async function fetchMine(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PersonnelServiceSession[] }>('/personnel/me/sessions', {
        params: { sort: '-created_at' },
      });
      sessions.value = data.data;
    } catch {
      error.value = 'Unable to load your sessions.';
    } finally {
      loading.value = false;
    }
  }

  return { sessions, loading, error, fetchMine, $reset };
});
