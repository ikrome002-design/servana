import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Phase 20G Finance COMPENSATION-LIABILITY read surface + manual adjustment (Plan §61/§80, §19.3;
 * `compensation.liability.view` + `compensation.adjustment.create`). UX state only — the API
 * (CompensationLiabilityController/CompensationAdjustmentController + policies + EnsurePermission +
 * RequireFreshMfa + EnsureIdempotentRequest) is the security boundary and re-authorizes every call.
 *
 * The browser NEVER computes an authoritative salary, commission, reversal, adjustment or net-liability
 * amount. It formats the server's integer minor units and renders the server `/summary` per-currency
 * totals verbatim. Different currencies are never combined. A manual adjustment is a STANDALONE additive
 * financial fact (positive OR negative signed `amount_minor`); the server derives branch, type, actors and
 * status. There is no update/delete — the ledgers are append-only.
 */
export type LiabilityEntry = components['schemas']['CompensationLiabilityEntryResource'];
export type CompensationAdjustment = components['schemas']['CompensationAdjustmentResource'];

/**
 * The `/summary` response is a raw server-derived projection (not a Resource), so its per-currency row
 * shape is declared here — mirrors {@see CompensationLiabilityReadModel::summary}. Every field is an
 * integer minor unit except `currency`. Never summed across currencies.
 */
export interface LiabilitySummaryRow {
  currency: string;
  gross_salary_accrual_minor: number;
  salary_reversal_minor: number;
  net_salary_liability_minor: number;
  gross_earned_commission_minor: number;
  commission_reversal_minor: number;
  net_commission_liability_minor: number;
  compensation_adjustment_minor: number;
  combined_net_liability_minor: number;
}

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

/** Filters mirror CompensationLiabilityIndexRequest — only fields the contract declares are ever sent. */
export interface LiabilityFilters {
  liability_type: string;
  staff_profile_ulid: string;
  branch_ulid: string;
  entry_type: string;
  status: string;
  currency: string;
  date_from: string;
  date_to: string;
}

export interface CreateAdjustmentPayload {
  staff_profile_ulid: string;
  /** Signed integer minor units (positive increases liability, negative reduces it); never zero. */
  amount_minor: number;
  currency: string;
  reason: string;
}

const EMPTY_META: PageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

function emptyFilters(): LiabilityFilters {
  return {
    liability_type: '',
    staff_profile_ulid: '',
    branch_ulid: '',
    entry_type: '',
    status: '',
    currency: '',
    date_from: '',
    date_to: '',
  };
}

/** Only non-empty filter values become query params — an unknown/blank field is never sent. */
function toParams(filters: LiabilityFilters): Record<string, string> {
  const params: Record<string, string> = {};
  (Object.keys(filters) as Array<keyof LiabilityFilters>).forEach((key) => {
    const value = filters[key];
    if (value !== '') params[key] = value;
  });
  return params;
}

export const useCompensationLiabilityStore = defineStore('compensationLiability', () => {
  const summary = ref<LiabilitySummaryRow[]>([]);
  const entries = ref<LiabilityEntry[]>([]);
  const entriesMeta = ref<PageMeta>({ ...EMPTY_META });
  const adjustments = ref<CompensationAdjustment[]>([]);
  const adjustmentsMeta = ref<PageMeta>({ ...EMPTY_META });
  const currentAdjustment = ref<CompensationAdjustment | null>(null);

  const summaryLoading = ref(false);
  const entriesLoading = ref(false);
  const adjustmentsLoading = ref(false);
  const creating = ref(false);

  const summaryError = ref<string | null>(null);
  const entriesError = ref<string | null>(null);
  const adjustmentsError = ref<string | null>(null);
  /** A read that returns 403 sets this so the screen can render a safe forbidden state, not a blank one. */
  const forbidden = ref(false);

  const filters = ref<LiabilityFilters>(emptyFilters());

  // Idempotency-Key lifecycle for the single financial mutation. The key is minted on first submit and
  // REUSED for a network retry of the same payload; a materially changed payload mints a new key; a
  // successful create retires it. The UI never sees the key.
  let pendingKey: string | null = null;
  let pendingHash: string | null = null;

  function $reset(): void {
    summary.value = [];
    entries.value = [];
    entriesMeta.value = { ...EMPTY_META };
    adjustments.value = [];
    adjustmentsMeta.value = { ...EMPTY_META };
    currentAdjustment.value = null;
    summaryLoading.value = false;
    entriesLoading.value = false;
    adjustmentsLoading.value = false;
    creating.value = false;
    summaryError.value = null;
    entriesError.value = null;
    adjustmentsError.value = null;
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

  async function fetchSummary(): Promise<void> {
    summaryLoading.value = true;
    summaryError.value = null;
    try {
      // The summary shares the read filters that the read model honours (staff/currency/branch/type).
      const { data } = await apiClient.get<{ data: LiabilitySummaryRow[] }>(
        '/compensation/liabilities/summary',
        { params: toParams(filters.value) },
      );
      summary.value = data.data;
    } catch (err) {
      if (!noteForbidden(err)) summaryError.value = 'Unable to load the liability summary.';
    } finally {
      summaryLoading.value = false;
    }
  }

  async function fetchEntries(page = entriesMeta.value.current_page): Promise<void> {
    entriesLoading.value = true;
    entriesError.value = null;
    try {
      const { data } = await apiClient.get<Paginated<LiabilityEntry>>('/compensation/liabilities', {
        params: { ...toParams(filters.value), page },
      });
      entries.value = data.data;
      entriesMeta.value = data.meta ?? { ...EMPTY_META, current_page: page };
    } catch (err) {
      if (!noteForbidden(err)) entriesError.value = 'Unable to load liability entries.';
    } finally {
      entriesLoading.value = false;
    }
  }

  async function fetchAdjustments(page = adjustmentsMeta.value.current_page): Promise<void> {
    adjustmentsLoading.value = true;
    adjustmentsError.value = null;
    try {
      // Adjustments honour their own contract filters (staff/branch/type/currency).
      const params: Record<string, string | number> = { page };
      if (filters.value.staff_profile_ulid !== '') params.staff_profile_ulid = filters.value.staff_profile_ulid;
      if (filters.value.branch_ulid !== '') params.branch_ulid = filters.value.branch_ulid;
      if (filters.value.currency !== '') params.currency = filters.value.currency;
      const { data } = await apiClient.get<Paginated<CompensationAdjustment>>('/compensation/adjustments', { params });
      adjustments.value = data.data;
      adjustmentsMeta.value = data.meta ?? { ...EMPTY_META, current_page: page };
    } catch (err) {
      if (!noteForbidden(err)) adjustmentsError.value = 'Unable to load compensation adjustments.';
    } finally {
      adjustmentsLoading.value = false;
    }
  }

  async function fetchAdjustment(id: string): Promise<CompensationAdjustment> {
    const { data } = await apiClient.get<{ data: CompensationAdjustment }>(`/compensation/adjustments/${id}`);
    currentAdjustment.value = data.data;
    return data.data;
  }

  /**
   * Re-run every read for the current filters + reset to page 1. Called when a filter materially changes
   * so a stale page never lingers.
   */
  async function applyFilters(): Promise<void> {
    entriesMeta.value.current_page = 1;
    adjustmentsMeta.value.current_page = 1;
    forbidden.value = false;
    await Promise.all([fetchSummary(), fetchEntries(1), fetchAdjustments(1)]);
  }

  function resetFilters(): void {
    filters.value = emptyFilters();
  }

  /**
   * Create a STANDALONE manual compensation adjustment. Idempotent by construction: the same payload
   * reuses its key on retry; a changed payload mints a new key; success retires the key. Only the server
   * response mutates local state, so a rejected create (stale step-up, period lock, validation) never
   * leaves a phantom row. Errors are rethrown for the screen to map to safe copy.
   */
  async function createAdjustment(payload: CreateAdjustmentPayload): Promise<CompensationAdjustment> {
    const hash = JSON.stringify(payload);
    if (pendingKey === null || pendingHash !== hash) {
      pendingKey = crypto.randomUUID();
      pendingHash = hash;
    }
    creating.value = true;
    try {
      const { data } = await apiClient.post<{ data: CompensationAdjustment }>(
        '/compensation/adjustments',
        payload,
        { headers: { 'Idempotency-Key': pendingKey } },
      );
      // Retire the key only on a settled success.
      pendingKey = null;
      pendingHash = null;
      currentAdjustment.value = data.data;
      return data.data;
    } finally {
      creating.value = false;
    }
  }

  return {
    summary,
    entries,
    entriesMeta,
    adjustments,
    adjustmentsMeta,
    currentAdjustment,
    summaryLoading,
    entriesLoading,
    adjustmentsLoading,
    creating,
    summaryError,
    entriesError,
    adjustmentsError,
    forbidden,
    filters,
    $reset,
    fetchSummary,
    fetchEntries,
    fetchAdjustments,
    fetchAdjustment,
    applyFilters,
    resetFilters,
    createAdjustment,
  };
});
