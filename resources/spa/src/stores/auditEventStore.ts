import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Audit event reads (Plan §70, §74; Phase 19). UX state only — the API
 * (AuditLogPolicy + EnsurePermission + branch scope) is authoritative. Reads are
 * branch-scoped, field-masked, and domain-segmented server-side; merchant-level
 * (branch_id = null) rows are never returned. This store never receives or stores
 * raw before/after/context — only the masked payload the resource emits.
 *
 * Domains map to the three canonical read segments / endpoints:
 *   - general      → GET /audit-logs                 (audit.branch_events.view)
 *   - finance      → GET /audit-logs/finance         (finance.audit.view | audit.finance.view)
 *   - compensation → GET /audit-logs/compensation    (audit.compensation.view)
 */
export type AuditDomain = 'general' | 'finance' | 'compensation';

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
}

interface PageMeta {
  current_page: number;
  last_page: number;
  total: number;
}

const ENDPOINT: Record<AuditDomain, string> = {
  general: '/audit-logs',
  finance: '/audit-logs/finance',
  compensation: '/audit-logs/compensation',
};

export const useAuditEventStore = defineStore('auditEvent', () => {
  const events = ref<AuditEventView[]>([]);
  const current = ref<AuditEventView | null>(null);
  const meta = ref<PageMeta>({ current_page: 1, last_page: 1, total: 0 });
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filters = ref<AuditEventFilters>({ sort: '-created_at' });

  function $reset(): void {
    events.value = [];
    current.value = null;
    meta.value = { current_page: 1, last_page: 1, total: 0 };
    loading.value = false;
    error.value = null;
    filters.value = { sort: '-created_at' };
  }

  async function fetchEvents(domain: AuditDomain = 'general', page = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = { page };
      for (const [key, value] of Object.entries(filters.value)) {
        if (typeof value === 'string' && value !== '') params[key] = value;
      }
      const { data } = await apiClient.get<{ data: AuditEventView[]; meta?: PageMeta }>(ENDPOINT[domain], { params });
      events.value = data.data;
      if (data.meta) meta.value = { current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total };
    } catch {
      error.value = 'Unable to load audit events.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchEvent(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    current.value = null;
    try {
      const { data } = await apiClient.get<{ data: AuditEventView }>(`/audit-logs/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the audit event.';
    } finally {
      loading.value = false;
    }
  }

  return { events, current, meta, loading, error, filters, $reset, fetchEvents, fetchEvent };
});
