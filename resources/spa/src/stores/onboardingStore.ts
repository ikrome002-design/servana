import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import type { ServiceFeeTier } from '@/types/models';

export interface FirstTimeSetupForm {
  service_fee_tier: ServiceFeeTier | '';
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

  function reset(): void {
    form.value = emptyFirstTimeSetupForm();
    submitting.value = false;
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

  return { form, submitting, reset, complete };
});
