import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Audit flagged-event review workflow (Plan §13.2, §70, §80; Phase 19). UX state
 * only — AuditFlaggedEventPolicy + the state machine are authoritative. The Audit
 * role flags a branch-scoped audit row and works it through the review lifecycle
 * (open → under_review → resolved/dismissed → reopened). ONLY review metadata is
 * mutated; the linked source audit row is immutable (masked summary only). The
 * `can` map is server-derived — controls are UX gating, never the security boundary.
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

export const useFlaggedEventStore = defineStore('flaggedEvent', () => {
  const items = ref<FlaggedEventView[]>([]);
  const current = ref<FlaggedEventView | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    items.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchAll(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = { sort: '-created_at' };
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FlaggedEventView[] }>('/audit-flagged-events', { params });
      items.value = data.data;
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
    return data.data;
  }

  return { items, current, loading, error, filterStatus, $reset, fetchAll, fetchOne, flag, transition };
});
