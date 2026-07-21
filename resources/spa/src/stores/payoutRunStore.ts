import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Phase 20H payout-run UX surface (Plan §62, §25.5, §19.3). State only — the API
 * (Hr/Finance/MerchantCompensationController + policies + EnsurePermission + RequireFreshMfa +
 * EnsureIdempotentRequest) is the security boundary and re-authorizes every call. **Servana moves no
 * money** — mark-paid records an EXTERNAL settlement outcome.
 *
 * The browser NEVER computes an authoritative payout total, snapshots items, or resolves eligibility: it
 * formats the server's integer minor units and renders the server-snapshotted run/items verbatim. HR
 * reads/writes drafts under `/hr`; Finance verifies/approves/rejects/marks-paid under `/finance`; the
 * Merchant Administrator reads the high-value queue and approves under `/merchant`. Each context is a
 * distinct role-owned route tree; the store never lets a role reach another's endpoint.
 */
export type PayoutRun = components['schemas']['PersonnelPayoutRunResource'];

export type PayoutContext = 'hr' | 'finance' | 'merchant';

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

/** Filters mirror PayoutRunIndexRequest — only fields the contract declares are ever sent. */
export interface PayoutFilters {
  status: string;
  currency: string;
  branch_ulid: string;
}

export interface CreateRunPayload {
  branch_ulid: string;
  period_start: string;
  period_end: string;
  currency: string;
}

export interface UpdateRunPayload {
  period_start: string;
  period_end: string;
  currency: string;
}

export interface MarkPaidPayload {
  external_payment_reference: string;
  paid_date: string;
}

const EMPTY_META: PageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

function emptyFilters(): PayoutFilters {
  return { status: '', currency: '', branch_ulid: '' };
}

function toParams(filters: PayoutFilters): Record<string, string> {
  const params: Record<string, string> = {};
  (Object.keys(filters) as Array<keyof PayoutFilters>).forEach((key) => {
    if (filters[key] !== '') params[key] = filters[key];
  });
  return params;
}

export const usePayoutRunStore = defineStore('payoutRun', () => {
  const runs = ref<PayoutRun[]>([]);
  const meta = ref<PageMeta>({ ...EMPTY_META });
  const currentRun = ref<PayoutRun | null>(null);

  const listLoading = ref(false);
  const detailLoading = ref(false);
  const mutating = ref(false);

  const listError = ref<string | null>(null);
  const detailError = ref<string | null>(null);
  /** A read that returns 403 sets this so the screen renders a safe forbidden state, not a blank one. */
  const forbidden = ref(false);

  const filters = ref<PayoutFilters>(emptyFilters());

  // Idempotency-Key lifecycle for financial mutations. A key is minted on first submit of a given
  // (action + run + payload); a network retry of the same submit reuses it; a settled success retires it.
  let pendingKey: string | null = null;
  let pendingHash: string | null = null;

  function $reset(): void {
    runs.value = [];
    meta.value = { ...EMPTY_META };
    currentRun.value = null;
    listLoading.value = false;
    detailLoading.value = false;
    mutating.value = false;
    listError.value = null;
    detailError.value = null;
    forbidden.value = false;
    filters.value = emptyFilters();
    pendingKey = null;
    pendingHash = null;
  }

  function noteForbidden(err: unknown): boolean {
    if (axios.isAxiosError(err) && err.response?.status === 403) {
      forbidden.value = true;
      return true;
    }
    return false;
  }

  /** Idempotent-key header for a financial mutation, keyed by a stable action+payload hash. */
  function idempotentHeaders(hash: string): { 'Idempotency-Key': string } {
    if (pendingKey === null || pendingHash !== hash) {
      pendingKey = crypto.randomUUID();
      pendingHash = hash;
    }
    return { 'Idempotency-Key': pendingKey };
  }

  function retireKey(): void {
    pendingKey = null;
    pendingHash = null;
  }

  async function fetchRuns(context: PayoutContext, page = meta.value.current_page): Promise<void> {
    listLoading.value = true;
    listError.value = null;
    try {
      const { data } = await apiClient.get<Paginated<PayoutRun>>(`/${context}/payout-runs`, {
        params: { ...toParams(filters.value), page },
      });
      runs.value = data.data;
      meta.value = data.meta ?? { ...EMPTY_META, current_page: page };
    } catch (err) {
      if (!noteForbidden(err)) listError.value = 'Unable to load payout runs.';
    } finally {
      listLoading.value = false;
    }
  }

  async function fetchRun(context: PayoutContext, id: string): Promise<PayoutRun | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const { data } = await apiClient.get<{ data: PayoutRun }>(`/${context}/payout-runs/${id}`);
      currentRun.value = data.data;
      return data.data;
    } catch (err) {
      if (!noteForbidden(err)) detailError.value = 'Unable to load this payout run.';
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  async function applyFilters(context: PayoutContext): Promise<void> {
    meta.value.current_page = 1;
    forbidden.value = false;
    if (filters.value.currency !== '') filters.value.currency = filters.value.currency.toUpperCase();
    await fetchRuns(context, 1);
  }

  function resetFilters(): void {
    filters.value = emptyFilters();
  }

  // ---- HR draft mutations (branch scope; no idempotency key — configuration-shaped) ----------------

  async function createDraft(payload: CreateRunPayload): Promise<PayoutRun> {
    mutating.value = true;
    try {
      const { data } = await apiClient.post<{ data: PayoutRun }>('/hr/payout-runs', payload);
      currentRun.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  async function updateDraft(id: string, payload: UpdateRunPayload): Promise<PayoutRun> {
    mutating.value = true;
    try {
      const { data } = await apiClient.patch<{ data: PayoutRun }>(`/hr/payout-runs/${id}`, payload);
      currentRun.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  async function submitDraft(id: string): Promise<PayoutRun> {
    mutating.value = true;
    try {
      const { data } = await apiClient.post<{ data: PayoutRun }>(`/hr/payout-runs/${id}/submit`);
      currentRun.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  async function cancelDraft(id: string): Promise<PayoutRun> {
    mutating.value = true;
    try {
      const { data } = await apiClient.post<{ data: PayoutRun }>(`/hr/payout-runs/${id}/cancel`);
      currentRun.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  // ---- Finance financial mutations (fresh step-up + Idempotency-Key server-enforced) ---------------

  async function verify(id: string): Promise<PayoutRun> {
    return financialAction('POST', `/finance/payout-runs/${id}/verify`, `verify:${id}`);
  }

  async function approve(id: string): Promise<PayoutRun> {
    return financialAction('POST', `/finance/payout-runs/${id}/approve`, `approve:${id}`);
  }

  async function reject(id: string, reason: string): Promise<PayoutRun> {
    return financialAction('POST', `/finance/payout-runs/${id}/reject`, `reject:${id}:${reason}`, { reason });
  }

  async function markPaid(id: string, payload: MarkPaidPayload): Promise<PayoutRun> {
    return financialAction('POST', `/finance/payout-runs/${id}/mark-paid`, `mark-paid:${id}:${JSON.stringify(payload)}`, payload);
  }

  async function approveHighValue(id: string): Promise<PayoutRun> {
    return financialAction('POST', `/merchant/payout-runs/${id}/approve-high-value`, `high-value:${id}`);
  }

  /**
   * Single financial-mutation path: mint/reuse an Idempotency-Key by action+payload hash, POST, and
   * retire the key on a settled success. Only the server response mutates local state, so a rejected
   * call (stale step-up, invalid transition, validation) never leaves a phantom state. Errors are
   * rethrown for the screen to map to safe copy.
   */
  async function financialAction(
    method: 'POST',
    url: string,
    hash: string,
    body: object = {},
  ): Promise<PayoutRun> {
    mutating.value = true;
    try {
      const { data } = await apiClient.request<{ data: PayoutRun }>({
        method,
        url,
        data: body,
        headers: idempotentHeaders(hash),
      });
      retireKey();
      currentRun.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  return {
    runs,
    meta,
    currentRun,
    listLoading,
    detailLoading,
    mutating,
    listError,
    detailError,
    forbidden,
    filters,
    $reset,
    fetchRuns,
    fetchRun,
    applyFilters,
    resetFilters,
    createDraft,
    updateDraft,
    submitDraft,
    cancelDraft,
    verify,
    approve,
    reject,
    markPaid,
    approveHighValue,
  };
});
