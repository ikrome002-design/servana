import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { fetchAuditEventPage, type AuditEventView } from '@/stores/auditEventStore';
import { fetchAuditExportPage, type AuditExportView } from '@/stores/auditExportStore';
import { fetchFlagPage, type FlaggedEventView } from '@/stores/flaggedEventStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Audit account workspace: the dashboard's independent evidence slices and the integrity status
 * (Phase UI-15; Appendix A §12.1, §12.4.1, §12.4.13). UX state only.
 *
 * Every count shown on the dashboard is a server `meta.total` for an exact filter inside the
 * caller's branch scope — never a tally of the first page. A slice the caller is not permitted to
 * read is recorded as `unavailable` and is not requested at all, and a slice that fails is
 * recorded as `failed`; neither is ever rendered as zero.
 */

export interface AuditIntegrityView {
  status: 'verified' | 'partial' | 'empty' | 'broken';
  checked_at: string;
  segment_limit: number;
  complete_history: boolean;
  coverage_from: string | null;
  coverage_to: string | null;
  branch_records_checked: number;
  first_break: {
    category: string | null;
    within_branch_scope: boolean;
    event: string | null;
    occurred_at: string | null;
  } | null;
  scheduled_verification_exposed: boolean;
}

export type SliceState<T> =
  | { state: 'loading' }
  | { state: 'unavailable' }
  | { state: 'failed' }
  | { state: 'ready'; total: number; items: T[] };

export interface AuditDashboardSnapshot {
  critical: SliceState<AuditEventView>;
  high: SliceState<AuditEventView>;
  recent: SliceState<AuditEventView>;
  finance: SliceState<AuditEventView>;
  compensation: SliceState<AuditEventView>;
  access: SliceState<AuditEventView>;
  privacy: SliceState<AuditEventView>;
  openFlags: SliceState<FlaggedEventView>;
  underReview: SliceState<FlaggedEventView>;
  reopened: SliceState<FlaggedEventView>;
  exportsReady: SliceState<AuditExportView>;
  exportsInFlight: SliceState<AuditExportView>;
  exportsQueued: SliceState<AuditExportView>;
}

type SliceKey = keyof AuditDashboardSnapshot;

function loadingSnapshot(): AuditDashboardSnapshot {
  const loading = { state: 'loading' } as const;
  return {
    critical: loading, high: loading, recent: loading, finance: loading, compensation: loading, access: loading,
    privacy: loading, openFlags: loading, underReview: loading, reopened: loading, exportsReady: loading, exportsInFlight: loading, exportsQueued: loading,
  };
}

export const useAuditWorkspaceStore = defineStore('auditWorkspace', () => {
  const integrity = ref<AuditIntegrityView | null>(null);
  const integrityLoading = ref(false);
  const integrityError = ref<'forbidden' | 'failed' | null>(null);
  const dashboard = ref<AuditDashboardSnapshot>(loadingSnapshot());
  const observedAt = ref<string | null>(null);

  function $reset(): void {
    integrity.value = null;
    integrityLoading.value = false;
    integrityError.value = null;
    dashboard.value = loadingSnapshot();
    observedAt.value = null;
  }

  async function fetchIntegrity(): Promise<void> {
    integrityLoading.value = true;
    integrityError.value = null;
    try {
      const { data } = await apiClient.get<{ data: AuditIntegrityView }>('/audit-integrity');
      integrity.value = data.data;
    } catch (e: unknown) {
      integrity.value = null;
      integrityError.value = (e as { response?: { status?: number } }).response?.status === 403 ? 'forbidden' : 'failed';
    } finally {
      integrityLoading.value = false;
    }
  }

  async function slice<T>(key: SliceKey, permitted: boolean, read: () => Promise<{ data: T[]; meta: { total: number } }>): Promise<void> {
    if (!permitted) {
      (dashboard.value[key] as SliceState<T>) = { state: 'unavailable' };
      return;
    }
    try {
      const page = await read();
      (dashboard.value[key] as SliceState<T>) = { state: 'ready', total: page.meta.total, items: page.data };
    } catch {
      (dashboard.value[key] as SliceState<T>) = { state: 'failed' };
    }
  }

  async function fetchDashboard(): Promise<void> {
    const permissions = usePermissionStore();
    const events = permissions.can('audit.branch_events.view');
    const finance = permissions.can('audit.finance.view');
    const compensation = permissions.can('audit.compensation.view');
    const exports = permissions.can('audit.export');
    dashboard.value = loadingSnapshot();

    await Promise.all([
      slice('critical', events, () => fetchAuditEventPage('general', { severity: 'critical', sort: '-created_at' }, 4)),
      slice('high', events, () => fetchAuditEventPage('general', { severity: 'high', sort: '-created_at' }, 4)),
      slice('recent', events, () => fetchAuditEventPage('general', { sort: '-created_at' }, 6)),
      slice('finance', finance, () => fetchAuditEventPage('finance', { sort: '-created_at' }, 3)),
      slice('compensation', compensation, () => fetchAuditEventPage('compensation', { sort: '-created_at' }, 3)),
      slice('access', events, () => fetchAuditEventPage('general', { view: 'access', sort: '-created_at' }, 3)),
      slice('privacy', events, () => fetchAuditEventPage('general', { view: 'privacy_preferred', sort: '-created_at' }, 3)),
      slice('openFlags', events, () => fetchFlagPage('open', 5)),
      slice('underReview', events, () => fetchFlagPage('under_review', 5)),
      slice('reopened', events, () => fetchFlagPage('reopened', 5)),
      slice('exportsReady', exports, () => fetchAuditExportPage('ready', 3)),
      slice('exportsInFlight', exports, () => fetchAuditExportPage('processing', 3)),
      slice('exportsQueued', exports, () => fetchAuditExportPage('queued', 3)),
      events ? fetchIntegrity() : Promise.resolve(),
    ]);
    observedAt.value = new Date().toISOString();
  }

  return { integrity, integrityLoading, integrityError, dashboard, observedAt, $reset, fetchIntegrity, fetchDashboard };
});
