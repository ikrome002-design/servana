import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components, paths } from '@/types/generated/api';

/**
 * Platform-wide subscription operations (COR-UI08-001 §10; Phase UI-08, contract page §5.4.13).
 *
 * MONITORING ONLY. The delivered backend is seven READ operations over the Phase 20B projection:
 * there is no table, no migration, no mutation and no new permission key. This store therefore
 * exposes no write method at all — not a disabled one, not a guarded one. A subscription state is
 * changed by the billing lifecycle, and an issued invoice is immutable; the page that consumes
 * this store must offer no record-payment, mark-paid, edit-invoice, override-state, create-credit
 * or query-provider control.
 *
 * `platform.merchant.view` is the primary permission (the existing `MerchantPolicy::viewGovernance`
 * authority, reused rather than duplicated). The API remains the security boundary.
 */
export type PlatformSubscription = components['schemas']['PlatformSubscriptionResource'];
export type PlatformSubscriptionInvoice = components['schemas']['PlatformSubscriptionInvoiceResource'];

type SummaryResponse = paths['/api/v1/platform/subscription-operations/summary']['get']['responses'][200]['content']['application/json'];
type SubscriptionsResponse = paths['/api/v1/platform/subscriptions']['get']['responses'][200]['content']['application/json'];
type InvoicesResponse = paths['/api/v1/platform/subscription-invoices']['get']['responses'][200]['content']['application/json'];
type CreditsResponse = paths['/api/v1/platform/billing-credits']['get']['responses'][200]['content']['application/json'];
type EscalationsResponse = paths['/api/v1/platform/subscription-escalations']['get']['responses'][200]['content']['application/json'];

export type SubscriptionOperationsSummary = SummaryResponse['data'];
export type SubscriptionOperationsDefinitions = SummaryResponse['meta'];
export type BillingCreditRow = CreditsResponse['data'][number];
export type EscalationRow = EscalationsResponse['data'][number];

export type OperationsTab = 'subscriptions' | 'invoices' | 'credits' | 'escalations';

export interface SubscriptionOperationsFilters {
  status: string;
  plan: string;
  billing_interval: string;
  merchant: string;
  page: number;
}

function emptyFilters(): SubscriptionOperationsFilters {
  return { status: '', plan: '', billing_interval: '', merchant: '', page: 1 };
}

/** True only for a payload carrying the aggregate the page actually renders. */
function isSummary(payload: unknown): payload is SubscriptionOperationsSummary {
  return typeof payload === 'object' && payload !== null && !Array.isArray(payload) && 'totals' in payload;
}

export const usePlatformSubscriptionOperationsStore = defineStore('platformSubscriptionOperations', () => {
  const summary = ref<SubscriptionOperationsSummary | null>(null);
  const definitions = ref<SubscriptionOperationsDefinitions | null>(null);
  const subscriptions = ref<PlatformSubscription[]>([]);
  const invoices = ref<PlatformSubscriptionInvoice[]>([]);
  const credits = ref<BillingCreditRow[]>([]);
  const escalations = ref<EscalationRow[]>([]);
  const selectedSubscription = ref<PlatformSubscription | null>(null);
  const selectedInvoice = ref<PlatformSubscriptionInvoice | null>(null);

  const tab = ref<OperationsTab>('subscriptions');
  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);
  const filters = ref<SubscriptionOperationsFilters>(emptyFilters());

  // See platformSmsBillingStore for why a sequence token is required rather than optional: a slow
  // first response resolving after a fast second one would silently show the wrong cohort.
  let sequence = 0;
  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    summary.value = null;
    definitions.value = null;
    subscriptions.value = [];
    invoices.value = [];
    credits.value = [];
    escalations.value = [];
    selectedSubscription.value = null;
    selectedInvoice.value = null;
    tab.value = 'subscriptions';
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    filters.value = emptyFilters();
    sequence += 1;
  }

  async function loadSummary(): Promise<void> {
    const token = ++sequence;
    loading.value = true;
    error.value = null;

    try {
      const { data } = await apiClient.get<SummaryResponse>('/platform/subscription-operations/summary');
      if (!isCurrent(token)) return;
      // UI08-RENDER-001: an incomplete payload must not crash an audited route. `data` typed as the
      // summary does not guarantee the SHAPE at runtime — a collection-shaped body would assign an
      // array here and the template would then read `.totals.subscriptions` off `undefined`. A
      // payload without `totals` is treated as absent, which the page already renders safely.
      summary.value = isSummary(data.data) ? data.data : null;
      definitions.value = data.meta;
      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load subscription operations.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  function activeParams(): Record<string, string | number> {
    const params: Record<string, string | number> = { page: filters.value.page };
    if (filters.value.status !== '') params.status = filters.value.status;
    if (filters.value.plan !== '') params.plan = filters.value.plan;
    if (filters.value.billing_interval !== '') params.billing_interval = filters.value.billing_interval;
    if (filters.value.merchant !== '') params.merchant = filters.value.merchant;
    return params;
  }

  async function loadTab(next: OperationsTab = tab.value): Promise<void> {
    const token = ++sequence;
    tab.value = next;
    loading.value = true;
    error.value = null;

    try {
      const params = activeParams();

      if (next === 'subscriptions') {
        const { data } = await apiClient.get<SubscriptionsResponse>('/platform/subscriptions', { params });
        if (!isCurrent(token)) return;
        subscriptions.value = data.data;
      } else if (next === 'invoices') {
        const { data } = await apiClient.get<InvoicesResponse>('/platform/subscription-invoices', { params });
        if (!isCurrent(token)) return;
        invoices.value = data.data;
      } else if (next === 'credits') {
        const { data } = await apiClient.get<CreditsResponse>('/platform/billing-credits', { params });
        if (!isCurrent(token)) return;
        credits.value = data.data;
      } else {
        const { data } = await apiClient.get<EscalationsResponse>('/platform/subscription-escalations', { params });
        if (!isCurrent(token)) return;
        escalations.value = data.data;
      }

      lastRefreshed.value = new Date().toISOString();
    } catch {
      if (isCurrent(token)) error.value = 'Unable to load subscription operations.';
    } finally {
      if (isCurrent(token)) loading.value = false;
    }
  }

  async function openSubscription(id: string): Promise<PlatformSubscription> {
    const { data } = await apiClient.get<{ data: PlatformSubscription }>(`/platform/subscriptions/${id}`);
    selectedSubscription.value = data.data;
    return data.data;
  }

  async function openInvoice(id: string): Promise<PlatformSubscriptionInvoice> {
    const { data } = await apiClient.get<{ data: PlatformSubscriptionInvoice }>(`/platform/subscription-invoices/${id}`);
    selectedInvoice.value = data.data;
    return data.data;
  }

  function setFilter<K extends keyof SubscriptionOperationsFilters>(key: K, value: SubscriptionOperationsFilters[K]): void {
    filters.value = { ...filters.value, [key]: value, ...(key === 'page' ? {} : { page: 1 }) };
  }

  return {
    summary,
    definitions,
    subscriptions,
    invoices,
    credits,
    escalations,
    selectedSubscription,
    selectedInvoice,
    tab,
    loading,
    error,
    lastRefreshed,
    filters,
    $reset,
    loadSummary,
    loadTab,
    openSubscription,
    openInvoice,
    setFilter,
  };
});
