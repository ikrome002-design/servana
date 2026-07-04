import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * External refunds (Plan §44; Phase 18B). UX state only — the API (RefundPolicy +
 * EnsureBranchScope + period-lock/idempotency gates + fresh MFA on approve/finalize) is
 * authoritative. Servana NEVER moves funds; a refund records the intent against a
 * validated payment component. Maker (`refund.create`) requests; a DISTINCT Finance
 * membership approves + finalizes. Finalization is irreversible. Every mutation sends a
 * client-generated `Idempotency-Key`; the external reference is always masked.
 */
export interface MoneyView {
  amount: number;
  currency: string;
  formatted: string;
}

export interface RefundView {
  id: string;
  status: string;
  amount: MoneyView;
  currency: string;
  method: string;
  reference_masked: string | null;
  reason: string;
  refund_group: string;
  approved_at: string | null;
  finalized_at: string | null;
  rejected_at: string | null;
  created_at: string | null;
  invoice?: { id: string; invoice_number: string | null; status: string };
  payment_record?: { id: string; method: string } | null;
}

export const useRefundStore = defineStore('refund', () => {
  const refunds = ref<RefundView[]>([]);
  const current = ref<RefundView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    refunds.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchRefunds(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: RefundView[] }>('/refunds', { params });
      refunds.value = data.data;
    } catch {
      error.value = 'Unable to load refunds.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchRefund(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: RefundView }>(`/refunds/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the refund.';
    } finally {
      loading.value = false;
    }
  }

  async function request(payload: { payment_record: string; amount_minor: number; method: string; reason: string; reference?: string }): Promise<RefundView> {
    const { data } = await apiClient.post<{ data: RefundView }>('/refunds', payload, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  /** approve / reject / finalize. approve + finalize require a fresh MFA step-up (server-enforced). */
  async function decide(id: string, verb: 'approve' | 'reject' | 'finalize'): Promise<RefundView> {
    const { data } = await apiClient.post<{ data: RefundView }>(`/refunds/${id}/${verb}`, {}, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  return { refunds, current, loading, error, filterStatus, $reset, fetchRefunds, fetchRefund, request, decide };
});
