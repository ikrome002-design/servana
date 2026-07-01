import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Invoice } from '@/types/models';

/**
 * Invoicing (Plan §40, §25.3; Phase 17). UX state only — the API (InvoicePolicy +
 * EnsureBranchScope + the invoice state machine + billing/period-lock/idempotency
 * gates) is the security boundary. Client contact is ALWAYS masked by the server.
 * Front Office drafts + finalizes; Finance voids + adjusts. Finalization sends a
 * client-generated `Idempotency-Key` so a retry never allocates a second number,
 * item, or audit event. No payment or receipt action exists in Phase 17.
 */
export const useInvoiceStore = defineStore('invoice', () => {
  const invoices = ref<Invoice[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    invoices.value = [];
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchInvoices(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: Invoice[] }>('/invoices', { params });
      invoices.value = data.data;
    } catch {
      error.value = 'Unable to load invoices.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchInvoice(id: string): Promise<Invoice> {
    const { data } = await apiClient.get<{ data: Invoice }>(`/invoices/${id}`);
    return data.data;
  }

  async function createDraft(clientId: string, serviceSessionIds: string[]): Promise<Invoice> {
    const { data } = await apiClient.post<{ data: Invoice }>('/invoices', {
      client_id: clientId,
      service_session_ids: serviceSessionIds,
    });
    return data.data;
  }

  async function updateDraft(id: string, serviceSessionIds: string[]): Promise<Invoice> {
    const { data } = await apiClient.patch<{ data: Invoice }>(`/invoices/${id}`, {
      service_session_ids: serviceSessionIds,
    });
    return data.data;
  }

  /** Finalize a draft. The idempotency key makes a retry replay the stored success. */
  async function finalize(id: string, idempotencyKey?: string): Promise<Invoice> {
    const key = idempotencyKey ?? crypto.randomUUID();
    const { data } = await apiClient.post<{ data: Invoice }>(`/invoices/${id}/finalize`, null, {
      headers: { 'Idempotency-Key': key },
    });
    return data.data;
  }

  async function requestVoid(id: string, reason: string): Promise<Invoice> {
    const { data } = await apiClient.post<{ data: Invoice }>(`/invoices/${id}/void`, { reason });
    return data.data;
  }

  async function executeVoid(id: string): Promise<Invoice> {
    const { data } = await apiClient.post<{ data: Invoice }>(`/invoices/${id}/void/execute`);
    return data.data;
  }

  async function rejectVoid(id: string): Promise<Invoice> {
    const { data } = await apiClient.post<{ data: Invoice }>(`/invoices/${id}/void/reject`);
    return data.data;
  }

  async function adjust(id: string, reason: string): Promise<Invoice> {
    const { data } = await apiClient.post<{ data: Invoice }>(`/invoices/${id}/adjust`, { reason });
    return data.data;
  }

  return {
    invoices,
    loading,
    error,
    filterStatus,
    fetchInvoices,
    fetchInvoice,
    createDraft,
    updateDraft,
    finalize,
    requestVoid,
    executeVoid,
    rejectVoid,
    adjust,
    $reset,
  };
});
