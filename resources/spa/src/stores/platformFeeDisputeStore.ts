import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Percentage platform-fee DISPUTE workflow (Plan §13.10 [Correction 3]; Phase 20E). UX state only —
 * `PlatformFeeDisputePolicy` is authoritative. Merchant Admin/Finance may CREATE a dispute
 * (`platform_fee.dispute`); Finance reviews/resolves/rejects (`platform_fee.dispute.review`, fresh
 * step-up on resolve/reject). A money-changing resolution creates an additive `platform_fee_adjustment`
 * on the backend — the browser never edits the original ledger amount. Canonical states: open,
 * under_review, resolved, rejected (there is NO `escalated`).
 */
export type PlatformFeeDispute = components['schemas']['PlatformFeeDisputeResource'];

export interface CreateDisputePayload {
  platform_fee_ledger_entry?: string | null;
  subscription_invoice?: string | null;
  reason: string;
  evidence_file?: string | null;
}

const BASE = '/platform-fee-disputes';

export const usePlatformFeeDisputeStore = defineStore('platformFeeDispute', () => {
  const disputes = ref<PlatformFeeDispute[]>([]);
  const current = ref<PlatformFeeDispute | null>(null);
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
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: PlatformFeeDispute[] }>(BASE, { params });
      disputes.value = data.data;
    } catch {
      error.value = 'Unable to load platform-fee disputes.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchDispute(id: string): Promise<PlatformFeeDispute> {
    const { data } = await apiClient.get<{ data: PlatformFeeDispute }>(`${BASE}/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createDispute(payload: CreateDisputePayload): Promise<PlatformFeeDispute> {
    const { data } = await apiClient.post<{ data: PlatformFeeDispute }>(BASE, payload);
    current.value = data.data;
    return data.data;
  }

  async function startReview(id: string): Promise<PlatformFeeDispute> {
    const { data } = await apiClient.post<{ data: PlatformFeeDispute }>(`${BASE}/${id}/review`, {});
    current.value = data.data;
    return data.data;
  }

  /** Resolve — an OPTIONAL server-validated `money_change_amount_minor` drives an additive adjustment. */
  async function resolve(id: string, resolutionNote: string, moneyChangeAmountMinor?: number | null): Promise<PlatformFeeDispute> {
    const payload: Record<string, unknown> = { resolution_note: resolutionNote };
    if (moneyChangeAmountMinor !== null && moneyChangeAmountMinor !== undefined) {
      payload.money_change_amount_minor = moneyChangeAmountMinor;
    }
    const { data } = await apiClient.post<{ data: PlatformFeeDispute }>(`${BASE}/${id}/resolve`, payload);
    current.value = data.data;
    return data.data;
  }

  async function reject(id: string, resolutionNote: string): Promise<PlatformFeeDispute> {
    const { data } = await apiClient.post<{ data: PlatformFeeDispute }>(`${BASE}/${id}/reject`, { resolution_note: resolutionNote });
    current.value = data.data;
    return data.data;
  }

  return {
    disputes,
    current,
    loading,
    error,
    filterStatus,
    $reset,
    fetchDisputes,
    fetchDispute,
    createDispute,
    startReview,
    resolve,
    reject,
  };
});
