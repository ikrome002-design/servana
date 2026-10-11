import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Audit exports (Plan §13.5, §19.3, §80; ADR-010; Phase 19). UX state only —
 * AuditExportPolicy + FileAccessService (signed-download boundary) are
 * authoritative. Audit requests a branch-scoped, reason-gated, masked export (a
 * fresh MFA step-up is server-enforced), generated async; downloads go through a
 * short-lived authorized signed link that is NEVER stored. Merchant-level
 * (branch_id = null) rows are never exported. `file_id`, storage paths,
 * signatures, and raw failure detail never reach this store.
 */
export type AuditExportStatus = 'queued' | 'processing' | 'ready' | 'failed' | 'expired' | 'revoked';

export interface AuditExportScope {
  domains: string[];
  severities: string[];
  has_date_from: boolean;
  has_date_to: boolean;
}

export interface AuditExportView {
  id: string;
  branch?: { id: string; name: string } | null;
  status: AuditExportStatus;
  reason: string;
  scope: AuditExportScope;
  row_count: number | null;
  download_count: number;
  requested_at: string | null;
  generated_at: string | null;
  expires_at: string | null;
  first_downloaded_at: string | null;
  last_downloaded_at: string | null;
  failure_code: string | null;
  failure_message: string | null;
  created_at: string | null;
  can?: { view?: boolean; download?: boolean; revoke?: boolean };
}

/** Allowlisted audit domains offered in the request form (mirrors AuditDomain). */
export const AUDIT_EXPORT_DOMAINS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'general', label: 'Branch events' },
  { value: 'finance', label: 'Finance' },
  { value: 'compensation', label: 'Compensation' },
];

export const AUDIT_EXPORT_SEVERITIES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'info', label: 'Info' },
  { value: 'notice', label: 'Notice' },
  { value: 'warning', label: 'Warning' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
];

/** A request is "in flight" (poll) until it reaches a terminal state. */
export function isTerminal(status: AuditExportStatus): boolean {
  return status === 'ready' || status === 'failed' || status === 'expired' || status === 'revoked';
}

export interface AuditExportRequestPayload {
  branch: string;
  reason: string;
  date_from?: string;
  date_to?: string;
  domains?: string[];
  severities?: string[];
}

export interface AuditExportPageMeta {
  current_page: number;
  last_page: number;
  total: number;
}

/** Stateless page read (dashboard slices); meta.total is the server's count for that status. */
export async function fetchAuditExportPage(status: AuditExportStatus | '', perPage = 5): Promise<{ data: AuditExportView[]; meta: AuditExportPageMeta }> {
  const params: Record<string, string | number> = { sort: '-created_at', per_page: perPage };
  if (status !== '') params.status = status;
  const { data } = await apiClient.get<{ data: AuditExportView[]; meta?: AuditExportPageMeta }>('/audit-exports', { params });
  return { data: data.data, meta: data.meta ?? { current_page: 1, last_page: 1, total: data.data.length } };
}

export const useAuditExportStore = defineStore('auditExport', () => {
  const exports = ref<AuditExportView[]>([]);
  const current = ref<AuditExportView | null>(null);
  const meta = ref<AuditExportPageMeta>({ current_page: 1, last_page: 1, total: 0 });
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    exports.value = [];
    current.value = null;
    meta.value = { current_page: 1, last_page: 1, total: 0 };
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchExports(page?: number): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = { sort: '-created_at' };
      if (page !== undefined) params.page = page;
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: AuditExportView[]; meta?: AuditExportPageMeta }>('/audit-exports', { params });
      exports.value = data.data;
      if (data.meta) meta.value = { current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total };
    } catch {
      error.value = 'Unable to load audit exports.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchExport(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: AuditExportView }>(`/audit-exports/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the audit export.';
    } finally {
      loading.value = false;
    }
  }

  /**
   * Request an export. A fresh MFA step-up is server-enforced (RequireFreshMfa on
   * audit-exports.store); the route is a BranchMutation, not a financial mutation,
   * so no Idempotency-Key is required — each request creates a distinct export row.
   */
  async function request(payload: AuditExportRequestPayload): Promise<AuditExportView> {
    const { data } = await apiClient.post<{ data: AuditExportView }>('/audit-exports', payload);
    current.value = data.data;
    return data.data;
  }

  /** Request a short-lived authorized signed download link (never stored). */
  async function downloadLink(id: string): Promise<string> {
    const { data } = await apiClient.post<{ data: { url: string } }>(`/audit-exports/${id}/download-link`, {});
    return data.data.url;
  }

  async function revoke(id: string): Promise<AuditExportView> {
    const { data } = await apiClient.post<{ data: AuditExportView }>(`/audit-exports/${id}/revoke`, {});
    current.value = data.data;
    return data.data;
  }

  return {
    exports,
    current,
    meta,
    loading,
    error,
    filterStatus,
    $reset,
    fetchExports,
    fetchExport,
    request,
    downloadLink,
    revoke,
  };
});
