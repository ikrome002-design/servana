import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

export interface FinanceMoney {
  amount: number;
  currency: string;
  formatted: string;
}

export interface FinanceWorkspaceTask {
  key: string;
  label: string;
  count: number;
  severity: 'critical' | 'high' | 'medium';
  route_name: string;
  step_up_required: boolean;
  maker_checker: string;
}

export interface FinanceWorkspaceOverview {
  branch_context: {
    label: string;
    branches: Array<{ id: string; name: string; code: string; town: string | null }>;
  };
  payments: {
    pending_validation: number;
    duplicate_risk: number;
    pending_recorded: FinanceMoney[];
  };
  invoices: {
    outstanding: number;
    outstanding_balance: FinanceMoney[];
    validated_payments: FinanceMoney[];
  };
  controls: {
    original_receipts: number;
    active_disputes: number;
    refunds_requiring_action: number;
    cash_ups_requiring_review: number;
    open_periods: number;
    reopen_requests: number;
  };
  compensation: {
    salary_due: FinanceMoney[];
    commission_due: FinanceMoney[];
    payouts_requiring_action: number;
    earnings_queries_requiring_action: number;
  };
  tasks: FinanceWorkspaceTask[];
  subscription: { available: false; reason: string };
  reports: { available: false; reason: string };
  notifications: { available: false; reason: string };
}

export interface FinanceDuplicateReview {
  id: string;
  method: string;
  result: string;
  match_type: 'exact_normalized_reference';
  risk: 'high';
  reference_masked: string | null;
  amount: FinanceMoney;
  checked_at: string;
  current: {
    group_id: string;
    group_status: string;
    invoice_id: string;
    invoice_number: string | null;
    recorded_by: string;
    recorded_at: string | null;
  };
  conflict: null | {
    payment_id: string;
    group_id: string;
    group_status: string;
    invoice_id: string;
    invoice_number: string | null;
    amount: FinanceMoney;
    paid_at: string;
  };
  can_override: boolean;
}

export interface FinancePartialSplitInvoice {
  invoice: { id: string; number: string | null; status: string; created_at: string | null };
  balance: {
    total: FinanceMoney;
    validated: FinanceMoney;
    pending_recorded: FinanceMoney;
    remaining: FinanceMoney;
  };
  group_count: number;
  has_multiple_groups: boolean;
  has_multi_method_group: boolean;
  groups: Array<{
    id: string;
    status: string;
    total: FinanceMoney;
    recorded_at: string | null;
    maker: string;
    receipt: null | { id: string; number: number };
    components: Array<{
      id: string;
      method: string;
      status: string;
      amount: FinanceMoney;
      reference_masked: string | null;
      duplicate_risk: boolean;
    }>;
  }>;
}

interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export const useFinanceWorkspaceStore = defineStore('financeWorkspace', () => {
  const overview = ref<FinanceWorkspaceOverview | null>(null);
  const duplicates = ref<FinanceDuplicateReview[]>([]);
  const duplicateMeta = ref<PageMeta | null>(null);
  const partialSplitInvoices = ref<FinancePartialSplitInvoice[]>([]);
  const partialSplitMeta = ref<PageMeta | null>(null);
  const overviewLoading = ref(false);
  const overviewError = ref<string | null>(null);
  const duplicatesLoading = ref(false);
  const duplicatesError = ref<string | null>(null);
  const partialSplitLoading = ref(false);
  const partialSplitError = ref<string | null>(null);
  let overviewRequest: Promise<void> | null = null;

  function fetchOverview(): Promise<void> {
    if (overviewRequest !== null) return overviewRequest;

    overviewLoading.value = true;
    overviewError.value = null;
    overviewRequest = apiClient
      .get<{ data: { overview: FinanceWorkspaceOverview } }>('/finance/workspace')
      .then(({ data }) => { overview.value = data.data.overview; })
      .catch(() => { overviewError.value = 'We couldn’t load the Finance control desk.'; })
      .finally(() => {
        overviewLoading.value = false;
        overviewRequest = null;
      });

    return overviewRequest;
  }

  async function fetchDuplicates(params: Record<string, string | number> = {}): Promise<void> {
    duplicatesLoading.value = true;
    duplicatesError.value = null;
    try {
      const { data } = await apiClient.get<{ data: FinanceDuplicateReview[]; meta: PageMeta }>(
        '/finance/duplicate-references',
        { params },
      );
      duplicates.value = data.data;
      duplicateMeta.value = data.meta;
    } catch {
      duplicatesError.value = 'We couldn’t load duplicate-reference reviews.';
    } finally {
      duplicatesLoading.value = false;
    }
  }

  async function fetchPartialSplit(params: Record<string, string | number> = {}): Promise<void> {
    partialSplitLoading.value = true;
    partialSplitError.value = null;
    try {
      const { data } = await apiClient.get<{ data: FinancePartialSplitInvoice[]; meta: PageMeta }>(
        '/finance/partial-split-payments',
        { params },
      );
      partialSplitInvoices.value = data.data;
      partialSplitMeta.value = data.meta;
    } catch {
      partialSplitError.value = 'We couldn’t load partial and split payments.';
    } finally {
      partialSplitLoading.value = false;
    }
  }

  function $reset(): void {
    overview.value = null;
    duplicates.value = [];
    duplicateMeta.value = null;
    partialSplitInvoices.value = [];
    partialSplitMeta.value = null;
    overviewLoading.value = false;
    overviewError.value = null;
    duplicatesLoading.value = false;
    duplicatesError.value = null;
    partialSplitLoading.value = false;
    partialSplitError.value = null;
  }

  return {
    overview,
    duplicates,
    duplicateMeta,
    partialSplitInvoices,
    partialSplitMeta,
    overviewLoading,
    overviewError,
    duplicatesLoading,
    duplicatesError,
    partialSplitLoading,
    partialSplitError,
    fetchOverview,
    fetchDuplicates,
    fetchPartialSplit,
    $reset,
  };
});
