import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
  },
}));

import {
  isTerminalPlanStatus,
  modelRequiresCommissionRule,
  modelRequiresSalary,
  useCompensationStore,
  type CommissionRule,
  type CommissionRulePayload,
  type CompensationPlan,
  type CompensationPlanPayload,
} from '@/stores/compensationStore';

// Typed against the GENERATED contract, so a fixture that drifts from the OpenAPI resource
// (a new field, a renamed status label) fails typecheck rather than passing a stale shape.
const rule: CommissionRule = {
  id: '01RULE',
  status: 'draft',
  status_label: 'Draft',
  calculation_type: 'percentage',
  calculation_basis: 'service_price',
  applies_to: 'all_services',
  selected_service_ulids: [],
  selected_services: [],
  percentage_basis_points: 1500,
  fixed_amount_minor: null,
  currency: null,
  applies_to_preferred_personnel_fee: false,
  effective_from: '2026-08-01',
  effective_to: null,
  notes: null,
  change_reason: 'Launch',
  is_editable: true,
  created_at: '2026-07-01T00:00:00+00:00',
  approved_at: null,
};

const plan: CompensationPlan = {
  id: '01PLAN',
  status: 'draft',
  status_label: 'Draft',
  staff_profile_id: '01STAFF',
  staff_display_name: 'Jane Doe',
  branch_id: '01BRANCH',
  compensation_model: 'salary_plus_commission',
  compensation_model_label: 'Salary plus commission',
  salary_amount_minor: 5000000,
  salary_currency: 'KES',
  salary_period: 'monthly',
  salary_payout_day: 28,
  commission_rule: rule,
  effective_from: '2026-08-01',
  effective_to: null,
  is_backdated: false,
  notes: null,
  change_reason: 'Promotion',
  submitted_at: null,
  approved_at: null,
  rejected_at: null,
  created_at: '2026-07-01T00:00:00+00:00',
  updated_at: '2026-07-01T00:00:00+00:00',
  capabilities: {
    can_update_draft: true,
    can_submit: true,
    can_approve: false,
    can_reject: false,
    can_cancel: true,
    is_terminal: false,
  },
};

const rulePayload: CommissionRulePayload = {
  calculation_type: 'percentage',
  calculation_basis: 'service_price',
  applies_to: 'all_services',
  percentage_basis_points: 1500,
  applies_to_preferred_personnel_fee: false,
  effective_from: '2026-08-01',
  change_reason: 'Launch',
};

const planPayload: CompensationPlanPayload = {
  staff_profile_id: '01STAFF',
  compensation_model: 'salary_plus_commission',
  commission_rule_id: '01RULE',
  salary_amount_minor: 5000000,
  salary_currency: 'KES',
  salary_period: 'monthly',
  effective_from: '2026-08-01',
  change_reason: 'Promotion',
};

function apiError(code: string, message: string, fields: Record<string, string[]> = {}): unknown {
  return Object.assign(new Error(code), { isAxiosError: true, apiError: { code, message, fields, meta: {} } });
}

describe('compensationStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
  });

  it('loads plans and applies the status + staff filters', async () => {
    get.mockResolvedValueOnce({ data: { data: [plan] } });
    const store = useCompensationStore();
    store.filterStatus = 'draft';
    store.filterStaffProfile = '01STAFF';
    await store.fetchPlans();
    expect(get).toHaveBeenCalledWith('/compensation-plans', {
      params: { status: 'draft', staff_profile_id: '01STAFF' },
    });
    expect(store.plans).toHaveLength(1);
    expect(store.loading).toBe(false);
  });

  it('narrows the loaded page by compensation model without inventing a query parameter', async () => {
    get.mockResolvedValueOnce({ data: { data: [plan, { ...plan, id: '01OTHER', compensation_model: 'salary_only' }] } });
    const store = useCompensationStore();
    store.filterModel = 'salary_only';
    await store.fetchPlans();
    expect(get).toHaveBeenCalledWith('/compensation-plans', { params: {} });
    expect(store.plans.map((p) => p.id)).toEqual(['01OTHER']);
  });

  it('surfaces a safe error and leaks no internals when the list fails', async () => {
    get.mockRejectedValueOnce(new Error('SQLSTATE 23514 personnel_compensation_plans_model_shape'));
    const store = useCompensationStore();
    await store.fetchPlans();
    expect(store.error).toBe('Unable to load compensation plans.');
    expect(store.error).not.toContain('SQLSTATE');
  });

  it('loads a plan detail', async () => {
    get.mockResolvedValueOnce({ data: { data: plan } });
    const store = useCompensationStore();
    const loaded = await store.fetchPlan('01PLAN');
    expect(get).toHaveBeenCalledWith('/compensation-plans/01PLAN');
    expect(loaded.id).toBe('01PLAN');
    expect(store.current?.id).toBe('01PLAN');
  });

  it('loads append-only history', async () => {
    get.mockResolvedValueOnce({ data: { data: [{ id: '01H', event: 'created', event_label: 'Created' }] } });
    const store = useCompensationStore();
    await store.fetchHistory('01PLAN');
    expect(get).toHaveBeenCalledWith('/compensation-plans/01PLAN/history');
    expect(store.history).toHaveLength(1);
    expect(store.historyLoading).toBe(false);
  });

  it('creates a commission rule draft', async () => {
    post.mockResolvedValueOnce({ data: { data: rule } });
    const store = useCompensationStore();
    const created = await store.createCommissionRule(rulePayload);
    expect(post).toHaveBeenCalledWith('/commission-rules', rulePayload);
    expect(created.status).toBe('draft');
    expect(store.rules).toHaveLength(1);
  });

  it('updates a commission rule draft through the draft route', async () => {
    patch.mockResolvedValueOnce({ data: { data: { ...rule, percentage_basis_points: 2000 } } });
    const store = useCompensationStore();
    store.rules = [rule];
    await store.updateCommissionRuleDraft('01RULE', { ...rulePayload, percentage_basis_points: 2000 });
    expect(patch).toHaveBeenCalledWith('/commission-rules/01RULE/draft', expect.objectContaining({ percentage_basis_points: 2000 }));
    expect(store.rules[0].percentage_basis_points).toBe(2000);
  });

  it('creates a plan draft', async () => {
    post.mockResolvedValueOnce({ data: { data: plan } });
    const store = useCompensationStore();
    await store.createPlan(planPayload);
    expect(post).toHaveBeenCalledWith('/compensation-plans', planPayload);
    expect(store.current?.id).toBe('01PLAN');
  });

  it('updates a plan draft through the draft route', async () => {
    patch.mockResolvedValueOnce({ data: { data: plan } });
    const store = useCompensationStore();
    await store.updatePlanDraft('01PLAN', planPayload);
    expect(patch).toHaveBeenCalledWith('/compensation-plans/01PLAN/draft', planPayload);
  });

  it('drives named transitions only — no generic status setter and no supersede', async () => {
    const store = useCompensationStore();
    store.plans = [plan];
    post.mockResolvedValue({ data: { data: { ...plan, status: 'pending_approval', status_label: 'Pending approval' } } });
    await store.transition('01PLAN', 'submit', { change_reason: 'Ready' });
    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/submit', { change_reason: 'Ready' });
    expect(store.plans[0].status).toBe('pending_approval');

    await store.transition('01PLAN', 'reject', { change_reason: 'No' });
    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/reject', { change_reason: 'No' });
    await store.transition('01PLAN', 'cancel', { change_reason: 'Stop' });
    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/cancel', { change_reason: 'Stop' });

    expect(Object.keys(store)).not.toContain('supersede');
    expect(post.mock.calls.every(([url]) => !String(url).includes('supersede'))).toBe(true);
  });

  it('sends the impact-preview acknowledgement on approve', async () => {
    post.mockResolvedValue({ data: { data: { ...plan, status: 'active', status_label: 'Active' } } });
    const store = useCompensationStore();
    await store.transition('01PLAN', 'approve', { change_reason: 'Agreed', acknowledge_impact_preview: true });
    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/approve', {
      change_reason: 'Agreed',
      acknowledge_impact_preview: true,
    });
  });

  it('leaves local state untouched when a transition is refused (validation)', async () => {
    const store = useCompensationStore();
    store.plans = [plan];
    post.mockRejectedValueOnce(apiError('validation_failed', 'The given data was invalid.', { change_reason: ['Required'] }));
    await expect(store.transition('01PLAN', 'submit', { change_reason: '' })).rejects.toBeTruthy();
    expect(store.plans[0].status).toBe('draft');
    expect(store.current).toBeNull();
  });

  it('leaves local state untouched when approval is forbidden', async () => {
    const store = useCompensationStore();
    store.plans = [plan];
    post.mockRejectedValueOnce(apiError('forbidden', 'This action is unauthorized.'));
    await expect(store.transition('01PLAN', 'approve', { change_reason: 'x' })).rejects.toBeTruthy();
    expect(store.plans[0].status).toBe('draft');
  });

  it('leaves local state untouched when a fresh step-up is required', async () => {
    const store = useCompensationStore();
    store.plans = [{ ...plan, status: 'pending_approval', status_label: 'Pending approval' }];
    post.mockRejectedValueOnce(apiError('step_up_required', 'A fresh step-up verification is required.'));
    await expect(
      store.transition('01PLAN', 'approve', { change_reason: 'x', acknowledge_impact_preview: true }),
    ).rejects.toBeTruthy();
    expect(store.plans[0].status).toBe('pending_approval');
  });

  it('leaves local state untouched on a maker/checker violation', async () => {
    const store = useCompensationStore();
    store.plans = [{ ...plan, status: 'pending_approval', status_label: 'Pending approval' }];
    post.mockRejectedValueOnce(
      apiError('maker_checker_violation', 'The person who submitted a compensation change cannot approve it.'),
    );
    await expect(store.transition('01PLAN', 'approve', { change_reason: 'x' })).rejects.toBeTruthy();
    expect(store.plans[0].status).toBe('pending_approval');
  });

  it('leaves local state untouched on an invalid transition', async () => {
    const store = useCompensationStore();
    store.plans = [plan];
    post.mockRejectedValueOnce(apiError('invalid_state_transition', 'A draft plan cannot be approved.'));
    await expect(store.transition('01PLAN', 'approve', { change_reason: 'x' })).rejects.toBeTruthy();
    expect(store.plans[0].status).toBe('draft');
  });

  it('clears stale branch/tenant data on reset', async () => {
    get.mockResolvedValueOnce({ data: { data: [plan] } });
    const store = useCompensationStore();
    await store.fetchPlans();
    store.rules = [rule];
    store.history = [{ id: '01H' } as never];
    store.filterStatus = 'active';
    store.$reset();
    expect(store.plans).toEqual([]);
    expect(store.rules).toEqual([]);
    expect(store.history).toEqual([]);
    expect(store.current).toBeNull();
    expect(store.filterStatus).toBe('');
    expect(store.filterStaffProfile).toBe('');
    expect(store.filterModel).toBe('');
  });

  it('exposes no earnings, payout, ledger or liability action', () => {
    const store = useCompensationStore();
    const keys = Object.keys(store).join(' ');
    for (const forbidden of ['earning', 'payout', 'ledger', 'liability', 'settle', 'wallet', 'accrual']) {
      expect(keys.toLowerCase()).not.toContain(forbidden);
    }
  });

  it('mirrors the F1 model shape helpers', () => {
    expect(modelRequiresSalary('salary_only')).toBe(true);
    expect(modelRequiresSalary('salary_plus_commission')).toBe(true);
    expect(modelRequiresSalary('commission_only')).toBe(false);
    expect(modelRequiresCommissionRule('commission_only')).toBe(true);
    expect(modelRequiresCommissionRule('salary_plus_commission')).toBe(true);
    expect(modelRequiresCommissionRule('salary_only')).toBe(false);
  });

  it('knows which plan statuses are terminal', () => {
    expect(isTerminalPlanStatus('superseded')).toBe(true);
    expect(isTerminalPlanStatus('expired')).toBe(true);
    expect(isTerminalPlanStatus('rejected')).toBe(true);
    expect(isTerminalPlanStatus('cancelled')).toBe(true);
    expect(isTerminalPlanStatus('active')).toBe(false);
    expect(isTerminalPlanStatus('draft')).toBe(false);
  });
});
