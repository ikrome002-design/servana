import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const put = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), put: (...a: unknown[]) => put(...a) },
}));

import {
  BILLING_MODES,
  usePlatformBillingSettingsStore,
} from '@/stores/platformBillingSettingsStore';

const settings = {
  id: '01HZ',
  billing_mode: 'fixed_amount',
  default_trial_days: 14,
  grace_days: 7,
  currency: 'KES',
  settings: { invoice_due_days: '30' },
  effective_from: '2026-07-10T00:00:00+00:00',
};

describe('platformBillingSettingsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    put.mockReset();
  });

  it('exposes the three canonical billing modes in order', () => {
    expect(BILLING_MODES.map((m) => m.value)).toEqual([
      'fixed_amount',
      'percentage_on_merchant_client_invoice',
      'fixed_amount_plus_percentage_on_merchant_client_invoice',
    ]);
  });

  it('fetches the current effective settings', async () => {
    get.mockResolvedValueOnce({ data: { data: settings } });
    const store = usePlatformBillingSettingsStore();
    await store.fetch();
    expect(get).toHaveBeenCalledWith('/platform/billing-settings');
    expect(store.current?.currency).toBe('KES');
    expect(store.error).toBeNull();
  });

  it('surfaces a load error without throwing', async () => {
    get.mockRejectedValueOnce(new Error('boom'));
    const store = usePlatformBillingSettingsStore();
    await store.fetch();
    expect(store.error).toBe('Unable to load billing settings.');
    expect(store.current).toBeNull();
  });

  it('updates billing settings with an idempotency key and returns the new version', async () => {
    put.mockResolvedValueOnce({ data: { data: { ...settings, default_trial_days: 30 } } });
    const store = usePlatformBillingSettingsStore();
    const result = await store.updateBillingSettings({
      billing_mode: 'fixed_amount',
      default_trial_days: 30,
      grace_days: 7,
      currency: 'KES',
    });
    expect(put).toHaveBeenCalledWith(
      '/platform/billing-settings',
      { billing_mode: 'fixed_amount', default_trial_days: 30, grace_days: 7, currency: 'KES' },
      { headers: { 'Idempotency-Key': expect.any(String) } },
    );
    expect(result.default_trial_days).toBe(30);
    expect(store.current?.default_trial_days).toBe(30);
  });

  it('updates only the allowlisted general settings jsonb', async () => {
    put.mockResolvedValueOnce({ data: { data: settings } });
    const store = usePlatformBillingSettingsStore();
    await store.updateGeneralSettings({ invoice_due_days: '30', support_email: null });
    expect(put).toHaveBeenCalledWith(
      '/platform/settings',
      { settings: { invoice_due_days: '30', support_email: null } },
      { headers: { 'Idempotency-Key': expect.any(String) } },
    );
  });
});
