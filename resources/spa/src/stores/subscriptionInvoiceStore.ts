import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Merchant subscription invoices + PDF state (Plan §49; Phase 20B). UX state only — the API
 * (SubscriptionInvoicePolicy + the Phase 10F FileAccessService) is the security boundary. Amounts
 * are integer minor units; the invoice financial snapshot is immutable. `payment_reference_pending`
 * is true until a Wallet reference exists (20D-W) — the UI shows the exact pending-reference text.
 *
 * GENERATION and DOWNLOAD are strictly separate:
 *   - generatePdf(): a durable MUTATION; blocked server-side in `read_only_grace`/`suspended_billing`
 *     (403 `billing_read_only`), surfaced to the caller.
 *   - downloadLink(): a READ/download action; allowed even in billing read-only states.
 * No Wallet payment / STK / PayBill-Till / provider / payment-attempt / reconciliation state exists.
 */
export type SubscriptionInvoice = components['schemas']['SubscriptionInvoiceResource'];

/** Exact copy shown while a Wallet reference is not yet available (Plan §49; ADR-014). */
export const PAYMENT_REFERENCE_PENDING_TEXT = 'Payment reference pending — see your billing dashboard';

export const useSubscriptionInvoiceStore = defineStore('subscriptionInvoice', () => {
  const invoices = ref<SubscriptionInvoice[]>([]);
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
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: SubscriptionInvoice[] }>('/subscription-invoices', { params });
      invoices.value = data.data;
    } catch {
      error.value = 'Unable to load subscription invoices.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchInvoice(id: string): Promise<SubscriptionInvoice> {
    const { data } = await apiClient.get<{ data: SubscriptionInvoice }>(`/subscription-invoices/${id}`);
    return data.data;
  }

  /** Generate (or regenerate) the invoice PDF — a mutation, blocked in billing read-only states. */
  async function generatePdf(id: string): Promise<SubscriptionInvoice> {
    const { data } = await apiClient.post<{ data: SubscriptionInvoice }>(`/subscription-invoices/${id}/pdf`);
    const idx = invoices.value.findIndex((i) => i.id === id);
    if (idx !== -1) invoices.value[idx] = data.data;
    return data.data;
  }

  /** Issue a short-lived signed download link for an EXISTING PDF (read; allowed in read-only). */
  async function downloadLink(id: string): Promise<{ url: string; expires_at: string }> {
    const { data } = await apiClient.get<{ data: { url: string; expires_at: string } }>(
      `/subscription-invoices/${id}/pdf/download-link`,
    );
    return data.data;
  }

  return {
    invoices,
    loading,
    error,
    filterStatus,
    PAYMENT_REFERENCE_PENDING_TEXT,
    $reset,
    fetchInvoices,
    fetchInvoice,
    generatePdf,
    downloadLink,
  };
});
