import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Promotional-discount management (Plan §53; Phase 20C). UX state only —
 * PromotionalDiscountPolicy is authoritative; promotions are platform-scoped, MFA-gated,
 * and every mutation requires a fresh step-up (server-enforced). Targets are explicit rows
 * referencing merchants/plans by ULID; the applied discount is snapshotted onto new invoices
 * and never recomputed. No merchant role can reach these endpoints.
 */
export type PromotionalDiscount = components['schemas']['PromotionalDiscountResource'];

export interface OfferTargetInput {
  target_type: 'merchant' | 'plan' | 'billing_mode';
  merchant_id?: string | null;
  subscription_plan_id?: string | null;
  billing_mode?: string | null;
}

export interface PromotionPayload {
  name: string;
  type: 'percentage' | 'fixed_amount';
  value: number;
  currency?: string | null;
  target_scope: 'all_new_merchants' | 'selected_merchants' | 'selected_plans' | 'billing_mode';
  effective_from: string;
  effective_to?: string | null;
  targets?: OfferTargetInput[];
}

export const usePromotionStore = defineStore('promotion', () => {
  const promotions = ref<PromotionalDiscount[]>([]);
  const current = ref<PromotionalDiscount | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    promotions.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchPromotions(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: PromotionalDiscount[] }>('/platform/promotional-discounts', { params });
      promotions.value = data.data;
    } catch {
      error.value = 'Unable to load promotional discounts.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchPromotion(id: string): Promise<PromotionalDiscount> {
    const { data } = await apiClient.get<{ data: PromotionalDiscount }>(`/platform/promotional-discounts/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createPromotion(payload: PromotionPayload): Promise<PromotionalDiscount> {
    const { data } = await apiClient.post<{ data: PromotionalDiscount }>('/platform/promotional-discounts', payload);
    current.value = data.data;
    return data.data;
  }

  async function updateDraft(id: string, payload: PromotionPayload): Promise<PromotionalDiscount> {
    const { data } = await apiClient.patch<{ data: PromotionalDiscount }>(`/platform/promotional-discounts/${id}`, payload);
    current.value = data.data;
    return data.data;
  }

  async function transition(
    id: string,
    action: 'approve' | 'pause' | 'resume' | 'cancel',
    changeReason: string,
  ): Promise<PromotionalDiscount> {
    const { data } = await apiClient.post<{ data: PromotionalDiscount }>(
      `/platform/promotional-discounts/${id}/${action}`,
      { change_reason: changeReason },
    );
    current.value = data.data;
    return data.data;
  }

  return {
    promotions,
    current,
    loading,
    error,
    filterStatus,
    $reset,
    fetchPromotions,
    fetchPromotion,
    createPromotion,
    updateDraft,
    transition,
  };
});
