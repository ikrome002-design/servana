import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import type { ServiceFeeTier } from '@/types/models';

export interface FirstTimeSetupForm {
  service_fee_tier: ServiceFeeTier | '';
  subscription_plan_ulid: string;
  subscription_plan_price_ulid: string;
  business_category: string;
  contact_phone: string;
  contact_email: string;
  receipt_display_name: string;
  address: string;
  town: string;
  timezone: string;
  branch: {
    name: string;
    code: string;
    town: string;
    address: string;
    phone: string;
    email: string;
  };
  branch_manager_email: string;
  hr_email: string;
}

export function emptyFirstTimeSetupForm(): FirstTimeSetupForm {
  return {
    service_fee_tier: '',
    subscription_plan_ulid: '',
    subscription_plan_price_ulid: '',
    business_category: '',
    contact_phone: '',
    contact_email: '',
    receipt_display_name: '',
    address: '',
    town: '',
    timezone: 'Africa/Nairobi',
    branch: { name: '', code: '', town: '', address: '', phone: '', email: '' },
    branch_manager_email: '',
    hr_email: '',
  };
}

/**
 * First-time setup wizard state + submission (Scope §3.2, Plan §27 Phase 6).
 *
 * The wizard collects all steps client-side, then submits ONE transactional
 * payload to the server (the server enforces step completion). On success the
 * returned bootstrap is applied so the merchant flips to active in the SPA and
 * the owner can be routed to the dashboard.
 */
export const useOnboardingStore = defineStore('onboarding', () => {
  const form = ref<FirstTimeSetupForm>(emptyFirstTimeSetupForm());
  const submitting = ref(false);
  const loading = ref(false);
  const loadError = ref<string | null>(null);
  const serviceFeeTiers = ref<Array<{ value: ServiceFeeTier; label: string }>>([]);
  const subscriptionPlans = ref<Array<{
    id: string;
    name: string;
    description: string | null;
    tier: string | null;
    prices: Array<{
      id: string;
      amount_minor: number;
      currency: string;
      billing_interval: string;
    }>;
  }>>([]);

  const DRAFT_VERSION = 1;

  function draftKey(): string | null {
    const userId = useAuthStore().user?.id;
    return userId ? `servana.setup.v${DRAFT_VERSION}.${userId}` : null;
  }

  function restoreDraft(): void {
    const key = draftKey();
    if (key === null) return;
    try {
      const stored = JSON.parse(localStorage.getItem(key) ?? 'null') as {
        version?: number;
        form?: Partial<FirstTimeSetupForm>;
      } | null;
      if (stored?.version !== DRAFT_VERSION || stored.form === undefined) return;
      form.value = {
        ...emptyFirstTimeSetupForm(),
        ...stored.form,
        branch: { ...emptyFirstTimeSetupForm().branch, ...(stored.form.branch ?? {}) },
      };
    } catch {
      // A corrupt/unavailable device draft never weakens server validation.
    }
  }

  function saveDraft(): void {
    const key = draftKey();
    if (key === null) return;
    try {
      localStorage.setItem(key, JSON.stringify({ version: DRAFT_VERSION, form: form.value }));
    } catch {
      // Setup still works in this session when storage is unavailable.
    }
  }

  function clearDraft(): void {
    const key = draftKey();
    if (key !== null) localStorage.removeItem(key);
  }

  async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
      const { data } = await apiClient.get<{
        data: {
          options: {
            service_fee_tiers: Array<{ value: ServiceFeeTier; label: string }>;
            subscription_plans: typeof subscriptionPlans.value;
          };
        };
      }>('/merchant-registration/first-time-setup');
      serviceFeeTiers.value = data.data.options.service_fee_tiers;
      subscriptionPlans.value = data.data.options.subscription_plans;
      restoreDraft();
    } catch {
      loadError.value = 'We couldn’t load the setup options. Check your connection and try again.';
    } finally {
      loading.value = false;
    }
  }

  function reset(): void {
    form.value = emptyFirstTimeSetupForm();
    submitting.value = false;
    clearDraft();
  }

  /** Submit the completed setup; throws on validation/HTTP error for the caller. */
  async function complete(): Promise<void> {
    submitting.value = true;
    try {
      await apiClient.post('/merchant-registration/first-time-setup', form.value);
      // Re-bootstrap so merchant/membership/setup reflect the now-active tenant.
      await useAuthStore().bootstrap();
    } finally {
      submitting.value = false;
    }
  }

  return {
    form,
    submitting,
    loading,
    loadError,
    serviceFeeTiers,
    subscriptionPlans,
    load,
    saveDraft,
    clearDraft,
    reset,
    complete,
  };
});
