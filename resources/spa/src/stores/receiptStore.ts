import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Receipts (Plan §43; Phase 18B). UX state only — the API (ReceiptPolicy +
 * FileAccessService signed-download boundary) is authoritative. Receipts are issued
 * AUTOMATICALLY on payment validation; there is NO manual issue action. Finance may
 * reissue (a new gap-free number referencing the original); Finance and Front Office
 * may view + download via a short-lived authorized signed link. The reissue POST sends
 * a client-generated `Idempotency-Key`.
 */
export interface MoneyView {
  amount: number;
  currency: string;
  formatted: string;
}

export interface ReceiptComponentView {
  method: string;
  amount: MoneyView;
}

export interface ReceiptView {
  id: string;
  receipt_number: number;
  amount: MoneyView;
  currency: string;
  components?: ReceiptComponentView[];
  is_reissue: boolean;
  reissue_of?: string | null;
  reason?: string | null;
  downloadable: boolean;
  file_generation_status: string;
  created_at: string | null;
  invoice?: { id: string; invoice_number: string | null };
}

export const useReceiptStore = defineStore('receipt', () => {
  const receipts = ref<ReceiptView[]>([]);
  const current = ref<ReceiptView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    receipts.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
  }

  async function fetchReceipts(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: ReceiptView[] }>('/receipts', { params: { sort: '-created_at' } });
      receipts.value = data.data;
    } catch {
      error.value = 'Unable to load receipts.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchReceipt(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: ReceiptView }>(`/receipts/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the receipt.';
    } finally {
      loading.value = false;
    }
  }

  async function reissue(id: string, reason: string): Promise<ReceiptView> {
    const { data } = await apiClient.post<{ data: ReceiptView }>(`/receipts/${id}/reissue`, { reason }, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  /** Request a short-lived authorized signed download link (never stored). */
  async function downloadLink(id: string): Promise<string> {
    const { data } = await apiClient.post<{ data: { url: string } }>(`/receipts/${id}/download-link`, {});
    return data.data.url;
  }

  return { receipts, current, loading, error, $reset, fetchReceipts, fetchReceipt, reissue, downloadLink };
});
