import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components } from '@/types/generated/api';

/**
 * HR compensation-plan + commission-rule CONFIGURATION (Plan §59, §80; Scope §12.1-§12.9, §18.3;
 * Phase 20F). UX state only — CompensationPlanPolicy/CommissionRulePolicy, EnsureBranchScope,
 * EnsurePermission and RequireFreshMfa are the security boundary. Branch-scoped, HR-only.
 *
 * Configuration only: this store loads and writes DECLARED terms. It never computes or holds an
 * earned commission, a salary accrual, a payout, an earnings statement or a liability — those are
 * Phase 20G/20H surfaces and no endpoint for them exists. Money stays in integer minor units; the
 * screen formats for display only.
 *
 * One named transition per action — there is no generic status setter, no DELETE, and no manual
 * supersede call: supersede is a CONSEQUENCE of approving a successor, applied server-side.
 */
export type CompensationPlan = components['schemas']['CompensationPlanResource'];
export type CommissionRule = components['schemas']['CommissionRuleResource'];
export type CompensationPlanHistoryEntry = components['schemas']['CompensationPlanHistoryResource'];
export type CompensationModel = components['schemas']['CompensationModel'];
export type SalaryPeriod = components['schemas']['SalaryPeriod'];

interface Option {
  value: string;
  label: string;
}

/** Labels mirror the backing enums' `label()` (Plan §59) — sentence case, no earnings language. */
export const COMPENSATION_MODELS: ReadonlyArray<Option> = [
  { value: 'salary_only', label: 'Salary only' },
  { value: 'commission_only', label: 'Commission only' },
  { value: 'salary_plus_commission', label: 'Salary plus commission' },
];

export const SALARY_PERIODS: ReadonlyArray<Option> = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'daily', label: 'Daily' },
  { value: 'hourly', label: 'Hourly' },
  { value: 'per_shift', label: 'Per shift' },
];

export const COMMISSION_CALCULATION_TYPES: ReadonlyArray<Option> = [
  { value: 'percentage', label: 'Percentage' },
  { value: 'fixed_amount', label: 'Fixed amount' },
];

export const COMMISSION_CALCULATION_BASES: ReadonlyArray<Option> = [
  { value: 'service_price', label: 'Service price' },
  { value: 'invoice_item_total', label: 'Invoice item total' },
  { value: 'paid_amount', label: 'Paid amount' },
  { value: 'net_after_discount', label: 'Net after discount' },
];

export const COMMISSION_APPLIES_TO: ReadonlyArray<Option> = [
  { value: 'all_services', label: 'All services' },
  { value: 'selected_services', label: 'Selected services' },
  { value: 'service_category', label: 'Service category' },
];

export const PLAN_STATUSES: ReadonlyArray<Option> = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'superseded', label: 'Superseded' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
];

/** F1 model shape (mirrors CompensationModel::requiresSalary; the DB CHECK stays authoritative). */
export function modelRequiresSalary(model: string): boolean {
  return model === 'salary_only' || model === 'salary_plus_commission';
}

/** F1 model shape (mirrors CompensationModel::requiresCommissionRule). */
export function modelRequiresCommissionRule(model: string): boolean {
  return model === 'commission_only' || model === 'salary_plus_commission';
}

export function isTerminalPlanStatus(status: string): boolean {
  return ['expired', 'superseded', 'rejected', 'cancelled'].includes(status);
}

export interface CommissionRulePayload {
  calculation_type: string;
  calculation_basis: string;
  applies_to: string;
  service_category_id?: string | null;
  percentage_basis_points?: number | null;
  fixed_amount_minor?: number | null;
  currency?: string | null;
  applies_to_preferred_personnel_fee: boolean;
  effective_from: string;
  effective_to?: string | null;
  change_reason: string;
  notes?: string | null;
}

export interface CompensationPlanPayload {
  staff_profile_id: string;
  compensation_model: string;
  commission_rule_id?: string | null;
  salary_amount_minor?: number | null;
  salary_currency?: string | null;
  salary_period?: string | null;
  salary_payout_day?: number | null;
  effective_from: string;
  effective_to?: string | null;
  change_reason: string;
  notes?: string | null;
}

/** Named plan transitions. There is deliberately no `supersede` and no `status` verb. */
export type PlanTransition = 'submit' | 'approve' | 'reject' | 'cancel';

export interface TransitionPayload {
  change_reason: string;
  /** Approve only — the approver's acknowledgement that the server-built impact preview was shown. */
  acknowledge_impact_preview?: boolean;
}

export const useCompensationStore = defineStore('compensation', () => {
  const plans = ref<CompensationPlan[]>([]);
  const current = ref<CompensationPlan | null>(null);
  const history = ref<CompensationPlanHistoryEntry[]>([]);
  const rules = ref<CommissionRule[]>([]);
  const loading = ref(false);
  const historyLoading = ref(false);
  const error = ref<string | null>(null);
  const filterStatus = ref<string>('');
  const filterStaffProfile = ref<string>('');
  const filterModel = ref<string>('');

  /**
   * Drop every plan/rule/history fact. Called on branch or tenant context change so another
   * branch's configuration can never linger on screen after the acting context moves.
   */
  function $reset(): void {
    plans.value = [];
    current.value = null;
    history.value = [];
    rules.value = [];
    loading.value = false;
    historyLoading.value = false;
    error.value = null;
    filterStatus.value = '';
    filterStaffProfile.value = '';
    filterModel.value = '';
  }

  async function fetchPlans(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string> = {};
      if (filterStatus.value !== '') params.status = filterStatus.value;
      if (filterStaffProfile.value !== '') params.staff_profile_id = filterStaffProfile.value;
      const { data } = await apiClient.get<{ data: CompensationPlan[] }>('/compensation-plans', { params });
      // `compensation_model` has no server-side filter; narrow client-side over the returned page
      // rather than inventing a query parameter the contract does not declare.
      plans.value =
        filterModel.value === ''
          ? data.data
          : data.data.filter((p) => p.compensation_model === filterModel.value);
    } catch {
      error.value = 'Unable to load compensation plans.';
    } finally {
      loading.value = false;
    }
  }

  async function fetchPlan(id: string): Promise<CompensationPlan> {
    const { data } = await apiClient.get<{ data: CompensationPlan }>(`/compensation-plans/${id}`);
    current.value = data.data;
    return data.data;
  }

  /** Append-only change history — rendered verbatim; the SPA never synthesizes an event. */
  async function fetchHistory(id: string): Promise<void> {
    historyLoading.value = true;
    history.value = [];
    try {
      const { data } = await apiClient.get<{ data: CompensationPlanHistoryEntry[] }>(
        `/compensation-plans/${id}/history`,
      );
      history.value = data.data;
    } finally {
      historyLoading.value = false;
    }
  }

  async function fetchCommissionRules(): Promise<void> {
    const { data } = await apiClient.get<{ data: CommissionRule[] }>('/commission-rules', { params: {} });
    rules.value = data.data;
  }

  async function createCommissionRule(payload: CommissionRulePayload): Promise<CommissionRule> {
    const { data } = await apiClient.post<{ data: CommissionRule }>('/commission-rules', payload);
    rules.value = [data.data, ...rules.value];
    return data.data;
  }

  async function updateCommissionRuleDraft(id: string, payload: CommissionRulePayload): Promise<CommissionRule> {
    const { data } = await apiClient.patch<{ data: CommissionRule }>(`/commission-rules/${id}/draft`, payload);
    rules.value = rules.value.map((r) => (r.id === id ? data.data : r));
    return data.data;
  }

  async function createPlan(payload: CompensationPlanPayload): Promise<CompensationPlan> {
    const { data } = await apiClient.post<{ data: CompensationPlan }>('/compensation-plans', payload);
    current.value = data.data;
    return data.data;
  }

  async function updatePlanDraft(id: string, payload: CompensationPlanPayload): Promise<CompensationPlan> {
    const { data } = await apiClient.patch<{ data: CompensationPlan }>(`/compensation-plans/${id}/draft`, payload);
    current.value = data.data;
    return data.data;
  }

  /**
   * Drive one named transition. Local state is written ONLY from the server's response, so a
   * rejected submit/approve (invalid transition, maker/checker, stale step-up) never leaves the
   * screen showing a state the backend did not grant.
   */
  async function transition(
    id: string,
    verb: PlanTransition,
    payload: TransitionPayload,
  ): Promise<CompensationPlan> {
    const { data } = await apiClient.post<{ data: CompensationPlan }>(
      `/compensation-plans/${id}/${verb}`,
      payload,
    );
    current.value = data.data;
    plans.value = plans.value.map((p) => (p.id === id ? data.data : p));
    return data.data;
  }

  return {
    plans,
    current,
    history,
    rules,
    loading,
    historyLoading,
    error,
    filterStatus,
    filterStaffProfile,
    filterModel,
    $reset,
    fetchPlans,
    fetchPlan,
    fetchHistory,
    fetchCommissionRules,
    createCommissionRule,
    updateCommissionRuleDraft,
    createPlan,
    updatePlanDraft,
    transition,
  };
});
