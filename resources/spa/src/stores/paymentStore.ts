import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Merchant-client payment recording (Plan §41; Phase 18A). UX state only — the API
 * (PaymentRecordingGroupPolicy + EnsureBranchScope + billing/period-lock/idempotency
 * gates + the durable duplicate check) is the security boundary. Front Office is the
 * default maker; Finance reads pending groups and overrides duplicates. Recording
 * sends a client-generated `Idempotency-Key` so a retry never records a second group.
 * A recording is NEVER a validation and creates NO receipt — those are Phase 18B.
 */
export interface MoneyView {
  amount: number;
  currency: string;
  formatted: string;
}

export interface PaymentComponentInput {
  method: string;
  amount_minor: number;
  reference?: string;
}

export interface PaymentComponentView {
  id: string;
  method: string;
  amount: MoneyView;
  status: string;
  reference_masked: string | null;
}

export interface PaymentRecordingGroupView {
  id: string;
  status: string;
  is_pending_validation: boolean;
  currency: string;
  total: MoneyView;
  recorded_at: string | null;
  submitted_for_validation_at: string | null;
  maker?: { id: string; name: string };
  invoice?: { id: string; invoice_number: string | null };
  components?: PaymentComponentView[];
}

export interface DuplicateConflict {
  group_id: string;
  method: string;
  masked_reference: string | null;
}

interface PaymentPageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** Methods whose evidence/reference is mandatory (mirrors the server rules, §41). */
export const REFERENCE_METHODS = ['mpesa_offline', 'bank_transfer', 'card_terminal', 'voucher', 'other'];

export const PAYMENT_METHODS: { value: string; label: string }[] = [
  { value: 'cash', label: 'Cash' },
  { value: 'mpesa_offline', label: 'M-Pesa (offline)' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'card_terminal', label: 'Card terminal' },
  { value: 'voucher', label: 'Voucher' },
  { value: 'other', label: 'Other' },
];

export const usePaymentStore = defineStore('payment', () => {
  const groups = ref<PaymentRecordingGroupView[]>([]);
  const groupMeta = ref<PaymentPageMeta | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const duplicate = ref<DuplicateConflict | null>(null);

  function $reset(): void {
    groups.value = [];
    groupMeta.value = null;
    loading.value = false;
    error.value = null;
    duplicate.value = null;
  }

  /** Finance: list the pending recording groups (customer_payment.view). */
  async function fetchGroups(params: Record<string, string | number> = {}): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PaymentRecordingGroupView[]; meta: PaymentPageMeta }>('/payment-recording-groups', { params });
      groups.value = data.data;
      groupMeta.value = data.meta;
    } catch {
      error.value = 'Unable to load payment recordings.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchGroup(id: string): Promise<PaymentRecordingGroupView> {
    const { data } = await apiClient.get<{ data: PaymentRecordingGroupView }>(`/payment-recording-groups/${id}`);
    return data.data;
  }

  /**
   * Front Office: record a payment group against an invoice. Returns the created
   * group, or throws. On a suspected duplicate the server returns 409 and this sets
   * `duplicate` for the caller to surface a warning (Finance override is required).
   */
  async function recordPayment(
    invoiceId: string,
    components: PaymentComponentInput[],
    idempotencyKey?: string,
  ): Promise<PaymentRecordingGroupView> {
    duplicate.value = null;
    const key = idempotencyKey ?? crypto.randomUUID();
    try {
      const { data } = await apiClient.post<{ data: PaymentRecordingGroupView }>(
        `/invoices/${invoiceId}/payment-recording-groups`,
        { components },
        { headers: { 'Idempotency-Key': key } },
      );
      return data.data;
    } catch (e: unknown) {
      const err = e as { response?: { status?: number; data?: { error?: { code?: string; meta?: DuplicateConflict } } } };
      if (err.response?.status === 409 && err.response.data?.error?.code === 'payment_reference_duplicate_suspected') {
        duplicate.value = err.response.data.error.meta ?? null;
      }
      throw e;
    }
  }

  /** Finance: override a suspected-duplicate reference check (requires a fresh step-up). */
  async function overrideDuplicate(checkId: string, reason: string, idempotencyKey?: string): Promise<void> {
    const key = idempotencyKey ?? crypto.randomUUID();
    await apiClient.post(
      `/payment-reference-checks/${checkId}/override`,
      { reason },
      { headers: { 'Idempotency-Key': key } },
    );
  }

  /**
   * Finance checker (Phase 18B): validate a WHOLE pending group — one atomic decision
   * for all components (no partial validation), issuing one original receipt. The
   * recording maker may never validate their own group (server-enforced maker/checker).
   */
  async function validateGroup(id: string): Promise<PaymentRecordingGroupView> {
    const { data } = await apiClient.post<{ data: PaymentRecordingGroupView }>(
      `/payment-recording-groups/${id}/validate`, {}, { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    return data.data;
  }

  /** Finance checker: reject a whole pending group with a mandatory reason (no receipt). */
  async function rejectGroup(id: string, reason: string): Promise<PaymentRecordingGroupView> {
    const { data } = await apiClient.post<{ data: PaymentRecordingGroupView }>(
      `/payment-recording-groups/${id}/reject`, { reason }, { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    return data.data;
  }

  /** Finance checker: return a whole pending group for correction (mandatory reason; no receipt). */
  async function requestCorrection(id: string, reason: string): Promise<PaymentRecordingGroupView> {
    const { data } = await apiClient.post<{ data: PaymentRecordingGroupView }>(
      `/payment-recording-groups/${id}/request-correction`, { reason }, { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    return data.data;
  }

  /** Finance: correct a component payment reference on a correctable group. */
  async function correctReference(recordId: string, reference: string): Promise<void> {
    await apiClient.post(
      `/payment-records/${recordId}/correct-reference`, { reference }, { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
  }

  /** Finance: resubmit a corrected group back to pending_validation. */
  async function resubmitGroup(id: string): Promise<PaymentRecordingGroupView> {
    const { data } = await apiClient.post<{ data: PaymentRecordingGroupView }>(
      `/payment-recording-groups/${id}/resubmit`, {}, { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    return data.data;
  }

  return {
    groups,
    groupMeta,
    loading,
    error,
    duplicate,
    fetchGroups,
    fetchGroup,
    recordPayment,
    overrideDuplicate,
    validateGroup,
    rejectGroup,
    requestCorrection,
    correctReference,
    resubmitGroup,
    $reset,
  };
});
