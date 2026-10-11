import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Audit event reads (Plan §70, §74; Phase 19; Phase UI-15). UX state only — the API
 * (AuditLogPolicy + EnsurePermission + branch scope) is authoritative. Reads are
 * branch-scoped, field-masked, and domain-segmented server-side; merchant-level
 * (branch_id = null) rows are never returned. This store never receives or stores
 * raw before/after/context — only the masked payload the resource emits.
 *
 * Domains map to the three canonical read segments / endpoints:
 *   - general      → GET /audit-logs                 (audit.branch_events.view)
 *   - finance      → GET /audit-logs/finance         (finance.audit.view | audit.finance.view)
 *   - compensation → GET /audit-logs/compensation    (audit.compensation.view)
 *
 * Phase UI-15 adds the server-decided `view` lens (operational / access / privacy_preferred),
 * the `flagged` review-state filter and `correlation_id`. They narrow server-side; the browser
 * never filters a page of results and calls it a view.
 */
export type AuditDomain = 'general' | 'finance' | 'compensation';
export type AuditReviewViewKey = 'operational' | 'access' | 'privacy_preferred';

export interface AuditReviewSummary {
  flag: string;
  status: string;
  flag_count: number;
}

export interface AuditEventView {
  id: string;
  action: string;
  severity: string;
  actor: string | null;
  branch: string | null;
  subject_type: string | null;
  context: Record<string, unknown>;
  correlation_id: string | null;
  created_at: string | null;
  /** Mutable review status of the latest flag — never the event's own severity. */
  review?: AuditReviewSummary | null;
  can?: { view?: boolean };
}

export interface AuditEventFilters {
  action?: string;
  severity?: string;
  branch?: string;
  subject_type?: string;
  date_from?: string;
  date_to?: string;
  sort?: string;
  view?: AuditReviewViewKey | '';
  flagged?: 'flagged' | 'unflagged' | '';
  correlation_id?: string;
}

export interface AuditPageMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page?: number;
}

export interface AuditEventPage {
  data: AuditEventView[];
  meta: AuditPageMeta;
}

const ENDPOINT: Record<AuditDomain, string> = {
  general: '/audit-logs',
  finance: '/audit-logs/finance',
  compensation: '/audit-logs/compensation',
};

/** The filter keys the backend allowlists; anything else is dropped before the request. */
const FILTER_KEYS: ReadonlyArray<keyof AuditEventFilters> = [
  'action', 'severity', 'branch', 'subject_type', 'date_from', 'date_to', 'sort', 'view', 'flagged', 'correlation_id',
];

export function auditQueryParams(filters: AuditEventFilters, page = 1, perPage?: number): Record<string, string | number> {
  const params: Record<string, string | number> = { page };
  if (perPage !== undefined) params.per_page = perPage;
  for (const key of FILTER_KEYS) {
    const value = filters[key];
    if (typeof value === 'string' && value !== '') params[key] = value;
  }
  return params;
}

/**
 * One stateless page read, for surfaces (the dashboard) that compose several independent slices
 * and must not clobber the shared list state. `meta.total` is the server's count for exactly these
 * filters — never a client tally of the returned page.
 */
export async function fetchAuditEventPage(domain: AuditDomain, filters: AuditEventFilters, perPage = 5): Promise<AuditEventPage> {
  const { data } = await apiClient.get<{ data: AuditEventView[]; meta?: AuditPageMeta }>(ENDPOINT[domain], {
    params: auditQueryParams(filters, 1, perPage),
  });
  return {
    data: data.data,
    meta: data.meta ?? { current_page: 1, last_page: 1, total: data.data.length },
  };
}

export const useAuditEventStore = defineStore('auditEvent', () => {
  const events = ref<AuditEventView[]>([]);
  const current = ref<AuditEventView | null>(null);
  const meta = ref<AuditPageMeta>({ current_page: 1, last_page: 1, total: 0 });
  const loading = ref(false);
  const error = ref<string | null>(null);
  const currentError = ref<'not_found' | 'forbidden' | 'failed' | null>(null);
  const filters = ref<AuditEventFilters>({ sort: '-created_at' });

  function $reset(): void {
    events.value = [];
    current.value = null;
    meta.value = { current_page: 1, last_page: 1, total: 0 };
    loading.value = false;
    error.value = null;
    currentError.value = null;
    filters.value = { sort: '-created_at' };
  }

  async function fetchEvents(domain: AuditDomain = 'general', page = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: AuditEventView[]; meta?: AuditPageMeta }>(ENDPOINT[domain], {
        params: auditQueryParams(filters.value, page),
      });
      events.value = data.data;
      if (data.meta) {
        meta.value = { current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total, per_page: data.meta.per_page };
      }
    } catch {
      error.value = 'Unable to load audit events.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchEvent(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    currentError.value = null;
    current.value = null;
    try {
      const { data } = await apiClient.get<{ data: AuditEventView }>(`/audit-logs/${id}`);
      current.value = data.data;
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number } }).response?.status;
      // A foreign or unknown ULID is a 404 by design (no existence leak); say "not found", not "error".
      currentError.value = status === 404 ? 'not_found' : status === 403 ? 'forbidden' : 'failed';
      error.value = currentError.value === 'failed' ? 'Unable to load the audit event.' : null;
    } finally {
      loading.value = false;
    }
  }

  return { events, current, meta, loading, error, currentError, filters, $reset, fetchEvents, fetchEvent };
});
