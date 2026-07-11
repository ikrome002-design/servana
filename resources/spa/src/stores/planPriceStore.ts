import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Effective-dated subscription-plan prices (Plan §13.8, §47; ADR-011; Phase 20A). UX state
 * only — SubscriptionPlanPricePolicy is authoritative. This is the SOLE price source;
 * plans never carry a price column. Prices are platform-scoped, MFA-gated, and
 * create/cancel require a fresh step-up (server-enforced).
 *
 * A price is never destructively edited: changing a price creates a new effective-dated
 * record; overlapping ranges are rejected by PostgreSQL (409). Historical/current rows are
 * read-only; only a FUTURE price may be cancelled. Amounts are integer minor units.
 */
export type PlanPrice = components['schemas']['SubscriptionPlanPriceResource'];

/** Canonical billing intervals (mirrors BillingInterval; kept in enum order). */
export const BILLING_INTERVALS: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'weekly', label: 'Weekly' },
  { value: 'bi_weekly', label: 'Bi-weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'annual', label: 'Annual' },
];

export interface CreatePricePayload {
  amount_minor: number;
  currency: string;
  billing_interval: string;
  effective_from: string;
  effective_to?: string | null;
}

export const usePlanPriceStore = defineStore('planPrice', () => {
  const prices = ref<PlanPrice[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    prices.value = [];
    loading.value = false;
    error.value = null;
  }

  async function fetchPrices(planId: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PlanPrice[] }>(`/platform/plans/${planId}/prices`);
      prices.value = data.data;
    } catch {
      error.value = 'Unable to load plan prices.';
    } finally {
      loading.value = false;
    }
  }

  /** Create a current or future effective-dated price (financial create → idempotency key). */
  async function createPrice(planId: string, payload: CreatePricePayload): Promise<PlanPrice> {
    const { data } = await apiClient.post<{ data: PlanPrice }>(`/platform/plans/${planId}/prices`, payload, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    prices.value = [...prices.value, data.data];
    return data.data;
  }

  /** Cancel a FUTURE price only (current/historical are immutable). */
  async function cancelFuturePrice(priceId: string): Promise<PlanPrice> {
    const { data } = await apiClient.post<{ data: PlanPrice }>(`/platform/plan-prices/${priceId}/cancel`, {});
    prices.value = prices.value.filter((p) => p.id !== priceId);
    return data.data;
  }

  return { prices, loading, error, $reset, fetchPrices, createPrice, cancelFuturePrice };
});
