import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';
import type { OfferTargetInput } from '@/stores/promotionStore';

/**
 * Free-period (trial-length) offer management (Plan §53; Phase 20C). UX state only —
 * FreePeriodOfferPolicy is authoritative; offers are platform-scoped, MFA-gated, and every
 * mutation requires a fresh step-up. A resolved offer sets a new subscription's trial length
 * once at the founding-admin anchor; existing trials are never rewritten. Approval always
 * yields `scheduled` (no direct draft→active); activation is scheduler-driven.
 */
export type FreePeriodOffer = components['schemas']['FreePeriodOfferResource'];

export interface FreePeriodOfferPayload {
  name: string;
  free_period_days: number;
  target_scope: 'all_new_merchants' | 'selected_merchants' | 'selected_plans' | 'billing_mode';
  effective_from: string;
  effective_to?: string | null;
  targets?: OfferTargetInput[];
}

export const useFreePeriodOfferStore = defineStore('freePeriodOffer', () => {
  const offers = ref<FreePeriodOffer[]>([]);
  const current = ref<FreePeriodOffer | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    offers.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchOffers(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FreePeriodOffer[] }>('/platform/free-period-offers', { params });
      offers.value = data.data;
    } catch {
      error.value = 'Unable to load free-period offers.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchOffer(id: string): Promise<FreePeriodOffer> {
    const { data } = await apiClient.get<{ data: FreePeriodOffer }>(`/platform/free-period-offers/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createOffer(payload: FreePeriodOfferPayload): Promise<FreePeriodOffer> {
    const { data } = await apiClient.post<{ data: FreePeriodOffer }>('/platform/free-period-offers', payload);
    current.value = data.data;
    return data.data;
  }

  async function updateDraft(id: string, payload: FreePeriodOfferPayload): Promise<FreePeriodOffer> {
    const { data } = await apiClient.patch<{ data: FreePeriodOffer }>(`/platform/free-period-offers/${id}`, payload);
    current.value = data.data;
    return data.data;
  }

  async function transition(
    id: string,
    action: 'approve' | 'pause' | 'resume' | 'cancel',
    changeReason: string,
  ): Promise<FreePeriodOffer> {
    const { data } = await apiClient.post<{ data: FreePeriodOffer }>(
      `/platform/free-period-offers/${id}/${action}`,
      { change_reason: changeReason },
    );
    current.value = data.data;
    return data.data;
  }

  return {
    offers,
    current,
    loading,
    error,
    filterStatus,
    $reset,
    fetchOffers,
    fetchOffer,
    createOffer,
    updateDraft,
    transition,
  };
});
