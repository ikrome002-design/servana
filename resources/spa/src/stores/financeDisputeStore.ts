import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Finance disputes (Plan §44; Phase 18B). UX state only — the API
 * (FinanceDisputePolicy) is authoritative. Finance opens an investigation linked to an
 * invoice and/or a payment record, moves it open → under_review → resolved/rejected, and
 * attaches private evidence; the DISPUTED SOURCE record is never mutated. Resolution /
 * rejection require a mandatory note. `finance_dispute.manage` is `PL n/a`.
 */
export interface FinanceDisputeView {
  id: string;
  status: string;
  reason: string;
  resolution_note: string | null;
  has_evidence: boolean;
  created_at: string | null;
  updated_at: string | null;
  invoice?: { id: string; invoice_number: string | null } | null;
  payment_record?: { id: string; method: string } | null;
}

export const useFinanceDisputeStore = defineStore('financeDispute', () => {
  const disputes = ref<FinanceDisputeView[]>([]);
  const current = ref<FinanceDisputeView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    disputes.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchDisputes(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FinanceDisputeView[] }>('/finance-disputes', { params });
      disputes.value = data.data;
    } catch {
      error.value = 'Unable to load disputes.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchDispute(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: FinanceDisputeView }>(`/finance-disputes/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the dispute.';
    } finally {
      loading.value = false;
    }
  }

  async function create(payload: { invoice?: string; payment_record?: string; reason: string; evidence_file?: string }): Promise<FinanceDisputeView> {
    const { data } = await apiClient.post<{ data: FinanceDisputeView }>('/finance-disputes', payload);
    current.value = data.data;
    return data.data;
  }

  async function startReview(id: string): Promise<FinanceDisputeView> {
    const { data } = await apiClient.post<{ data: FinanceDisputeView }>(`/finance-disputes/${id}/start-review`, {});
    current.value = data.data;
    return data.data;
  }

  async function decide(id: string, verb: 'resolve' | 'reject', resolutionNote: string): Promise<FinanceDisputeView> {
    const { data } = await apiClient.post<{ data: FinanceDisputeView }>(`/finance-disputes/${id}/${verb}`, { resolution_note: resolutionNote });
    current.value = data.data;
    return data.data;
  }

  return { disputes, current, loading, error, filterStatus, $reset, fetchDisputes, fetchDispute, create, startReview, decide };
});
