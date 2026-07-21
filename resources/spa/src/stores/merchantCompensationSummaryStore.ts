import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { apiClient } from '@/services/apiClient';

/**
 * Phase 20H Merchant-Administrator compensation summary (Plan §62/§63, §19.3;
 * `merchant.compensation_summary.view`). READ-only UX state — the API is the security boundary. Every
 * figure is a server-derived integer minor unit, grouped by currency and NEVER combined; the browser
 * computes no authoritative money. This is a compensation overview, not a payout mutation surface.
 */
export interface OutstandingLiabilityRow {
  currency: string;
  gross_salary_accrual_minor: number;
  salary_reversal_minor: number;
  net_salary_liability_minor: number;
  gross_earned_commission_minor: number;
  commission_reversal_minor: number;
  net_commission_liability_minor: number;
  compensation_adjustment_minor: number;
  combined_net_liability_minor: number;
}

export interface PaidByCurrencyRow {
  currency: string;
  paid_gross_minor: number;
  run_count: number;
}

export interface CompensationSummary {
  outstanding_liability_by_currency: OutstandingLiabilityRow[];
  paid_by_currency: PaidByCurrencyRow[];
  payout_runs_by_status: Record<string, number>;
  pending_high_value_approvals: number;
}

function emptySummary(): CompensationSummary {
  return {
    outstanding_liability_by_currency: [],
    paid_by_currency: [],
    payout_runs_by_status: {},
    pending_high_value_approvals: 0,
  };
}

export const useMerchantCompensationSummaryStore = defineStore('merchantCompensationSummary', () => {
  const summary = ref<CompensationSummary>(emptySummary());
  const loading = ref(false);
  const error = ref<string | null>(null);
  const forbidden = ref(false);
  const loaded = ref(false);

  function $reset(): void {
    summary.value = emptySummary();
    loading.value = false;
    error.value = null;
    forbidden.value = false;
    loaded.value = false;
  }

  async function fetchSummary(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: CompensationSummary }>('/merchant/compensation-summary');
      summary.value = data.data;
      loaded.value = true;
    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.status === 403) {
        forbidden.value = true;
      } else {
        error.value = 'Unable to load the compensation summary.';
      }
    } finally {
      loading.value = false;
    }
  }

  return { summary, loading, error, forbidden, loaded, $reset, fetchSummary };
});
