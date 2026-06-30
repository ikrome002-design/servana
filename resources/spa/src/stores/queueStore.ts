import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type {
  PersonnelQueueEntry,
  QueueAssignmentMode,
  QueueConfiguration,
  QueueEntry,
} from '@/types/models';

/**
 * Walk-ins & queues (Plan §37; Phase 16B). UX state only — the API (QueueEntryPolicy
 * + EnsureBranchScope + the queue state machine / scheduling gates) is the boundary.
 * Client contact is ALWAYS masked by the server; this store never holds a full phone
 * or email (guardrail §6.4). Front Office owns operational mutations; Branch Manager
 * reads + configures; Personnel use the own-scope read endpoint only.
 */
export const useQueueStore = defineStore('queue', () => {
  const entries = ref<QueueEntry[]>([]);
  const configuration = ref<QueueConfiguration | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    entries.value = [];
    configuration.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchQueue(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { active: 'true', sort: 'position' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: QueueEntry[] }>('/queue-entries', { params });
      entries.value = data.data;
    } catch {
      error.value = 'Unable to load the queue.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchConfiguration(): Promise<void> {
    const { data } = await apiClient.get<{ data: QueueConfiguration }>('/queue/configuration');
    configuration.value = data.data;
  }

  async function fetchEntry(id: string): Promise<QueueEntry> {
    const { data } = await apiClient.get<{ data: QueueEntry }>(`/queue-entries/${id}`);
    return data.data;
  }

  async function createWalkIn(payload: {
    assignment_mode: QueueAssignmentMode;
    service: string;
    client?: string | null;
    new_client?: { full_name: string; phone: string; email?: string } | null;
    personnel?: string | null;
    preferred_personnel?: string | null;
  }): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>('/walk-ins', payload);
    return data.data;
  }

  async function convertAppointment(appointmentId: string, mode: QueueAssignmentMode): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>(`/appointments/${appointmentId}/queue`, {
      assignment_mode: mode,
    });
    return data.data;
  }

  async function assign(
    id: string,
    mode: QueueAssignmentMode,
    personnel?: string,
    preferredPersonnel?: string,
    reason?: string,
  ): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>(`/queue-entries/${id}/assign`, {
      assignment_mode: mode,
      personnel,
      preferred_personnel: preferredPersonnel,
      reason,
    });
    return data.data;
  }

  async function transition(id: string, action: 'call' | 'start' | 'complete' | 'no-show'): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>(`/queue-entries/${id}/${action}`);
    return data.data;
  }

  async function transfer(id: string, personnel: string, reason: string): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>(`/queue-entries/${id}/transfer`, {
      personnel,
      reason,
    });
    return data.data;
  }

  async function cancel(id: string, reason: string): Promise<QueueEntry> {
    const { data } = await apiClient.post<{ data: QueueEntry }>(`/queue-entries/${id}/cancel`, { reason });
    return data.data;
  }

  async function reorder(order: string[]): Promise<void> {
    const { data } = await apiClient.put<{ data: QueueEntry[] }>('/queue-entries/reorder', { order });
    // Refresh the active board so the new order is reflected.
    const waiting = data.data;
    entries.value = entries.value
      .map((entry) => waiting.find((w) => w.id === entry.id) ?? entry)
      .sort((a, b) => a.position - b.position);
  }

  async function updateConfiguration(payload: {
    queue_is_open?: boolean;
    queue_capacity?: number | null;
    queue_default_assignment_mode?: 'next_available' | 'manual';
  }): Promise<void> {
    const { data } = await apiClient.put<{ data: QueueConfiguration }>('/queue/configuration', payload);
    configuration.value = data.data;
  }

  return {
    entries,
    configuration,
    loading,
    error,
    filterStatus,
    fetchQueue,
    fetchConfiguration,
    fetchEntry,
    createWalkIn,
    convertAppointment,
    assign,
    transition,
    transfer,
    cancel,
    reorder,
    updateConfiguration,
    $reset,
  };
});

/** Personnel own-scope queue (read-only). */
export const usePersonnelQueueStore = defineStore('personnelQueue', () => {
  const entries = ref<PersonnelQueueEntry[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    entries.value = [];
    loading.value = false;
    error.value = null;
  }

  async function fetchMine(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PersonnelQueueEntry[] }>('/personnel/me/queue', {
        params: { active: 'true', sort: 'position' },
      });
      entries.value = data.data;
    } catch {
      error.value = 'Unable to load your queue.';
    } finally {
      loading.value = false;
    }
  }

  return { entries, loading, error, fetchMine, $reset };
});
