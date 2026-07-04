import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Finance exports (Plan §65, §67; Phase 18B). UX state only — the API
 * (FinanceExportPolicy + FileAccessService signed-download boundary) is authoritative.
 * Finance requests a scoped, masked export (a fresh MFA step-up is server-enforced),
 * generated async; downloads go through a short-lived authorized signed link (never
 * stored). Only invoices/payments/receipts/cash_up/refunds/disputes are requestable —
 * compensation/payouts/billing are not offered. `finance_export.*` is `PL n/a`.
 */
export interface FinanceExportView {
  id: string;
  export_type: string;
  scope: 'merchant' | 'branch';
  branch?: { id: string; name: string } | null;
  status: string;
  reason: string;
  row_count: number | null;
  download_count: number;
  expires_at: string | null;
  first_downloaded_at: string | null;
  last_downloaded_at: string | null;
  failure_code: string | null;
  failure_message: string | null;
  created_at: string | null;
}

/** The only requestable export types this phase (compensation/payouts/billing excluded). */
export const SUPPORTED_EXPORT_TYPES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'invoices', label: 'Invoices' },
  { value: 'payments', label: 'Payments' },
  { value: 'receipts', label: 'Receipts' },
  { value: 'cash_up', label: 'Cash-up' },
  { value: 'refunds', label: 'Refunds' },
  { value: 'disputes', label: 'Disputes' },
];

export const useFinanceExportStore = defineStore('financeExport', () => {
  const exports = ref<FinanceExportView[]>([]);
  const current = ref<FinanceExportView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    exports.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchExports(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FinanceExportView[] }>('/finance-exports', { params });
      exports.value = data.data;
    } catch {
      error.value = 'Unable to load exports.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchExport(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: FinanceExportView }>(`/finance-exports/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the export.';
    } finally {
      loading.value = false;
    }
  }

  /** Request an export. A fresh MFA step-up is enforced by the server. */
  async function request(payload: { export_type: string; branch?: string; reason: string }): Promise<FinanceExportView> {
    const { data } = await apiClient.post<{ data: FinanceExportView }>('/finance-exports', payload);
    current.value = data.data;
    return data.data;
  }

  /** Request a short-lived authorized signed download link (never stored). */
  async function downloadLink(id: string): Promise<string> {
    const { data } = await apiClient.post<{ data: { url: string } }>(`/finance-exports/${id}/download-link`, {});
    return data.data.url;
  }

  async function revoke(id: string): Promise<FinanceExportView> {
    const { data } = await apiClient.post<{ data: FinanceExportView }>(`/finance-exports/${id}/revoke`, {});
    current.value = data.data;
    return data.data;
  }

  return { exports, current, loading, error, filterStatus, $reset, fetchExports, fetchExport, request, downloadLink, revoke };
});
