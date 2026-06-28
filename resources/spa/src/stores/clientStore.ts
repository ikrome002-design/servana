import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Client, SmsConsentState } from '@/types/models';

/**
 * Client records (Scope §clients/§35, Phase 15A). UX state only — the API
 * (ClientPolicy / front_office.search + EnsureBranchScope) is the boundary.
 * Contact is ALWAYS masked by the server; this store never holds a full phone or
 * email (guardrail §6.4).
 */
export const useClientStore = defineStore('client', () => {
  const clients = ref<Client[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastQuery = ref('');

  function $reset(): void {
    clients.value = [];
    loading.value = false;
    error.value = null;
    lastQuery.value = '';
  }

  async function fetchClients(query = ''): Promise<void> {
    loading.value = true;
    error.value = null;
    lastQuery.value = query;
    try {
      const params = query.trim() === '' ? {} : { q: query.trim() };
      const { data } = await apiClient.get<{ data: Client[] }>('/clients', { params });
      clients.value = data.data;
    } catch {
      error.value = 'Unable to load clients.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchClient(id: string): Promise<Client> {
    const { data } = await apiClient.get<{ data: Client }>(`/clients/${id}`);
    return data.data;
  }

  async function createClient(payload: {
    full_name: string;
    phone: string;
    email?: string;
    notes?: string;
  }): Promise<Client> {
    const { data } = await apiClient.post<{ data: Client }>('/clients', payload);
    return data.data;
  }

  async function updateClient(id: string, payload: Record<string, unknown>): Promise<Client> {
    const { data } = await apiClient.patch<{ data: Client }>(`/clients/${id}`, payload);
    return data.data;
  }

  async function changeConsent(id: string, state: SmsConsentState): Promise<void> {
    await apiClient.put(`/clients/${id}/sms-consent`, { state });
  }

  return {
    clients,
    loading,
    error,
    lastQuery,
    fetchClients,
    fetchClient,
    createClient,
    updateClient,
    changeConsent,
    $reset,
  };
});
