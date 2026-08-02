import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import PreferredFeeRulesSection from '@/pages/platform/billing/PreferredFeeRulesSection.vue';
import { useAuthStore } from '@/stores/authStore';

const activeRule = {
  id: '01FEE',
  calculation_type: 'fixed_amount',
  fixed_amount_minor: 5000,
  percentage_basis_points: null,
  currency: 'KES',
  calculation_basis: 'service_item_net_amount',
  scope: 'platform_default',
  service_id: null,
  effective_from: '2026-07-10',
  effective_to: null,
  status: 'active',
  approved_at: '2026-07-10T00:00:00+00:00',
  change_reason: 'launch',
};

const stubs = { SvDialog: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title', 'description'] } };

function mountSection(permissions: string[] = ['platform.preferred_personnel_fee.manage']) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(PreferredFeeRulesSection, { global: { stubs } });
}

describe('PreferredFeeRulesSection.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    get.mockResolvedValue({ data: { data: [activeRule] } });
  });

  it('shows an active rule as read-only (no in-place edit; supersede offered)', async () => {
    const wrapper = mountSection();
    await flushPromises();
    expect(wrapper.text()).toContain('Active terms are read-only');
    expect(wrapper.text()).toContain('Supersede');
  });

  it('hides all management controls when the manage permission is absent', async () => {
    const wrapper = mountSection([]);
    await flushPromises();
    // No create/approve/supersede/cancel BUTTON renders (text like "Superseded" in the
    // status filter is not a control). Assert on actual buttons.
    const buttonLabels = wrapper.findAll('button').map((b) => b.text());
    expect(buttonLabels).not.toContain('New draft rule');
    expect(buttonLabels).not.toContain('Supersede');
    expect(buttonLabels).not.toContain('Approve');
    expect(buttonLabels).not.toContain('Cancel');
  });

  it('makes fixed and percentage inputs mutually exclusive', async () => {
    const wrapper = mountSection();
    await flushPromises();
    await wrapper.get('button').trigger('click'); // open "New draft rule"
    await flushPromises();

    // Default type is fixed_amount → fixed amount + currency inputs, no basis-points input.
    expect(wrapper.find('#fee-fixed-amount').exists()).toBe(true);
    expect(wrapper.find('#fee-currency').exists()).toBe(true);
    expect(wrapper.find('#fee-basis-points').exists()).toBe(false);

    // Switch to percentage → basis-points input, no fixed amount/currency inputs.
    await wrapper.get('#fee-calc-type').setValue('percentage');
    expect(wrapper.find('#fee-basis-points').exists()).toBe(true);
    expect(wrapper.find('#fee-fixed-amount').exists()).toBe(false);
    expect(wrapper.find('#fee-currency').exists()).toBe(false);
  });

  it('requires a service only for service scope', async () => {
    const wrapper = mountSection();
    await flushPromises();
    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect(wrapper.find('#fee-service').exists()).toBe(false);
    await wrapper.get('#fee-scope').setValue('service');
    expect(wrapper.find('#fee-service').exists()).toBe(true);
  });

  it('renders an explicit overlap message on a 409 conflict', async () => {
    const wrapper = mountSection();
    await flushPromises();
    await wrapper.get('button').trigger('click');
    await flushPromises();

    post.mockRejectedValueOnce({
      isAxiosError: true,
      apiError: { code: 'invalid_state_transition', message: 'conflict', fields: {}, meta: {} },
      response: { status: 409 },
    });
    // Minimal valid-looking fixed rule.
    await wrapper.get('#fee-fixed-amount').setValue('50');
    await wrapper.get('#fee-from').setValue('2026-08-01');
    await wrapper.get('#fee-reason').setValue('launch default');
    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();

    expect(wrapper.text()).toContain('overlaps an existing active or scheduled rule');
  });
});
