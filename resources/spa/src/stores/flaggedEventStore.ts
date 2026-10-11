import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Audit flagged-event review workflow (Plan §13.2, §70, §80; Phase 19; Phase UI-15). UX state
 * only — AuditFlaggedEventPolicy + the state machine are authoritative. The Audit
 * role flags a branch-scoped audit row and works it through the review lifecycle
 * (open → under_review → resolved/dismissed → reopened). ONLY review metadata is
 * mutated; the linked source audit row is immutable (masked summary only). The
 * `can` map is server-derived — controls are UX gating, never the security boundary.
 *
 * The backend offers exactly these transitions: flag, start review (which assigns the reviewer),
 * resolve, dismiss and reopen. There is no reassign, escalate or category endpoint, so this store
 * has no method that could pretend otherwise.
 */
export type FlaggedStatus = 'open' | 'under_review' | 'resolved' | 'dismissed' | 'reopened';

export interface FlaggedAuditSummary {
  id: string;
  action: string;
  severity: string;
  actor: string | null;
  subject_type: string | null;
  context: Record<string, unknown>;
  occurred_at: string | null;
}

export interface FlaggedEventView {
  id: string;
  status: FlaggedStatus;
  review_notes: string | null;
  assigned_to: string | null;
  resolved_by: string | null;
  created_at: string | null;
  updated_at: string | null;
  audit_event: FlaggedAuditSummary | null;
  can?: { update_status?: boolean; resolve_metadata?: boolean };
}

export interface FlagPageMeta {
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * Stateless page read for one review status (dashboard slices). `meta.total` is the server's count
 * for that status within the caller's branch scope.
 */
export async function fetchFlagPage(status: FlaggedStatus | '', perPage = 5): Promise<{ data: FlaggedEventView[]; meta: FlagPageMeta }> {
  const params: Record<string, string | number> = { sort: '-created_at', per_page: perPage };
  if (status !== '') params.status = status;
  const { data } = await apiClient.get<{ data: FlaggedEventView[]; meta?: FlagPageMeta }>('/audit-flagged-events', { params });
  return { data: data.data, meta: data.meta ?? { current_page: 1, last_page: 1, total: data.data.length } };
}

export const useFlaggedEventStore = defineStore('flaggedEvent', () => {
  const items = ref<FlaggedEventView[]>([]);
  const current = ref<FlaggedEventView | null>(null);
  const meta = ref<FlagPageMeta>({ current_page: 1, last_page: 1, total: 0 });
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    items.value = [];
    current.value = null;
    meta.value = { current_page: 1, last_page: 1, total: 0 };
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchAll(page = 1): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = { sort: '-created_at', page };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FlaggedEventView[]; meta?: FlagPageMeta }>('/audit-flagged-events', { params });
      items.value = data.data;
      if (data.meta) meta.value = { current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total };
    } catch {
      error.value = 'Unable to load flagged events.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchOne(id: string): Promise<void> {
    loading.value = true;
    error.value = null;
    current.value = null;
    try {
      const { data } = await apiClient.get<{ data: FlaggedEventView }>(`/audit-flagged-events/${id}`);
      current.value = data.data;
    } catch {
      error.value = 'Unable to load the flagged event.';
    } finally {
      loading.value = false;
    }
  }

  /**
   * Flag an audit event for review (`audit_log` = the audit-row ULID; `note`
   * optional). Branch scope is derived server-side from the audit row — a
   * wrong-branch or foreign row is denied/404s without enumeration.
   */
  async function flag(payload: { audit_log: string; note?: string }): Promise<FlaggedEventView> {
    const { data } = await apiClient.post<{ data: FlaggedEventView }>('/audit-flagged-events', payload);
    current.value = data.data;
    return data.data;
  }

  async function transition(
    id: string,
    action: 'start-review' | 'resolve' | 'dismiss' | 'reopen',
    payload: Record<string, string> = {},
  ): Promise<FlaggedEventView> {
    const { data } = await apiClient.post<{ data: FlaggedEventView }>(`/audit-flagged-events/${id}/${action}`, payload);
    current.value = data.data;
    const index = items.value.findIndex((item) => item.id === id);
    if (index !== -1) items.value[index] = data.data;
    return data.data;
  }

  return { items, current, meta, loading, error, filterStatus, $reset, fetchAll, fetchOne, flag, transition };
});
