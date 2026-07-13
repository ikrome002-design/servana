import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Percentage platform-fee LEDGER read surface (Plan §51, §19.3; Phase 20E). UX state only — the read is
 * masked and SERVER-side scoped (`platform_fee.view`): Merchant Admin/Finance see the whole merchant,
 * Branch Manager/Audit see only branch-attributable entries. The browser NEVER recomputes fee money; it
 * displays the server-authoritative integer minor-unit amounts and the server `/summary` totals.
 */
export type PlatformFeeLedgerEntry = components['schemas']['PlatformFeeLedgerEntryResource'];

export interface PlatformFeeSummaryRow {
  currency: string;
  entry_count: number;
  gross_platform_fee_minor: number;
  client_shifted_amount_minor: number;
  merchant_absorbed_amount_minor: number;
}

export const usePlatformFeeStore = defineStore('platformFee', () => {
  const entries = ref<PlatformFeeLedgerEntry[]>([]);
  const summary = ref<PlatformFeeSummaryRow[]>([]);
  const current = ref<PlatformFeeLedgerEntry | null>(null);
  const loading = ref(false);
  const summaryLoading = ref(false);
  const error = ref<string | null>(null);
  const filterEntryType = ref<string>('');

  function $reset(): void {
    entries.value = [];
    summary.value = [];
    current.value = null;
    loading.value = false;
    summaryLoading.value = false;
    error.value = null;
    filterEntryType.value = '';
  }

  async function fetchEntries(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterEntryType.value !== '') params.entry_type = filterEntryType.value;
      const { data } = await apiClient.get<{ data: PlatformFeeLedgerEntry[] }>('/platform-fees', { params });
      entries.value = data.data;
    } catch {
      error.value = 'Unable to load platform fees.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchSummary(): Promise<void> {
    summaryLoading.value = true;
    try {
      const { data } = await apiClient.get<{ data: PlatformFeeSummaryRow[] }>('/platform-fees/summary');
      summary.value = data.data;
    } catch {
      error.value = 'Unable to load the platform-fee summary.';
    } finally {
      summaryLoading.value = false;
    }
  }

  async function fetchEntry(id: string): Promise<PlatformFeeLedgerEntry> {
    const { data } = await apiClient.get<{ data: PlatformFeeLedgerEntry }>(`/platform-fees/${id}`);
    current.value = data.data;
    return data.data;
  }

  return {
    entries,
    summary,
    current,
    loading,
    summaryLoading,
    error,
    filterEntryType,
    $reset,
    fetchEntries,
    fetchSummary,
    fetchEntry,
  };
});
