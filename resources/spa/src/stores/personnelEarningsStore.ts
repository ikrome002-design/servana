import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Phase 20H Personnel OWN-SCOPE earnings UX (Plan §63, §19.3; §H10/§H11). READ-only + on-demand
 * statement generation — the API derives the acting staff profile from the authenticated membership, so
 * the browser NEVER chooses a subject and NEVER sends a staff reference. Money is server-authoritative
 * integer minor units, grouped by currency and never combined. Statement download reuses the existing
 * Phase 10F file endpoints via the server-issued short-lived signed link (own-scope by owner). No other
 * staff data, no payout mutation, no Wallet/provider field.
 */
export type PayoutItem = components['schemas']['PersonnelPayoutItemResource'];
export type EarningsStatement = components['schemas']['EarningsStatementResource'];

export interface TabVisibility {
  model: string | null;
  has_current_plan: boolean;
  conflicting: boolean;
  salary_tab: boolean;
  commission_tab: boolean;
}

export interface EarningsCurrencyRow {
  currency: string;
  salary_unpaid_minor: number;
  salary_paid_minor: number;
  commission_unpaid_minor: number;
  commission_paid_minor: number;
  adjustment_unpaid_minor: number;
  adjustment_paid_minor: number;
  unpaid_minor: number;
  paid_minor: number;
  net_minor: number;
}

export interface EarningsOverview {
  tab_visibility: TabVisibility;
  currencies: EarningsCurrencyRow[];
}

export interface CompensationTerms {
  has_current_plan: boolean;
  conflicting: boolean;
  compensation_model?: string;
  salary_amount_minor?: number | null;
  salary_currency?: string | null;
  salary_period?: string | null;
  suspension_salary_policy?: string;
  effective_from?: string;
}

export interface SignedDownload {
  url: string;
  expires_at: string;
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface Paginated<T> {
  data: T[];
  meta: PageMeta;
}

const EMPTY_META: PageMeta = { current_page: 1, last_page: 1, per_page: 15, total: 0 };

function emptyOverview(): EarningsOverview {
  return {
    tab_visibility: { model: null, has_current_plan: false, conflicting: false, salary_tab: false, commission_tab: false },
    currencies: [],
  };
}

export const usePersonnelEarningsStore = defineStore('personnelEarnings', () => {
  const overview = ref<EarningsOverview>(emptyOverview());
  const terms = ref<CompensationTerms | null>(null);
  const payouts = ref<PayoutItem[]>([]);
  const payoutsMeta = ref<PageMeta>({ ...EMPTY_META });

  const overviewLoading = ref(false);
  const termsLoading = ref(false);
  const payoutsLoading = ref(false);
  const generating = ref(false);

  const overviewError = ref<string | null>(null);
  const termsError = ref<string | null>(null);
  const payoutsError = ref<string | null>(null);
  const forbidden = ref(false);

  function $reset(): void {
    overview.value = emptyOverview();
    terms.value = null;
    payouts.value = [];
    payoutsMeta.value = { ...EMPTY_META };
    overviewLoading.value = false;
    termsLoading.value = false;
    payoutsLoading.value = false;
    generating.value = false;
    overviewError.value = null;
    termsError.value = null;
    payoutsError.value = null;
    forbidden.value = false;
  }

  function noteForbidden(err: unknown): boolean {
    if (axios.isAxiosError(err) && err.response?.status === 403) {
      forbidden.value = true;
      return true;
    }
    return false;
  }

  async function fetchOverview(): Promise<void> {
    overviewLoading.value = true;
    overviewError.value = null;
    try {
      const { data } = await apiClient.get<{ data: EarningsOverview }>('/personnel/me/earnings');
      overview.value = data.data;
    } catch (err) {
      if (!noteForbidden(err)) overviewError.value = 'Unable to load your earnings.';
    } finally {
      overviewLoading.value = false;
    }
  }

  async function fetchTerms(): Promise<void> {
    termsLoading.value = true;
    termsError.value = null;
    try {
      const { data } = await apiClient.get<{ data: CompensationTerms }>('/personnel/me/compensation');
      terms.value = data.data;
    } catch (err) {
      if (!noteForbidden(err)) termsError.value = 'Unable to load your compensation terms.';
    } finally {
      termsLoading.value = false;
    }
  }

  async function fetchPayouts(page = payoutsMeta.value.current_page): Promise<void> {
    payoutsLoading.value = true;
    payoutsError.value = null;
    try {
      const { data } = await apiClient.get<Paginated<PayoutItem>>('/personnel/me/payouts', { params: { page } });
      payouts.value = data.data;
      payoutsMeta.value = data.meta ?? { ...EMPTY_META, current_page: page };
    } catch (err) {
      if (!noteForbidden(err)) payoutsError.value = 'Unable to load your payout history.';
    } finally {
      payoutsLoading.value = false;
    }
  }

  /**
   * Generate (or return the existing) earnings statement for a PAID payout item and return its safe
   * metadata + a short-lived signed download link. Idempotent server-side (a second call returns the same
   * file). Errors are rethrown for the screen to map to safe copy.
   */
  async function generateStatement(itemId: string): Promise<{ statement: EarningsStatement; download: SignedDownload }> {
    generating.value = true;
    try {
      const { data } = await apiClient.post<{ data: { statement: EarningsStatement; download: SignedDownload } }>(
        `/personnel/me/payout-items/${itemId}/statement`,
      );
      return data.data;
    } finally {
      generating.value = false;
    }
  }

  return {
    overview,
    terms,
    payouts,
    payoutsMeta,
    overviewLoading,
    termsLoading,
    payoutsLoading,
    generating,
    overviewError,
    termsError,
    payoutsError,
    forbidden,
    $reset,
    fetchOverview,
    fetchTerms,
    fetchPayouts,
    generateStatement,
  };
});
