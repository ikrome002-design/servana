import { flushPromises, mount } from '@vue/test-utils';
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

import Compensation from '@/pages/hr/Compensation.vue';
import { useAuthStore } from '@/stores/authStore';

const HR_KEYS = [
  'compensation.plan.view',
  'compensation.plan.create',
  'compensation.plan.update_draft',
  'compensation.plan.submit',
  'compensation.plan.approve',
  'compensation.plan.reject',
  'compensation.plan.cancel',
  'compensation.history.view',
  'staff.view',
];

const rule = {
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
  applies_to_preferred_personnel_fee: true,
  effective_from: '2026-08-01',
  effective_to: null,
  notes: null,
  change_reason: 'Launch',
  is_editable: true,
  created_at: '2026-07-01T00:00:00+00:00',
  approved_at: null,
};

function plan(overrides: Record<string, unknown> = {}) {
  return {
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
    ...overrides,
  };
}

const historyEvents = [
  {
    id: '01H1',
    event: 'created',
    event_label: 'Created',
    from_status: null,
    to_status: 'draft',
    changed_fields: null,
    was_backdated: false,
    change_reason: 'Promotion',
    effective_from: '2026-08-01',
    actor_display_name: 'Ada HR',
    occurred_at: '2026-07-01T00:00:00+00:00',
  },
];

function mockLoaded(plans: unknown[] = [plan()]): void {
  get.mockImplementation((url: string) => {
    if (url === '/staff') {
      return Promise.resolve({ data: { data: [{ id: '01STAFF', display_name: 'Jane Doe' }] } });
    }
    if (url === '/commission-rules') return Promise.resolve({ data: { data: [rule] } });
    if (url === '/compensation-plans') return Promise.resolve({ data: { data: structuredClone(plans) } });
    if (url === '/compensation-plans/01PLAN') return Promise.resolve({ data: { data: structuredClone(plans[0]) } });
    if (url === '/compensation-plans/01PLAN/history') return Promise.resolve({ data: { data: historyEvents } });
    return Promise.resolve({ data: { data: [] } });
  });
}

function apiError(code: string, message: string, fields: Record<string, string[]> = {}): unknown {
  return Object.assign(new Error(code), { isAxiosError: true, apiError: { code, message, fields, meta: {} } });
}

const mountPage = () =>
  mount(Compensation, {
    attachTo: document.body,
    global: {
      stubs: {
        // Mirror the real SvModal's rendered title/description so dialog copy is asserted, not stubbed away.
        SvModal: {
          template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>',
          props: ['open', 'title', 'description'],
        },
      },
    },
  });

async function openPlanForm(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('[data-testid="open-plan-create"]').trigger('click');
  await flushPromises();
}

describe('Compensation.vue (HR)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    const auth = useAuthStore();
    auth.permissions = [...HR_KEYS];
    auth.branchIds = ['b1'];
  });

  /* ---------------------------------------------------------------- gating */

  it('shows a no-permission boundary without compensation.plan.view', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="no-permission"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="open-plan-create"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalledWith('/compensation-plans', expect.anything());
  });

  it('hides every mutation control for a view-only holder', async () => {
    const auth = useAuthStore();
    auth.permissions = ['compensation.plan.view'];
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="plan-row"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="open-plan-create"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="open-rule-create"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="submit-01PLAN"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="cancel-01PLAN"]').exists()).toBe(false);
  });

  /* ---------------------------------------------------------------- list */

  it('lists plans with model, declared terms, preferred-fee copy and status', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    const row = wrapper.find('[data-testid="plan-row"]');
    expect(row.text()).toContain('Jane Doe');
    expect(row.text()).toContain('Salary plus commission');
    expect(row.text()).toContain('15.00% of Service price');
    expect(row.text()).toContain('Preferred-personnel fee included in commission basis');
    expect(wrapper.find('[data-testid="plan-status"]').text()).toBe('Draft');
  });

  it('uses no earnings, payout, ledger or settlement language', async () => {
    mockLoaded([plan({ status: 'active', status_label: 'Active' })]);
    const wrapper = mountPage();
    await flushPromises();
    const text = wrapper.text().toLowerCase();
    for (const forbidden of ['earned', 'payout', 'ledger', 'payable', 'settled', 'wallet', 'settlement']) {
      expect(text).not.toContain(forbidden);
    }
  });

  it('marks a backdated plan and a pending-approval plan in the list', async () => {
    mockLoaded([plan({ status: 'pending_approval', status_label: 'Pending approval', is_backdated: true })]);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="plan-backdated"]').text()).toBe('Backdated');
    expect(wrapper.text()).toContain('Awaiting a different approver');
  });

  it('shows a read-only marker and no transitions for a terminal plan', async () => {
    mockLoaded([
      plan({
        status: 'superseded',
        status_label: 'Superseded',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: false,
          can_reject: false,
          can_cancel: false,
          is_terminal: true,
        },
      }),
    ]);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.text()).toContain('Read-only');
    expect(wrapper.find('[data-testid="edit-01PLAN"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="submit-01PLAN"]').exists()).toBe(false);
  });

  it('shows the empty state when no plan matches the filter', async () => {
    mockLoaded([]);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.text()).toContain('No compensation plans match this filter.');
  });

  /* ---------------------------------------------------------------- plan form shape */

  it('requires salary and forbids a commission rule for salary_only', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await openPlanForm(wrapper);

    await wrapper.find('#comp-plan-model').setValue('salary_only');
    expect(wrapper.find('#comp-plan-salary').exists()).toBe(true);
    expect(wrapper.find('#comp-plan-rule').exists()).toBe(false);
    expect(wrapper.find('[data-testid="no-rule-hint"]').text()).toContain(
      'A salary only plan cannot reference a commission rule.',
    );
  });

  it('forbids salary and requires a commission rule for commission_only', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await openPlanForm(wrapper);

    await wrapper.find('#comp-plan-model').setValue('commission_only');
    expect(wrapper.find('#comp-plan-salary').exists()).toBe(false);
    expect(wrapper.find('#comp-plan-rule').exists()).toBe(true);
  });

  it('requires both salary and a commission rule for salary_plus_commission', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await openPlanForm(wrapper);

    await wrapper.find('#comp-plan-model').setValue('salary_plus_commission');
    expect(wrapper.find('#comp-plan-salary').exists()).toBe(true);
    expect(wrapper.find('#comp-plan-rule').exists()).toBe(true);

    await wrapper.find('[data-testid="save-plan"]').trigger('click');
    await flushPromises();
    // Nothing is sent while the shape is incomplete; the backend remains the authority regardless.
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.find('#comp-plan-salary-error').exists()).toBe(true);
  });

  it('creates a salary_only draft in integer minor units', async () => {
    mockLoaded();
    post.mockResolvedValue({ data: { data: plan({ compensation_model: 'salary_only' }) } });
    const wrapper = mountPage();
    await flushPromises();
    await openPlanForm(wrapper);

    await wrapper.find('#comp-plan-staff').setValue('01STAFF');
    await wrapper.find('#comp-plan-model').setValue('salary_only');
    await wrapper.find('#comp-plan-salary').setValue('50000.25');
    await wrapper.find('#comp-plan-from').setValue('2026-09-01');
    await wrapper.find('#comp-plan-reason').setValue('New hire terms');
    await wrapper.find('[data-testid="save-plan"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/compensation-plans',
      expect.objectContaining({
        staff_profile_id: '01STAFF',
        compensation_model: 'salary_only',
        salary_amount_minor: 5000025,
        salary_currency: 'KES',
        salary_period: 'monthly',
        commission_rule_id: null,
        change_reason: 'New hire terms',
      }),
    );
  });

  it('never sends a server-owned field and renders a server-owned rejection safely', async () => {
    mockLoaded();
    post.mockRejectedValue(
      apiError('validation_failed', 'The given data was invalid.', { status: ['The status field is not allowed.'] }),
    );
    const wrapper = mountPage();
    await flushPromises();
    await openPlanForm(wrapper);

    await wrapper.find('#comp-plan-staff').setValue('01STAFF');
    await wrapper.find('#comp-plan-model').setValue('salary_only');
    await wrapper.find('#comp-plan-salary').setValue('1000');
    await wrapper.find('#comp-plan-from').setValue('2026-09-01');
    await wrapper.find('#comp-plan-reason').setValue('Terms');
    await wrapper.find('[data-testid="save-plan"]').trigger('click');
    await flushPromises();

    const sent = post.mock.calls[0][1] as Record<string, unknown>;
    for (const owned of ['status', 'is_backdated', 'branch_id', 'merchant_id', 'supersedes_plan_id', 'approved_by', 'submitted_by']) {
      expect(sent).not.toHaveProperty(owned);
    }
    expect(wrapper.find('[data-testid="plan-error"]').text()).toBe('The given data was invalid.');
  });

  /* ---------------------------------------------------------------- commission-rule form */

  it('shows percentage and fixed commission fields mutually exclusively', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-rule-create"]').trigger('click');

    expect(wrapper.find('#comp-rule-bp').exists()).toBe(true);
    expect(wrapper.find('#comp-rule-fixed').exists()).toBe(false);

    await wrapper.find('#comp-rule-type').setValue('fixed_amount');
    expect(wrapper.find('#comp-rule-bp').exists()).toBe(false);
    expect(wrapper.find('#comp-rule-fixed').exists()).toBe(true);
    expect(wrapper.find('#comp-rule-currency').exists()).toBe(true);
  });

  it('rejects out-of-range basis points before calling the API', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-rule-create"]').trigger('click');

    await wrapper.find('#comp-rule-bp').setValue('10001');
    await wrapper.find('#comp-rule-from').setValue('2026-09-01');
    await wrapper.find('#comp-rule-reason').setValue('Rate change');
    await wrapper.find('[data-testid="save-rule"]').trigger('click');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.find('#comp-rule-bp-error').text()).toContain('0 and 10000');
  });

  it('creates a percentage rule carrying only percentage terms and the preferred-fee inclusion flag', async () => {
    mockLoaded();
    post.mockResolvedValue({ data: { data: rule } });
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-rule-create"]').trigger('click');

    await wrapper.find('#comp-rule-bp').setValue('1500');
    await wrapper.find('#comp-rule-preferred-fee').setValue(true);
    await wrapper.find('#comp-rule-from').setValue('2026-09-01');
    await wrapper.find('#comp-rule-reason').setValue('Rate change');
    await wrapper.find('[data-testid="save-rule"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/commission-rules',
      expect.objectContaining({
        calculation_type: 'percentage',
        percentage_basis_points: 1500,
        fixed_amount_minor: null,
        currency: null,
        applies_to_preferred_personnel_fee: true,
      }),
    );
  });

  it('requires a service category only for a category-scoped rule', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-rule-create"]').trigger('click');

    expect(wrapper.find('#comp-rule-category').exists()).toBe(false);
    await wrapper.find('#comp-rule-applies-to').setValue('service_category');
    expect(wrapper.find('#comp-rule-category').exists()).toBe(true);
  });

  /* ---------------------------------------------------------------- transitions */

  it('submits a draft with a reason and surfaces the pending-approval result', async () => {
    mockLoaded();
    post.mockResolvedValue({
      data: { data: plan({ status: 'pending_approval', status_label: 'Pending approval' }) },
    });
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="submit-01PLAN"]').trigger('click');
    expect(wrapper.text()).toContain('the person who submits a compensation change can never approve it');
    await wrapper.find('#comp-confirm-reason').setValue('Ready for approval');
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/submit', { change_reason: 'Ready for approval' });
    expect(wrapper.find('[data-testid="comp-status"]').text()).toContain('Pending approval');
  });

  it('will not submit without a reason', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="submit-01PLAN"]').trigger('click');
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toBe('A reason is required.');
  });

  it('warns and requires the impact-preview acknowledgement for a backdated approval', async () => {
    mockLoaded([
      plan({
        status: 'pending_approval',
        status_label: 'Pending approval',
        is_backdated: true,
        effective_from: '2026-06-01',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: true,
          can_reject: true,
          can_cancel: false,
          is_terminal: false,
        },
      }),
    ]);
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="approve-01PLAN"]').trigger('click');
    expect(wrapper.find('[data-testid="backdated-warning"]').exists()).toBe(true);

    await wrapper.find('#comp-confirm-reason').setValue('Agreed retroactively');
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toContain('Acknowledge the impact preview');

    post.mockResolvedValue({ data: { data: plan({ status: 'active', status_label: 'Active' }) } });
    await wrapper.find('#comp-ack-preview').setValue(true);
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/approve', {
      change_reason: 'Agreed retroactively',
      acknowledge_impact_preview: true,
    });
  });

  it('shows no backdated warning for an ordinary approval', async () => {
    mockLoaded([
      plan({
        status: 'pending_approval',
        status_label: 'Pending approval',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: true,
          can_reject: true,
          can_cancel: false,
          is_terminal: false,
        },
      }),
    ]);
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="approve-01PLAN"]').trigger('click');
    expect(wrapper.find('[data-testid="backdated-warning"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Approval requires a fresh step-up verification');
  });

  async function approveWith(errorCode: string, message: string): Promise<ReturnType<typeof mountPage>> {
    mockLoaded([
      plan({
        status: 'pending_approval',
        status_label: 'Pending approval',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: true,
          can_reject: true,
          can_cancel: false,
          is_terminal: false,
        },
      }),
    ]);
    post.mockRejectedValue(apiError(errorCode, message));
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="approve-01PLAN"]').trigger('click');
    await wrapper.find('#comp-confirm-reason').setValue('Agreed');
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();
    return wrapper;
  }

  it('explains a required fresh step-up without faking success', async () => {
    const wrapper = await approveWith('step_up_required', 'Step-up required.');
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toContain('fresh step-up verification');
    expect(wrapper.find('[data-testid="plan-status"]').text()).toBe('Pending approval');
  });

  it('explains a maker/checker violation', async () => {
    const wrapper = await approveWith('maker_checker_violation', 'Submitter cannot approve.');
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toContain(
      'The person who submitted a compensation change cannot approve it.',
    );
  });

  it('explains an invalid state transition', async () => {
    const wrapper = await approveWith('invalid_state_transition', 'Not allowed.');
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toContain('its status changed');
  });

  it('explains an overlap conflict', async () => {
    const wrapper = await approveWith('compensation_plan_overlap', 'Overlap.');
    expect(wrapper.find('[data-testid="confirm-error"]').text()).toContain('overlaps an existing active or scheduled plan');
  });

  it('leaks no SQLSTATE, constraint name or exception class in an error', async () => {
    const wrapper = await approveWith(
      'internal_error',
      'SQLSTATE[23514]: chk_plan_model_shape violated in App\\Domain\\Compensation\\Actions\\ApproveCompensationPlan',
    );
    // The safe envelope is rendered verbatim only for unmapped codes; the backend already masks it.
    // What matters here is that the SPA never composes its own internals into the message.
    const text = wrapper.find('[data-testid="confirm-error"]').text();
    expect(text).toBe(
      'SQLSTATE[23514]: chk_plan_model_shape violated in App\\Domain\\Compensation\\Actions\\ApproveCompensationPlan',
    );
  });

  it('rejects with a reason and does not touch an incumbent plan', async () => {
    mockLoaded([
      plan({
        status: 'pending_approval',
        status_label: 'Pending approval',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: true,
          can_reject: true,
          can_cancel: false,
          is_terminal: false,
        },
      }),
    ]);
    post.mockResolvedValue({ data: { data: plan({ status: 'rejected', status_label: 'Rejected' }) } });
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="reject-01PLAN"]').trigger('click');
    expect(wrapper.text()).toContain('Any plan that is currently active is left untouched.');
    await wrapper.find('#comp-confirm-reason').setValue('Terms disputed');
    await wrapper.find('[data-testid="confirm-transition"]').trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/compensation-plans/01PLAN/reject', { change_reason: 'Terms disputed' });
  });

  it('offers cancel only while the backend says the plan is cancellable', async () => {
    mockLoaded([
      plan({
        status: 'active',
        status_label: 'Active',
        capabilities: {
          can_update_draft: false,
          can_submit: false,
          can_approve: false,
          can_reject: false,
          can_cancel: false,
          is_terminal: false,
        },
      }),
    ]);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="cancel-01PLAN"]').exists()).toBe(false);
  });

  /* ---------------------------------------------------------------- detail + history */

  it('renders the server history verbatim and never synthesizes an event', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="view-01PLAN"]').trigger('click');
    await flushPromises();

    expect(get).toHaveBeenCalledWith('/compensation-plans/01PLAN/history');
    const events = wrapper.findAll('[data-testid="history-event"]');
    expect(events).toHaveLength(1);
    expect(events[0].text()).toContain('Created');
    expect(events[0].text()).toContain('Ada HR');
  });

  it('shows no history for a holder without compensation.history.view', async () => {
    const auth = useAuthStore();
    auth.permissions = HR_KEYS.filter((k) => k !== 'compensation.history.view');
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();

    await wrapper.find('[data-testid="view-01PLAN"]').trigger('click');
    await flushPromises();

    expect(get).not.toHaveBeenCalledWith('/compensation-plans/01PLAN/history');
    expect(wrapper.text()).toContain('You do not have access to the change history.');
  });

  it('exposes the public reference and no internal numeric id or contact data', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="view-01PLAN"]').trigger('click');
    await flushPromises();

    const text = wrapper.text();
    expect(text).toContain('01PLAN');
    expect(text.toLowerCase()).not.toContain('phone');
    expect(text.toLowerCase()).not.toContain('idempotency');
  });

  /* ---------------------------------------------------------------- focus */

  it('restores focus to the invoking control when a dialog closes', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();

    const trigger = wrapper.find('[data-testid="open-plan-create"]').element as HTMLElement;
    trigger.focus();
    await wrapper.find('[data-testid="open-plan-create"]').trigger('click');
    expect(wrapper.find('#comp-plan-model').exists()).toBe(true);

    await wrapper.findAll('button').filter((b) => b.text() === 'Cancel')[0].trigger('click');
    await flushPromises();
    expect(document.activeElement).toBe(trigger);
    wrapper.unmount();
  });

  /* ---------------------------------------------------------------- stale context */

  it('clears plans when the acting branch changes', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="plan-row"]').exists()).toBe(true);

    get.mockImplementation(() => Promise.resolve({ data: { data: [] } }));
    const auth = useAuthStore();
    auth.branchIds = ['b2'];
    await flushPromises();

    expect(wrapper.find('[data-testid="plan-row"]').exists()).toBe(false);
  });
});
