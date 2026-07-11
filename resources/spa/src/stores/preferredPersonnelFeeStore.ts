import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * Preferred-personnel fee rules — platform administration (Plan §13.10, §47; ADR-011;
 * Phase 20A). UX state only — PreferredPersonnelFeeRulePolicy is authoritative. Management
 * is Super-Admin only, platform-scoped, MFA-gated; create/supersede/approve/cancel require
 * a fresh step-up (server-enforced).
 *
 * Active monetary terms are immutable: a change SUPERSEDES with a new version rather than
 * editing. Fixed and percentage terms are mutually exclusive; basis points are 0..10000;
 * platform_default forbids a service while service scope requires one. Overlaps are
 * rejected by PostgreSQL (409). Only draft/scheduled rules may be cancelled.
 */
export type FeeRule = components['schemas']['PreferredPersonnelFeeRuleResource'];

export const FEE_CALCULATION_TYPES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'fixed_amount', label: 'Fixed amount' },
  { value: 'percentage', label: 'Percentage' },
];

export const FEE_CALCULATION_BASES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'service_item_net_amount', label: 'Service item net amount' },
  { value: 'service_item_gross_amount', label: 'Service item gross amount' },
];

export const FEE_SCOPES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'platform_default', label: 'Platform default' },
  { value: 'service', label: 'Service' },
];

export const FEE_STATUSES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'draft', label: 'Draft' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'active', label: 'Active' },
  { value: 'superseded', label: 'Superseded' },
  { value: 'expired', label: 'Expired' },
  { value: 'cancelled', label: 'Cancelled' },
];

/** Terminal states have no further controls. */
export function isTerminalFeeStatus(status: string): boolean {
  return status === 'superseded' || status === 'expired' || status === 'cancelled';
}

export interface CreateFeeRulePayload {
  calculation_type: string;
  fixed_amount_minor?: number | null;
  currency?: string | null;
  percentage_basis_points?: number | null;
  calculation_basis: string;
  scope: string;
  service_id?: string | null;
  effective_from: string;
  effective_to?: string | null;
  change_reason: string;
}

export interface SupersedeFeeRulePayload {
  calculation_type: string;
  fixed_amount_minor?: number | null;
  currency?: string | null;
  percentage_basis_points?: number | null;
  calculation_basis: string;
  effective_from: string;
  effective_to?: string | null;
  change_reason: string;
}

export const usePreferredPersonnelFeeStore = defineStore('preferredPersonnelFee', () => {
  const rules = ref<FeeRule[]>([]);
  const current = ref<FeeRule | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const filterScope = ref<string>('');
  const filterStatus = ref<string>('');

  function $reset(): void {
    rules.value = [];
    current.value = null;
    loading.value = false;
    error.value = null;
    filterScope.value = '';
    filterStatus.value = '';
  }

  async function fetchRules(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterScope.value !== '') params.scope = filterScope.value;
      if (filterStatus.value !== '') params.status = filterStatus.value;
      const { data } = await apiClient.get<{ data: FeeRule[] }>('/platform/preferred-personnel-fee-rules', { params });
      rules.value = data.data;
    } catch {
      error.value = 'Unable to load preferred-personnel fee rules.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchRule(id: string): Promise<FeeRule> {
    const { data } = await apiClient.get<{ data: FeeRule }>(`/platform/preferred-personnel-fee-rules/${id}`);
    current.value = data.data;
    return data.data;
  }

  async function createRule(payload: CreateFeeRulePayload): Promise<FeeRule> {
    const { data } = await apiClient.post<{ data: FeeRule }>('/platform/preferred-personnel-fee-rules', payload, {
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    });
    current.value = data.data;
    return data.data;
  }

  async function approveRule(id: string): Promise<FeeRule> {
    const { data } = await apiClient.post<{ data: FeeRule }>(
      `/platform/preferred-personnel-fee-rules/${id}/approve`,
      {},
    );
    current.value = data.data;
    return data.data;
  }

  async function supersedeRule(id: string, payload: SupersedeFeeRulePayload): Promise<FeeRule> {
    const { data } = await apiClient.post<{ data: FeeRule }>(
      `/platform/preferred-personnel-fee-rules/${id}/supersede`,
      payload,
      { headers: { 'Idempotency-Key': crypto.randomUUID() } },
    );
    current.value = data.data;
    return data.data;
  }

  async function cancelRule(id: string): Promise<FeeRule> {
    const { data } = await apiClient.post<{ data: FeeRule }>(
      `/platform/preferred-personnel-fee-rules/${id}/cancel`,
      {},
    );
    current.value = data.data;
    return data.data;
  }

  return {
    rules,
    current,
    loading,
    error,
    filterScope,
    filterStatus,
    $reset,
    fetchRules,
    fetchRule,
    createRule,
    approveRule,
    supersedeRule,
    cancelRule,
  };
});
