import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Platform / governance audit reads (Plan §70, §74; Phase 19 backend, Phase UI-08 page §5.4.18).
 *
 * READ-ONLY by construction: this store exposes no create, update or delete method, because the
 * `audit_logs` table is append-only at the DATABASE level (no UPDATE, no DELETE) and there is no
 * endpoint that would accept one. A "read-only screen" enforced only by omitting buttons would be a
 * UI convention; this is the actual shape of the contract.
 *
 * Scope is the PLATFORM chain: `PlatformAuditLogController` returns rows with `merchant_id IS NULL`
 * and 404s a merchant-scoped ULID, so a Super Administrator never reaches merchant operational audit
 * through here. `platform.audit.view` is enforced by route middleware and `AuditLogPolicy`; every
 * value in `context`, and the actor's email, are masked SERVER-side by `AuditValueMasker`. Nothing
 * in this store can request unmasked data — there is no parameter for it.
 *
 * Filters are exactly the ones `AuditLogIndexRequest` allowlists. Sending anything else would be
 * rejected 422, so the page offers no control for one.
 */
export interface PlatformAuditEvent {
  id: string;
  action: string;
  severity: string;
  actor: string | null;
  subject_type: string | null;
  context: Record<string, unknown>;
  correlation_id: string | null;
  created_at: string | null;
}

export interface PlatformAuditFilters {
  action: string;
  severity: string;
  actor: string;
  date_from: string;
  date_to: string;
  sort: string;
}

export interface PlatformAuditMeta {
  current_page: number;
  last_page: number;
  total: number;
}

export function emptyPlatformAuditFilters(): PlatformAuditFilters {
  return { action: '', severity: '', actor: '', date_from: '', date_to: '', sort: '-created_at' };
}

function readMeta(payload: unknown): PlatformAuditMeta | null {
  if (typeof payload !== 'object' || payload === null) return null;
  const meta = (payload as { meta?: unknown }).meta;
  if (typeof meta !== 'object' || meta === null) return null;
  const { current_page: current, last_page: last, total } = meta as Record<string, unknown>;
  if (typeof current !== 'number' || typeof last !== 'number' || typeof total !== 'number') return null;
  return { current_page: current, last_page: last, total };
}

export const usePlatformAuditStore = defineStore('platformAudit', () => {
  const events = ref<PlatformAuditEvent[]>([]);
  const current = ref<PlatformAuditEvent | null>(null);
  const meta = ref<PlatformAuditMeta | null>(null);
  const page = ref(1);
  const loading = ref(false);
  const detailLoading = ref(false);
  const error = ref<string | null>(null);
  const detailError = ref<string | null>(null);
  const filters = ref<PlatformAuditFilters>(emptyPlatformAuditFilters());

  function $reset(): void {
    events.value = [];
    current.value = null;
    meta.value = null;
    page.value = 1;
    loading.value = false;
    detailLoading.value = false;
    error.value = null;
    detailError.value = null;
    filters.value = emptyPlatformAuditFilters();
  }

  /** Only non-empty allowlisted filters are sent; an empty one is absent, never `""`. */
  function queryParams(): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    const f = filters.value;
    if (f.action !== '') params.action = f.action.trim();
    if (f.severity !== '') params.severity = f.severity;
    if (f.actor !== '') params.actor = f.actor.trim();
    if (f.date_from !== '') params.date_from = f.date_from;
    if (f.date_to !== '') params.date_to = f.date_to;
    if (f.sort !== '') params.sort = f.sort;
    if (page.value > 1) params.page = page.value;
    return params;
  }

  async function fetchEvents(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PlatformAuditEvent[] }>('/platform/audit-logs', { params: queryParams() });
      events.value = data.data;
      meta.value = readMeta(data);
    } catch {
      // The server's own message is not surfaced here: an audit read failure carries no detail a
      // caller is entitled to, and repeating it would be the only useful action anyway.
      error.value = 'We couldn’t load platform audit events.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchEvent(id: string): Promise<void> {
    detailLoading.value = true;
    detailError.value = null;
    current.value = null;
    try {
      const { data } = await apiClient.get<{ data: PlatformAuditEvent }>(`/platform/audit-logs/${id}`);
      current.value = data.data;
    } catch {
      // A merchant-chain ULID 404s here by design. The message is the same for "not found" and
      // "not yours", so the detail route cannot be used to probe which events exist.
      detailError.value = 'That audit event isn’t available.';
    } finally {
      detailLoading.value = false;
    }
  }

  function closeEvent(): void {
    current.value = null;
    detailError.value = null;
  }

  async function applyFilters(): Promise<void> {
    page.value = 1;
    await fetchEvents();
  }

  async function clearFilters(): Promise<void> {
    filters.value = emptyPlatformAuditFilters();
    await applyFilters();
  }

  async function goToPage(next: number): Promise<void> {
    page.value = next;
    await fetchEvents();
  }

  return {
    events,
    current,
    meta,
    page,
    loading,
    detailLoading,
    error,
    detailError,
    filters,
    $reset,
    fetchEvents,
    fetchEvent,
    closeEvent,
    applyFilters,
    clearFilters,
    goToPage,
  };
});
