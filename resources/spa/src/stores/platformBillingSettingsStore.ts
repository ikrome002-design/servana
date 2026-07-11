import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Platform billing/general settings (Plan §13.9, §47, §50; ADR-011; Phase 20A). UX
 * state only — PlatformSettingsPolicy / PlatformBillingSettingsPolicy are authoritative,
 * and every mutation is platform-scoped, MFA-gated and requires a fresh
 * BillingConfiguration step-up (server-enforced). Reads never require step-up.
 *
 * There is ONE effective settings record at an instant; an update creates the next
 * effective-dated version rather than overwriting history. Only documented allowlisted
 * `settings` keys are edited here — no arbitrary JSON, no commercial values invented.
 */
export type PlatformSettings = components['schemas']['PlatformBillingSettingsResource'];

/** Canonical billing modes (mirrors BillingMode; kept in enum order). */
export const BILLING_MODES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'fixed_amount', label: 'Fixed amount' },
  { value: 'percentage_on_merchant_client_invoice', label: 'Percentage on merchant-client invoice' },
  {
    value: 'fixed_amount_plus_percentage_on_merchant_client_invoice',
    label: 'Fixed amount plus percentage on merchant-client invoice',
  },
];

/** Documented, allowlisted general-settings keys (edited as strings). */
export const GENERAL_SETTINGS_KEYS: ReadonlyArray<{ key: string; label: string; hint: string }> = [
  { key: 'invoice_due_days', label: 'Invoice due days', hint: 'Days a platform invoice remains payable before escalation.' },
  { key: 'support_email', label: 'Support email', hint: 'Contact address shown to merchants for billing queries.' },
  { key: 'statement_footer', label: 'Statement footer', hint: 'Footer note printed on platform billing statements.' },
];

export interface BillingSettingsPayload {
  billing_mode: string;
  default_trial_days: number;
  grace_days: number;
  currency: string;
  settings?: Record<string, string | null>;
}

export const usePlatformBillingSettingsStore = defineStore('platformBillingSettings', () => {
  const current = ref<PlatformSettings | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    current.value = null;
    loading.value = false;
    error.value = null;
  }

  async function fetch(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: PlatformSettings }>('/platform/billing-settings');
      current.value = data.data;
    } catch {
      error.value = 'Unable to load billing settings.';
    } finally {
      loading.value = false;
    }
  }

  /** Create the next effective billing-settings version (fresh step-up, server-enforced). */
  async function updateBillingSettings(payload: BillingSettingsPayload): Promise<PlatformSettings> {
    const { data } = await apiClient.put<{ data: PlatformSettings }>('/platform/billing-settings', payload, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  /** Create the next effective version updating only the allowlisted general `settings`. */
  async function updateGeneralSettings(settings: Record<string, string | null>): Promise<PlatformSettings> {
    const { data } = await apiClient.put<{ data: PlatformSettings }>('/platform/settings', { settings }, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  return { current, loading, error, $reset, fetch, updateBillingSettings, updateGeneralSettings };
});
