import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Financial period locks + controlled reopen (Plan §46; ADR-0007; Phase 18B). UX state
 * only — the API (FinancialPeriodLockPolicy) is authoritative. Finance creates locks
 * (merchant-wide or branch) and executes reopens (fresh MFA); a Merchant Administrator
 * approves an EXCEPTIONAL reopen only. A locked period blocks applicable mutations with
 * 423; reads/receipts/disputes/exports are never locked. Every mutation sends a
 * client-generated `Idempotency-Key`.
 */
export interface PeriodLockView {
  id: string;
  scope: 'merchant' | 'branch';
  branch?: { id: string; name: string } | null;
  period_start: string;
  period_end: string;
  status: string;
  exception_required: boolean;
  reopen_reason: string | null;
  reopen_requested_at: string | null;
  reopen_approved_at: string | null;
  reopened_at: string | null;
  created_at: string | null;
}

export const usePeriodLockStore = defineStore('periodLock', () => {
  const locks = ref<PeriodLockView[]>([]);
  const current = ref<PeriodLockView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    locks.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchLocks(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-period_start' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: PeriodLockView[] }>('/period-locks', { params });
      locks.value = data.data;
    } catch {
      error.value = 'Unable to load financial periods.';
    } finally {
      loading.value = false;
    }
  }

  async function create(payload: { branch?: string; period_start: string; period_end: string; exception_required?: boolean }): Promise<PeriodLockView> {
    const { data } = await apiClient.post<{ data: PeriodLockView }>('/period-locks', payload, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  async function requestReopen(id: string, reason: string): Promise<PeriodLockView> {
    const { data } = await apiClient.post<{ data: PeriodLockView }>(`/period-locks/${id}/reopen`, { reason }, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  /** Merchant Administrator: approve an exceptional reopen (approver != requester). */
  async function approveException(id: string): Promise<PeriodLockView> {
    const { data } = await apiClient.post<{ data: PeriodLockView }>(`/period-locks/${id}/reopen/approve`, {}, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  /** Finance: execute the reopen (requires a fresh MFA step-up, server-enforced). */
  async function execute(id: string): Promise<PeriodLockView> {
    const { data } = await apiClient.post<{ data: PeriodLockView }>(`/period-locks/${id}/reopen/execute`, {}, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  return { locks, current, loading, error, filterStatus, $reset, fetchLocks, create, requestReopen, approveException, execute };
});
