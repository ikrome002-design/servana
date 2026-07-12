import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Platform merchant governance (Plan §22, §24.1; Phase 20B). UX state only — the API
 * (ResolvePlatformContext + MerchantPolicy governance abilities + mandatory MFA + a fresh
 * `merchant_governance` step-up) is the security boundary. Governance mutations change a merchant's
 * OPERATIONAL status ONLY (never the billing status, which is displayed separately); operational
 * reactivation is NOT a billing-recovery path. Every mutation requires a mandatory reason. There is
 * NO merchant-creation, first-admin, impersonation, manual-payment, or Wallet action here.
 */
export type PlatformMerchant = components['schemas']['PlatformMerchantResource'];
export type RegistrationMonitorRow = components['schemas']['MerchantRegistrationMonitorResource'];

export const usePlatformMerchantStore = defineStore('platformMerchant', () => {
  const registrations = ref<RegistrationMonitorRow[]>([]);
  const merchants = ref<PlatformMerchant[]>([]);
  const selected = ref<PlatformMerchant | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    registrations.value = [];
    merchants.value = [];
    selected.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchRegistrations(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await apiClient.get<{ data: RegistrationMonitorRow[] }>('/platform/registration-monitor');
      registrations.value = data.data;
    } catch {
      error.value = 'Unable to load registration monitoring.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchMerchants(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: PlatformMerchant[] }>('/platform/merchants', { params });
      merchants.value = data.data;
    } catch {
      error.value = 'Unable to load merchants.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchMerchant(id: string): Promise<PlatformMerchant> {
    const { data } = await apiClient.get<{ data: PlatformMerchant }>(`/platform/merchants/${id}`);
    selected.value = data.data;
    return data.data;
  }

  /** Governance mutations — mandatory reason; a missing fresh step-up returns 403 (surfaced). */
  async function suspend(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'suspend', reason);
  }

  async function reactivate(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'reactivate', reason);
  }

  async function deactivate(id: string, reason: string): Promise<PlatformMerchant> {
    return governance(id, 'deactivate', reason);
  }

  async function governance(id: string, action: 'suspend' | 'reactivate' | 'deactivate', reason: string): Promise<PlatformMerchant> {
    const { data } = await apiClient.post<{ data: PlatformMerchant }>(`/platform/merchants/${id}/${action}`, { reason });
    const idx = merchants.value.findIndex((m) => m.id === id);
    if (idx !== -1) merchants.value[idx] = data.data;
    if (selected.value?.id === id) selected.value = data.data;
    return data.data;
  }

  return {
    registrations,
    merchants,
    selected,
    loading,
    error,
    filterStatus,
    $reset,
    fetchRegistrations,
    fetchMerchants,
    fetchMerchant,
    suspend,
    reactivate,
    deactivate,
  };
});
