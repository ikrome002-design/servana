import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Subscription-plan catalogue + entitlements (Plan §13.8, §20, §47; ADR-011; Phase 20A).
 * UX state only — SubscriptionPlanPolicy / PlanEntitlementPolicy are authoritative; plans
 * are platform-scoped, MFA-gated, and management requires a fresh step-up.
 *
 * Plans carry NON-price catalogue metadata only (never a price column). Entitlements are
 * managed under `platform.plan.manage`. NO merchant plan-selection or subscription binding
 * exists here — that is Phase 20B.
 */
export type SubscriptionPlan = components['schemas']['SubscriptionPlanResource'];
export type PlanEntitlement = components['schemas']['PlanEntitlementResource'];

export interface CreatePlanPayload {
  key: string;
  name: string;
  description?: string | null;
  tier?: string | null;
  sort_order?: number | null;
  metadata?: Record<string, unknown>;
}

export interface UpdatePlanPayload {
  name?: string;
  description?: string | null;
  tier?: string | null;
  sort_order?: number;
  metadata?: Record<string, unknown>;
}

export const useSubscriptionPlanStore = defineStore('subscriptionPlan', () => {
  const plans = ref<SubscriptionPlan[]>([]);
  const current = ref<SubscriptionPlan | null>(null);
  const entitlements = ref<PlanEntitlement[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    plans.value = [];
    current.value = null;
    entitlements.value = [];
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchPlans(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: SubscriptionPlan[] }>('/platform/plans', { params });
      plans.value = data.data;
    } catch {
      error.value = 'Unable to load subscription plans.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchPlan(id: string): Promise<SubscriptionPlan> {
    const { data } = await apiClient.get<{ data: SubscriptionPlan }>(`/platform/plans/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createPlan(payload: CreatePlanPayload): Promise<SubscriptionPlan> {
    const { data } = await apiClient.post<{ data: SubscriptionPlan }>('/platform/plans', payload);
    current.value = data.data;
    return data.data;
  }

  async function updatePlan(id: string, payload: UpdatePlanPayload): Promise<SubscriptionPlan> {
    const { data } = await apiClient.put<{ data: SubscriptionPlan }>(`/platform/plans/${id}`, payload);
    current.value = data.data;
    return data.data;
  }

  async function retirePlan(id: string): Promise<SubscriptionPlan> {
    const { data } = await apiClient.post<{ data: SubscriptionPlan }>(`/platform/plans/${id}/retire`, {});
    current.value = data.data;
    return data.data;
  }

  async function fetchEntitlements(planId: string): Promise<void> {
    const { data } = await apiClient.get<{ data: PlanEntitlement[] }>(`/platform/plans/${planId}/entitlements`);
    entitlements.value = data.data;
  }

  /** Replace the plan's entitlement set (platform.plan.manage; fresh step-up server-enforced). */
  async function updateEntitlements(planId: string, next: PlanEntitlement[]): Promise<PlanEntitlement[]> {
    const { data } = await apiClient.put<{ data: PlanEntitlement[] }>(`/platform/plans/${planId}/entitlements`, {
      entitlements: next.map((e) => ({
        entitlement_key: e.entitlement_key,
        enabled: e.enabled,
        limit_int: e.limit_int,
      })),
    });
    entitlements.value = data.data;
    return data.data;
  }

  return {
    plans,
    current,
    entitlements,
    loading,
    error,
    filterStatus,
    $reset,
    fetchPlans,
    fetchPlan,
    createPlan,
    updatePlan,
    retirePlan,
    fetchEntitlements,
    updateEntitlements,
  };
});
