import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Percentage platform-fee CONFIGURATION management (Plan §51, §52; Phase 20E). UX state only —
 * `PlatformFeeConfigurationPolicy` is authoritative and every mutation is Super-Admin-only, platform-
 * scoped, MFA-gated and requires a fresh billing-configuration step-up (server-enforced). Approved
 * monetary terms are immutable: a change is a supersede (new version), never an in-place edit. The
 * backend never validates payments, fabricates ledger rows, or settles liabilities through these routes.
 */
export type PlatformFeeConfiguration = components['schemas']['PlatformFeeConfigurationResource'];

export interface PlatformFeeConfigPayload {
  billing_mode: string;
  percentage_basis_points?: number | null;
  fixed_component_minor?: number | null;
  tier_behavior?: string | null;
  shared_split_basis_points?: number | null;
  fee_basis_type?: string | null;
  currency: string;
  effective_from: string;
  effective_to?: string | null;
  change_reason: string;
}

const BASE = '/platform/billing/platform-fee-configurations';

export const usePlatformFeeConfigStore = defineStore('platformFeeConfig', () => {
  const configurations = ref<PlatformFeeConfiguration[]>([]);
  const current = ref<PlatformFeeConfiguration | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');

  function $reset(): void {
    configurations.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterStatus.value = '';
  }

  async function fetchConfigurations(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: PlatformFeeConfiguration[] }>(BASE, { params });
      configurations.value = data.data;
    } catch {
      error.value = 'Unable to load platform-fee configurations.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchConfiguration(id: string): Promise<PlatformFeeConfiguration> {
    const { data } = await apiClient.get<{ data: PlatformFeeConfiguration }>(`${BASE}/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createConfiguration(payload: PlatformFeeConfigPayload): Promise<PlatformFeeConfiguration> {
    const { data } = await apiClient.post<{ data: PlatformFeeConfiguration }>(BASE, payload);
    current.value = data.data;
    return data.data;
  }

  async function updateDraft(id: string, payload: PlatformFeeConfigPayload): Promise<PlatformFeeConfiguration> {
    const { data } = await apiClient.patch<{ data: PlatformFeeConfiguration }>(`${BASE}/${id}`, payload);
    current.value = data.data;
    return data.data;
  }

  /** Named transitions only — there is NO generic status setter (the backend rejects one). */
  async function transition(
    id: string,
    action: 'approve' | 'supersede' | 'cancel',
    payload: { change_reason: string } | PlatformFeeConfigPayload,
  ): Promise<PlatformFeeConfiguration> {
    const { data } = await apiClient.post<{ data: PlatformFeeConfiguration }>(`${BASE}/${id}/${action}`, payload);
    current.value = data.data;
    return data.data;
  }

  return {
    configurations,
    current,
    loading,
    error,
    filterStatus,
    $reset,
    fetchConfigurations,
    fetchConfiguration,
    createConfiguration,
    updateDraft,
    transition,
  };
});
