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

import PlatformFeeConfigSection from '@/pages/platform/billing/PlatformFeeConfigSection.vue';
import { useAuthStore } from '@/stores/authStore';

function config(overrides: Record<string, unknown> = {}) {
  return {
    id: '01CFG',
    billing_mode: 'percentage_on_merchant_client_invoice',
    percentage_basis_points: 250,
    fixed_component_minor: null,
    tier_behavior: 'shared',
    shared_split_basis_points: 5000,
    fee_basis_type: 'merchant_client_invoice_service_subtotal',
    currency: 'KES',
    effective_from: '2026-08-01',
    effective_to: null,
    status: 'active',
    approved_at: '2026-08-01T00:00:00+00:00',
    change_reason: 'Launch',
    capabilities: { editable: false, approvable: false, supersedable: true, cancellable: false },
    ...overrides,
  };
}

const stubs = { SvDialog: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title'] } };

function mountSection(permissions: string[] = ['platform.platform_fee.configure']) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(PlatformFeeConfigSection, { global: { stubs } });
}

describe('PlatformFeeConfigSection.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    get.mockResolvedValue({ data: { data: [config()] } });
  });

  it('renders the canonical "Shared" tier label, never the persisted split_tier value', async () => {
    const wrapper = mountSection();
    await flushPromises();
    expect(wrapper.text()).toContain('Shared');
    expect(wrapper.text()).not.toContain('split_tier');
  });

  it('offers supersede for an active (immutable) configuration but not an edit control', async () => {
    const wrapper = mountSection();
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).toContain('Supersede');
    expect(labels).not.toContain('Edit draft');
  });

  it('offers edit/approve/cancel for a draft configuration', async () => {
    get.mockResolvedValue({
      data: { data: [config({ status: 'draft', capabilities: { editable: true, approvable: true, supersedable: false, cancellable: true } })] },
    });
    const wrapper = mountSection();
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).toContain('Edit draft');
    expect(labels).toContain('Approve');
    expect(labels).toContain('Cancel');
  });

  it('hides every management control without the configure permission', async () => {
    const wrapper = mountSection([]);
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).not.toContain('New draft configuration');
    expect(labels).not.toContain('Supersede');
    expect(labels).not.toContain('Approve');
  });

  it('blocks submit and shows an error when a shared tier has no split (no API call)', async () => {
    const wrapper = mountSection();
    await flushPromises();
    await wrapper.get('button').trigger('click'); // "New draft configuration"
    await flushPromises();
    await wrapper.get('#pf-tier').setValue('shared');
    await wrapper.get('#pf-bps').setValue('250');
    await wrapper.get('#pf-eff-from').setValue('2026-09-01');
    await wrapper.get('#pf-reason').setValue('New split');
    // shared split intentionally left blank
    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('A shared split (basis points) is required');
  });

  it('rejects the validated-paid-amount basis outside the customer-centric tier', async () => {
    const wrapper = mountSection();
    await flushPromises();
    await wrapper.get('button').trigger('click');
    await flushPromises();
    await wrapper.get('#pf-tier').setValue('business_centric');
    await wrapper.get('#pf-basis').setValue('validated_paid_amount');
    await wrapper.get('#pf-eff-from').setValue('2026-09-01');
    await wrapper.get('#pf-reason').setValue('Bad basis');
    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('only available for the customer-centric tier');
  });
});
