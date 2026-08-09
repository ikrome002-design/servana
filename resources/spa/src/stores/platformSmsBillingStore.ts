import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components, paths } from '@/types/generated/api';

/**
 * Platform SMS billing settings, usage and charge reconciliation (COR-UI08-001 §9; Phase UI-08,
 * contract page §5.4.9).
 *
 * UX state only. `PlatformSmsBillingPolicy` + `EnsurePermission:platform.billing_settings.view` /
 * `.update` + MFA + a fresh `billing_configuration` step-up are the security boundary, and the
 * versioned `platform_sms_billing_rules` table is the pricing authority — never this store and
 * never deployment config.
 *
 * PRIVACY (binding, UI/UX plan §5.4.9): nothing reachable from here is a recipient list, a phone
 * number, a message body, a provider credential or callback data. Every response is an aggregate
 * or a configuration row, which is why no masking helper is needed: the data never arrives.
 *
 * Pricing changes NEVER re-price recorded usage. A new version supersedes; it does not rewrite.
 */
export type SmsBillingRule = components['schemas']['SmsBillingRuleResource'];

type SettingsResponse = paths['/api/v1/platform/sms-billing-settings']['get']['responses'][200]['content']['application/json'];
type VersionsResponse = paths['/api/v1/platform/sms-billing-settings/versions']['get']['responses'][200]['content']['application/json'];
type CostNoticeResponse = paths['/api/v1/platform/sms-billing-settings/cost-notice-preview']['get']['responses'][200]['content']['application/json'];
type UsageResponse = paths['/api/v1/platform/sms-billing-usage']['get']['responses'][200]['content']['application/json'];
type ReconciliationResponse = paths['/api/v1/platform/sms-billing-charge-reconciliation']['get']['responses'][200]['content']['application/json'];

export type SmsBillingSettings = SettingsResponse['data'];
export type SmsCostNotice = CostNoticeResponse['data'];
export type SmsUsageRow = UsageResponse['data'][number];
export type SmsUsageMeta = UsageResponse['meta'];
export type SmsChargeReconciliation = ReconciliationResponse['data'];

export interface ScheduleSmsRulePayload {
  unit_cost_minor: number;
  effective_from: string;
  reason: string;
  tax_basis_points?: number | null;
  usage_warning_threshold_units?: number | null;
  usage_anomaly_threshold_basis_points?: number | null;
}

export interface SmsUsageFilters {
  merchant: string;
  from: string;
  to: string;
  page: number;
}

function emptyFilters(): SmsUsageFilters {
  return { merchant: '', from: '', to: '', page: 1 };
}

/** True only for a payload carrying the settings object the page renders. */
function isSettings(payload: unknown): boolean {
  return typeof payload === 'object' && payload !== null && !Array.isArray(payload) && 'current' in payload;
}

/** True only for a payload carrying the reconciliation aggregate the page renders. */
function isReconciliation(payload: unknown): boolean {
  return typeof payload === 'object' && payload !== null && !Array.isArray(payload) && 'invoice_mapping' in payload;
}

export const usePlatformSmsBillingStore = defineStore('platformSmsBilling', () => {
  const settings = ref<SmsBillingSettings | null>(null);
  const versions = ref<SmsBillingRule[]>([]);
  const versionsMeta = ref<VersionsResponse['meta'] | null>(null);
  const costNotice = ref<SmsCostNotice | null>(null);
  const usage = ref<SmsUsageRow[]>([]);
  const usageMeta = ref<SmsUsageMeta | null>(null);
  const reconciliation = ref<SmsChargeReconciliation | null>(null);

  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);
  const filters = ref<SmsUsageFilters>(emptyFilters());

  /**
   * Stale-response protection. Every read carries the sequence number it was issued with, and a
   * response whose sequence is no longer current is DISCARDED. Without it, a slow first request
   * resolving after a fast second one would overwrite newer data with older data — the classic
   * filter-flicker defect, which on a billing screen means showing the wrong month's charges.
   */
  let sequence = 0;
  let inFlight: AbortController | null = null;

  function nextRequest(): { token: number; signal: AbortSignal } {
    sequence += 1;
    inFlight?.abort();
    inFlight = new AbortController();
    return { token: sequence, signal: inFlight.signal };
  }

  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    settings.value = null;
    versions.value = [];
    versionsMeta.value = null;
    costNotice.value = null;
    usage.value = [];
    usageMeta.value = null;
    reconciliation.value = null;
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    filters.value = emptyFilters();
    sequence += 1;
    inFlight?.abort();
    inFlight = null;
  }

  /** The rule in force now, the next scheduled rule, the version history and the reconciliation. */
  async function load(): Promise<void> {
    const { token, signal } = nextRequest();
    loading.value = true;
    error.value = null;

    try {
      const [settingsResponse, versionsResponse, reconciliationResponse] = await Promise.all([
        apiClient.get<SettingsResponse>('/platform/sms-billing-settings', { signal }),
        apiClient.get<VersionsResponse>('/platform/sms-billing-settings/versions', { signal }),
        apiClient.get<ReconciliationResponse>('/platform/sms-billing-charge-reconciliation', { signal }),
      ]);

      if (!isCurrent(token)) return;

      // UI08-RENDER-001: same guard as subscription operations — a payload without `current` is
      // treated as absent rather than assigned, so an audited route survives an incomplete read.
      settings.value = isSettings(settingsResponse.data.data) ? settingsResponse.data.data : null;
      versions.value = versionsResponse.data.data;
      versionsMeta.value = versionsResponse.data.meta;
      // Same guard: `v-if="store.reconciliation"` is truthy for a collection-shaped body, and the
      // panel then reads `.invoice_mapping.linked_count` off `undefined`. A payload without
      // `invoice_mapping` is absent, not present-but-empty.
      reconciliation.value = isReconciliation(reconciliationResponse.data.data)
        ? reconciliationResponse.data.data
        : null;
      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load SMS billing settings.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  async function loadUsage(): Promise<void> {
    const token = ++sequence;
    error.value = null;

    try {
      const params: Record<string, string | number> = { page: filters.value.page };
      if (filters.value.merchant !== '') params.merchant = filters.value.merchant;
      if (filters.value.from !== '') params.from = filters.value.from;
      if (filters.value.to !== '') params.to = filters.value.to;

      const { data } = await apiClient.get<UsageResponse>('/platform/sms-billing-usage', { params });

      if (!isCurrent(token)) return;

      usage.value = data.data;
      usageMeta.value = data.meta;
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load SMS usage.';
    }
  }

  /** Server-authoritative cost notice. The wording is never composed in the browser. */
  async function previewCostNotice(recipientCount: number, segmentCount: number): Promise<void> {
    const { data } = await apiClient.get<CostNoticeResponse>('/platform/sms-billing-settings/cost-notice-preview', {
      params: { recipient_count: recipientCount, segment_count: segmentCount },
    });
    costNotice.value = data.data;
  }

  /** Schedule the next effective rule. Overlap → 409; a backdated instant → 422. */
  async function schedule(payload: ScheduleSmsRulePayload): Promise<SmsBillingRule> {
    const { data } = await apiClient.post<{ data: SmsBillingRule }>(
      '/platform/sms-billing-settings/versions',
      payload,
      { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    await load();
    return data.data;
  }

  /** Withdraw a rule that has NOT yet taken effect. An effective rule can never be withdrawn. */
  async function cancelScheduled(ruleId: string, reason: string): Promise<SmsBillingRule> {
    const { data } = await apiClient.post<{ data: SmsBillingRule }>(
      `/platform/sms-billing-settings/versions/${ruleId}/cancel`,
      { reason },
    );
    await load();
    return data.data;
  }

  function setFilter<K extends keyof SmsUsageFilters>(key: K, value: SmsUsageFilters[K]): void {
    filters.value = { ...filters.value, [key]: value, ...(key === 'page' ? {} : { page: 1 }) };
  }

  return {
    settings,
    versions,
    versionsMeta,
    costNotice,
    usage,
    usageMeta,
    reconciliation,
    loading,
    error,
    lastRefreshed,
    filters,
    $reset,
    load,
    loadUsage,
    previewCostNotice,
    schedule,
    cancelScheduled,
    setFilter,
  };
});
