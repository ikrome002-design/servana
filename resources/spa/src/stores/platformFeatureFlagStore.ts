import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components, paths } from '@/types/generated/api';

/**
 * Platform feature flags (COR-UI08-001 §12; Phase UI-08, contract page §5.4.20).
 *
 * A flag is a RESTRICTIVE rollout control. It can never grant: the evaluator has no access to
 * permissions, entitlements, billing state or account context, and it can never open External
 * Gate W — an active, fully rolled-out, correctly targeted flag still denies with
 * `external_gate_closed`. This store must never present a flag as an access grant.
 *
 * The code allowlist is TRUTHFULLY EMPTY. `meta.catalogue_is_empty` says so, and the page renders
 * a real empty state: no seeded example flag, no fabricated health metric, no invented count.
 *
 * Maker/checker is structural — a database CHECK refuses a self-approved row even when policy,
 * controller and service are bypassed. The store surfaces the server's refusal rather than
 * duplicating the rule.
 */
export type FeatureFlagChangeRequest = components['schemas']['PlatformFeatureFlagChangeRequestResource'];

type FlagsResponse = paths['/api/v1/platform/feature-flags']['get']['responses'][200]['content']['application/json'];
type FlagResponse = paths['/api/v1/platform/feature-flags/{flagKey}']['get']['responses'][200]['content']['application/json'];
type HistoryResponse = paths['/api/v1/platform/feature-flags/{flagKey}/history']['get']['responses'][200]['content']['application/json'];

export type FeatureFlagRow = FlagsResponse['data'][number];
export type FeatureFlagCatalogueMeta = FlagsResponse['meta'];
export type FeatureFlagDetail = FlagResponse['data'];
export type FeatureFlagHistory = HistoryResponse['data'];

export interface ChangeRequestPayload {
  proposed_configuration: Record<string, unknown>;
  impact_statement: string;
  rollback_plan: string;
  health_criterion: string;
  reason: string;
}

export const usePlatformFeatureFlagStore = defineStore('platformFeatureFlag', () => {
  const flags = ref<FeatureFlagRow[]>([]);
  const catalogue = ref<FeatureFlagCatalogueMeta | null>(null);
  const selected = ref<FeatureFlagDetail | null>(null);
  const history = ref<FeatureFlagHistory | null>(null);

  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);

  let sequence = 0;
  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    flags.value = [];
    catalogue.value = null;
    selected.value = null;
    history.value = null;
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    sequence += 1;
  }

  async function load(): Promise<void> {
    const token = ++sequence;
    loading.value = true;
    error.value = null;

    try {
      const { data } = await apiClient.get<FlagsResponse>('/platform/feature-flags');
      if (!isCurrent(token)) return;
      flags.value = data.data;
      catalogue.value = data.meta;
      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load feature flags.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  async function openFlag(flagKey: string): Promise<void> {
    // A flag key CONTAINS dots. It is placed in the path with encodeURIComponent so a dotted key
    // cannot be mistaken for a path segment — the same class of trap that made `config()->set`
    // treat a flag key as a nested config path during Increment 6.
    const encoded = encodeURIComponent(flagKey);
    const [detail, historyResponse] = await Promise.all([
      apiClient.get<FlagResponse>(`/platform/feature-flags/${encoded}`),
      apiClient.get<HistoryResponse>(`/platform/feature-flags/${encoded}/history`),
    ]);
    selected.value = detail.data.data;
    history.value = historyResponse.data.data;
  }

  /** Propose a change. The requester can never approve it — the database refuses the row. */
  async function requestChange(flagKey: string, payload: ChangeRequestPayload): Promise<FeatureFlagChangeRequest> {
    const { data } = await apiClient.post<{ data: FeatureFlagChangeRequest }>(
      `/platform/feature-flags/${encodeURIComponent(flagKey)}/change-requests`,
      payload,
      { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    await load();
    return data.data;
  }

  async function decide(
    changeRequestId: string,
    decision: 'approve' | 'reject' | 'cancel',
    note: string,
  ): Promise<FeatureFlagChangeRequest> {
    const { data } = await apiClient.post<{ data: FeatureFlagChangeRequest }>(
      `/platform/feature-flag-change-requests/${changeRequestId}/${decision}`,
      { decision_note: note },
    );
    await load();
    return data.data;
  }

  /** Kill switch. Pausing a rollout is always allowed to be faster than approving one. */
  async function pause(flagKey: string, reason: string): Promise<void> {
    await apiClient.post(`/platform/feature-flags/${encodeURIComponent(flagKey)}/pause`, { reason });
    await load();
  }

  return {
    flags,
    catalogue,
    selected,
    history,
    loading,
    error,
    lastRefreshed,
    $reset,
    load,
    openFlag,
    requestChange,
    decide,
    pause,
  };
});
