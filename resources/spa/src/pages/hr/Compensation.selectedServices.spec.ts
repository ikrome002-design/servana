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
  'compensation.plan.view', 'compensation.plan.create', 'compensation.plan.update_draft',
  'compensation.plan.submit', 'compensation.plan.approve', 'compensation.plan.reject',
  'compensation.plan.cancel', 'compensation.history.view', 'staff.view',
];

const SVC_A = '01SVCAAA000000000000000000';
const SVC_B = '01SVCBBB000000000000000000';
const options = [{ ulid: SVC_A, name: 'Alpha cut' }, { ulid: SVC_B, name: 'Beta colour' }];

function selectedRule() {
  return {
    id: '01RULESEL', status: 'draft', status_label: 'Draft', calculation_type: 'percentage',
    calculation_basis: 'service_price', applies_to: 'selected_services',
    selected_service_ulids: [SVC_A], selected_services: [{ ulid: SVC_A, name: 'Alpha cut' }],
    percentage_basis_points: 1500, fixed_amount_minor: null, currency: null,
    applies_to_preferred_personnel_fee: false, effective_from: '2026-08-01', effective_to: null,
    notes: null, change_reason: 'Launch', is_editable: true, created_at: '2026-07-01T00:00:00+00:00', approved_at: null,
  };
}

function mockLoaded(rules: unknown[] = []): void {
  get.mockImplementation((url: string) => {
    if (url === '/staff') return Promise.resolve({ data: { data: [{ id: '01STAFF', display_name: 'Jane Doe' }] } });
    if (url === '/commission-rules') return Promise.resolve({ data: { data: rules } });
    if (url === '/commission-rule-service-options') return Promise.resolve({ data: { data: options } });
    if (url === '/compensation-plans') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({ data: { data: [] } });
  });
}

const mountPage = () =>
  mount(Compensation, {
    attachTo: document.body,
    global: {
      stubs: {
        SvModal: {
          template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>',
          props: ['open', 'title', 'description'],
        },
      },
    },
  });

async function openRuleForm(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('[data-testid="open-rule-create"]').trigger('click');
  await flushPromises();
}

async function chooseSelectedServices(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('#comp-rule-applies-to').setValue('selected_services');
  await flushPromises();
}

describe('Compensation.vue — selected-services multi-select (§9.1)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    const auth = useAuthStore();
    auth.permissions = [...HR_KEYS];
    auth.branchIds = ['b1'];
  });

  it('loads options from the compensation endpoint and never calls /services', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await openRuleForm(wrapper);
    await chooseSelectedServices(wrapper);
    expect(get.mock.calls.some((c) => c[0] === '/commission-rule-service-options')).toBe(true);
    expect(get.mock.calls.some((c) => c[0] === '/services')).toBe(false);
    // The multi-select renders one checkbox per branch service.
    expect(wrapper.find(`#svc-${SVC_A}`).exists()).toBe(true);
    expect(wrapper.find(`#svc-${SVC_B}`).exists()).toBe(true);
  });

  it('requires at least one service before saving a selected-services rule', async () => {
    mockLoaded();
    const wrapper = mountPage();
    await flushPromises();
    await openRuleForm(wrapper);
    await chooseSelectedServices(wrapper);
    await wrapper.find('#comp-rule-bp').setValue('1500');
    await wrapper.find('#comp-rule-from').setValue('2026-09-01');
    await wrapper.find('#comp-rule-reason').setValue('New rule');
    await wrapper.find('[data-testid="save-rule"]').trigger('submit');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.find('[data-testid="selected-services-error"]').exists()).toBe(true);
  });

  it('sends selected_service_ulids for a selected-services rule', async () => {
    mockLoaded();
    post.mockResolvedValueOnce({ data: { data: selectedRule() } });
    const wrapper = mountPage();
    await flushPromises();
    await openRuleForm(wrapper);
    await chooseSelectedServices(wrapper);
    await wrapper.find(`#svc-${SVC_A}`).setValue(true);
    await wrapper.find('#comp-rule-bp').setValue('1500');
    await wrapper.find('#comp-rule-from').setValue('2026-09-01');
    await wrapper.find('#comp-rule-reason').setValue('New rule');
    await wrapper.find('[data-testid="save-rule"]').trigger('submit');
    await flushPromises();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][1]).toMatchObject({ applies_to: 'selected_services', selected_service_ulids: [SVC_A] });
  });

  it('clears the stale selection when applies_to changes away from selected_services', async () => {
    mockLoaded();
    post.mockResolvedValueOnce({ data: { data: selectedRule() } });
    const wrapper = mountPage();
    await flushPromises();
    await openRuleForm(wrapper);
    await chooseSelectedServices(wrapper);
    await wrapper.find(`#svc-${SVC_A}`).setValue(true);
    // Switch to all_services; the membership control disappears and the submitted set is empty.
    await wrapper.find('#comp-rule-applies-to').setValue('all_services');
    await flushPromises();
    expect(wrapper.find('[data-testid="selected-services"]').exists()).toBe(false);
    await wrapper.find('#comp-rule-bp').setValue('1500');
    await wrapper.find('#comp-rule-from').setValue('2026-09-01');
    await wrapper.find('#comp-rule-reason').setValue('Now all');
    await wrapper.find('[data-testid="save-rule"]').trigger('submit');
    await flushPromises();
    expect(post.mock.calls[0][1].selected_service_ulids).toEqual([]);
  });

  it('hydrates the server-returned selection when editing a draft', async () => {
    mockLoaded([selectedRule()]);
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="edit-rule-01RULESEL"]').trigger('click');
    await flushPromises();
    // The previously-selected service is pre-checked from selected_service_ulids.
    const checkbox = wrapper.find(`#svc-${SVC_A}`).element as HTMLInputElement;
    expect(checkbox.checked).toBe(true);
    // And the removable summary chip shows its name.
    expect(wrapper.text()).toContain('Selected (1)');
  });

  it('shows a read-only selected-services line in the rule list', async () => {
    mockLoaded([selectedRule()]);
    const wrapper = mountPage();
    await flushPromises();
    const row = wrapper.find('[data-testid="rule-selected-services-01RULESEL"]');
    expect(row.exists()).toBe(true);
    expect(row.text()).toContain('Alpha cut');
  });

  it('surfaces an empty-options state without falling back', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/commission-rule-service-options') return Promise.resolve({ data: { data: [] } });
      if (url === '/commission-rules' || url === '/compensation-plans' || url === '/staff') return Promise.resolve({ data: { data: [] } });
      return Promise.resolve({ data: { data: [] } });
    });
    const wrapper = mountPage();
    await flushPromises();
    await openRuleForm(wrapper);
    await chooseSelectedServices(wrapper);
    expect(wrapper.find('[data-testid="no-service-options"]').exists()).toBe(true);
  });
});
