import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';

/**
 * Branch Manager READ-ONLY view of the EFFECTIVE preferred-personnel fee rule (Plan §13.10,
 * §39; Phase 20A). UX state only — the API (PreferredPersonnelFeeRulePolicy::viewBranchRule)
 * is authoritative. Branch-scoped, `preferred_personnel_fee.view_branch_rule`, NO MFA/step-up,
 * NO mutation controls, NO draft/scheduled/administration data. Only the currently-effective
 * applicable terms are returned (never internal IDs, approval metadata, or the legacy column).
 */
export interface BranchEffectiveFee {
  calculation_type: string;
  fixed_amount_minor: number | null;
  percentage_basis_points: number | null;
  currency: string | null;
  calculation_basis: string;
  effective_from: string;
  effective_to: string | null;
}

export const useBranchPreferredFeeStore = defineStore('branchPreferredFee', () => {
  const rule = ref<BranchEffectiveFee | null>(null);
  const loaded = ref(false);
  const loading = ref(false);
  const error = ref<string | null>(null);

  function $reset(): void {
    rule.value = null;
    loaded.value = false;
    loading.value = false;
    error.value = null;
  }

  /** Fetch the effective rule; an optional service ULID narrows to a service-scoped override. */
  async function fetchEffective(service?: string): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (service !== undefined && service !== '') params.service = service;
      const { data } = await apiClient.get<{ data: BranchEffectiveFee | null }>(
        '/branch/preferred-personnel-fee-rule',
        { params },
      );
      rule.value = data.data;
      loaded.value = true;
    } catch {
      error.value = 'Unable to load the effective preferred-personnel fee.';
    } finally {
      loading.value = false;
    }
  }

  return { rule, loaded, loading, error, $reset, fetchEffective };
});
