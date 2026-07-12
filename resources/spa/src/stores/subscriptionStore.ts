import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Merchant subscription self-service (Plan §22, §47, §48; Phase 20B). UX state only — the API
 * (MerchantSubscriptionPolicy + EnsureBillingMutable + the subscription state machine) is the
 * security boundary. The merchant's OPERATIONAL status and BILLING status are independent and are
 * surfaced separately. A plan change is a NO-PRORATION next-cycle change: `effective_at` is computed
 * server-side (the current period end) and is never client-supplied; there is no immediate/mid-cycle
 * or client-dated change. Billing read-only states (`read_only_grace`/`suspended_billing`) block the
 * plan-change mutation server-side; the UI mirrors this via the server-derived `can` map.
 */
export type MerchantSubscription = components['schemas']['MerchantSubscriptionResource'];
export type SubscriptionPlanOption = components['schemas']['SubscriptionPlanOptionResource'];
export type ScheduledPlanChange = components['schemas']['ScheduledPlanChangeResource'];

export const useSubscriptionStore = defineStore('subscription', () => {
  const subscription = ref<MerchantSubscription | null>(null);
  const plans = ref<SubscriptionPlanOption[]>([]);
  const scheduledChange = ref<ScheduledPlanChange | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    subscription.value = null;
    plans.value = [];
    scheduledChange.value = null;
    loading.value = false;
    error.value = null;
  }

  async function fetchSubscription(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: MerchantSubscription }>('/subscription');
      subscription.value = data.data;
      scheduledChange.value = data.data.scheduled_plan_change ?? null;
    } catch {
      error.value = 'Unable to load your subscription.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchPlans(): Promise<void> {
    const { data } = await apiClient.get<{ data: SubscriptionPlanOption[] }>('/subscription/plans');
    plans.value = data.data;
  }

  async function fetchScheduledChange(): Promise<void> {
    const { data } = await apiClient.get<{ data: ScheduledPlanChange | null }>('/subscription/scheduled-plan-change');
    scheduledChange.value = data.data;
  }

  /**
   * Schedule a no-proration next-cycle plan change. The server rejects a second pending change
   * with a structured 409 (`scheduled_plan_change_exists`) and validation errors with 422; both
   * are surfaced to the caller (never swallowed).
   */
  async function schedulePlanChange(planUlid: string, priceUlid: string): Promise<ScheduledPlanChange> {
    const { data } = await apiClient.post<{ data: ScheduledPlanChange }>('/subscription/scheduled-plan-change', {
      subscription_plan_ulid: planUlid,
      subscription_plan_price_ulid: priceUlid,
    });
    scheduledChange.value = data.data;
    return data.data;
  }

  async function cancelScheduledChange(): Promise<ScheduledPlanChange> {
    const { data } = await apiClient.post<{ data: ScheduledPlanChange }>('/subscription/scheduled-plan-change/cancel');
    scheduledChange.value = null;
    return data.data;
  }

  return {
    subscription,
    plans,
    scheduledChange,
    loading,
    error,
    $reset,
    fetchSubscription,
    fetchPlans,
    fetchScheduledChange,
    schedulePlanChange,
    cancelScheduledChange,
  };
});
