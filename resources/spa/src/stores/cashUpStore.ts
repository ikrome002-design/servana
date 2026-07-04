import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Branch cash-up + day-close reconciliation (Plan §45; ADR-0007; Phase 18B). UX state
 * only — the API (CashUpPolicy + EnsureBranchScope + the cash-up state machine +
 * period-lock/idempotency gates + the server-derived expected totals) is the security
 * boundary. The Branch Manager (maker) drafts + submits; Finance (checker) approves /
 * rejects / requests correction. Expected totals are ALWAYS server-derived — the client
 * only enters counted amounts. State-changing POSTs send a client-generated
 * `Idempotency-Key`.
 */
export interface MoneyView {
  amount: number;
  currency: string;
  formatted: string;
}

export interface CashUpLineView {
  method: string;
  expected_minor: number;
  counted_minor: number;
  variance_minor: number;
}

export interface CashUpView {
  id: string | null;
  business_date: string;
  status: string;
  expected: MoneyView;
  counted: MoneyView;
  variance: MoneyView;
  expected_minor: number;
  counted_minor: number;
  variance_minor: number;
  review_note?: string | null;
  lines: CashUpLineView[];
}

function idempotencyKey(): string {
  return crypto.randomUUID();
}

export const useCashUpStore = defineStore('cashUp', () => {
  const cashUps = ref<CashUpView[]>([]);
  const current = ref<CashUpView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    cashUps.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  /** Finance review inbox. */
  async function fetchCashUps(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-business_date' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: CashUpView[] }>('/cash-ups', { params });
      cashUps.value = data.data;
    } catch {
      error.value = 'Unable to load cash-ups.';
    } finally {
      loading.value = false;
    }
  }

  /** Branch-day view (persisted cash-up or a server-computed expected preview). */
  async function fetchBranchDay(branchId: string, date: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: CashUpView }>(`/branches/${branchId}/cash-ups/${date}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the branch-day cash-up.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchCashUp(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: CashUpView }>(`/cash-ups/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the cash-up.';
    } finally {
      loading.value = false;
    }
  }

  /** Branch Manager: save the counted amounts for the branch-day draft. */
  async function saveDraft(branchId: string, date: string, counts: Array<{ method: string; counted_minor: number }>): Promise<CashUpView> {
    const { data } = await apiClient.put<{ data: CashUpView }>(`/branches/${branchId}/cash-ups/${date}`, { counts });
    current.value = data.data;
    return data.data;
  }

  async function action(id: string, verb: 'submit' | 'resubmit' | 'approve' | 'lock', body?: Record<string, unknown>): Promise<CashUpView> {
    const { data } = await apiClient.post<{ data: CashUpView }>(`/cash-ups/${id}/${verb}`, body ?? {}, {
      headers: { 'Idempotency-Key': idempotencyKey() },
    });
    current.value = data.data;
    return data.data;
  }

  async function decide(id: string, verb: 'reject' | 'request-correction', reason: string): Promise<CashUpView> {
    const { data } = await apiClient.post<{ data: CashUpView }>(`/cash-ups/${id}/${verb}`, { reason }, {
      headers: { 'Idempotency-Key': idempotencyKey() },
    });
    current.value = data.data;
    return data.data;
  }

  return {
    cashUps,
    current,
    loading,
    error,
    filterStatus,
    $reset,
    fetchCashUps,
    fetchBranchDay,
    fetchCashUp,
    saveDraft,
    action,
    decide,
  };
});
