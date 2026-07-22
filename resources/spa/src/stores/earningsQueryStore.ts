import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Phase 20H earnings-query UX (Plan §63, §19.3; §H12; D-H12-1). State only — the API is the security
 * boundary. Personnel raise + read their OWN queries (`personnel.my_earnings_query.create`); Finance is
 * the sole authoritative responder (`earnings_query.respond`) and a monetary correction is created ONLY
 * as an additive compensation adjustment (never a ledger edit). The browser never edits a ledger amount
 * and never sends a server-owned field. Respond is idempotent (a replay never duplicates a correction).
 */
export type EarningsQuery = components['schemas']['EarningsQueryResource'];

export type QueryContext = 'personnel' | 'finance';

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

export interface CreateQueryPayload {
  subject_type: string;
  subject_ulid: string;
  query_type: string;
  body: string;
}

export interface RespondPayload {
  decision: 'resolved' | 'rejected';
  resolution_note: string;
  correction?: { amount_minor: number; currency: string; reason: string };
}

const EMPTY_META: PageMeta = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

function basePath(context: QueryContext): string {
  return context === 'finance' ? '/finance/earnings-queries' : '/personnel/me/earnings-queries';
}

export const useEarningsQueryStore = defineStore('earningsQuery', () => {
  const queries = ref<EarningsQuery[]>([]);
  const meta = ref<PageMeta>({ ...EMPTY_META });
  const currentQuery = ref<EarningsQuery | null>(null);
  const statusFilter = ref('');

  const listLoading = ref(false);
  const detailLoading = ref(false);
  const mutating = ref(false);

  const listError = ref<string | null>(null);
  const detailError = ref<string | null>(null);
  const forbidden = ref(false);

  // Idempotency-Key lifecycle for the Finance respond mutation (it may create a compensation adjustment).
  let pendingKey: string | null = null;
  let pendingHash: string | null = null;

  function $reset(): void {
    queries.value = [];
    meta.value = { ...EMPTY_META };
    currentQuery.value = null;
    statusFilter.value = '';
    listLoading.value = false;
    detailLoading.value = false;
    mutating.value = false;
    listError.value = null;
    detailError.value = null;
    forbidden.value = false;
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

  async function fetchQueries(context: QueryContext, page = meta.value.current_page): Promise<void> {
    listLoading.value = true;
    listError.value = null;
    try {
      const params: Record<string, string | number> = { page };
      if (statusFilter.value !== '') params.status = statusFilter.value;
      const { data } = await apiClient.get<Paginated<EarningsQuery>>(basePath(context), { params });
      queries.value = data.data;
      meta.value = data.meta ?? { ...EMPTY_META, current_page: page };
    } catch (err) {
      if (!noteForbidden(err)) listError.value = 'Unable to load earnings queries.';
    } finally {
      listLoading.value = false;
    }
  }

  async function fetchQuery(context: QueryContext, id: string): Promise<EarningsQuery | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const { data } = await apiClient.get<{ data: EarningsQuery }>(`${basePath(context)}/${id}`);
      currentQuery.value = data.data;
      return data.data;
    } catch (err) {
      if (!noteForbidden(err)) detailError.value = 'Unable to load this earnings query.';
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  /** Personnel: raise an own-scope earnings query. Only the server response mutates local state. */
  async function createQuery(payload: CreateQueryPayload): Promise<EarningsQuery> {
    mutating.value = true;
    try {
      const { data } = await apiClient.post<{ data: EarningsQuery }>('/personnel/me/earnings-queries', payload);
      currentQuery.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  /**
   * Finance: resolve/reject a query, optionally with an additive monetary correction. Idempotent by
   * construction — the same decision+note+correction reuses its key on retry; success retires it; a
   * terminal query fails closed server-side so a replay never duplicates the adjustment.
   */
  async function respond(id: string, payload: RespondPayload): Promise<EarningsQuery> {
    const hash = `${id}:${JSON.stringify(payload)}`;
    if (pendingKey === null || pendingHash !== hash) {
      pendingKey = crypto.randomUUID();
      pendingHash = hash;
    }
    mutating.value = true;
    try {
      const { data } = await apiClient.post<{ data: EarningsQuery }>(
        `/finance/earnings-queries/${id}/respond`,
        payload,
        { headers: { 'Idempotency-Key': pendingKey } },
      );
      pendingKey = null;
      pendingHash = null;
      currentQuery.value = data.data;
      return data.data;
    } finally {
      mutating.value = false;
    }
  }

  return {
    queries,
    meta,
    currentQuery,
    statusFilter,
    listLoading,
    detailLoading,
    mutating,
    listError,
    detailError,
    forbidden,
    $reset,
    fetchQueries,
    fetchQuery,
    createQuery,
    respond,
  };
});
