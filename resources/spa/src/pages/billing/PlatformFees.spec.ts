import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import PlatformFees from '@/pages/billing/PlatformFees.vue';
import { useAuthStore } from '@/stores/authStore';

const entry = {
  id: '01ENTRY',
  merchant_id: '01MERCH',
  branch_id: '01BRANCH',
  source_invoice_id: '01INV',
  source_invoice_item_id: '01ITEM',
  entry_type: 'earned',
  status: 'invoiced',
  billing_mode: 'percentage_on_merchant_client_invoice',
  service_fee_tier: 'shared',
  fee_basis_type: 'merchant_client_invoice_service_subtotal',
  fee_basis_amount_minor: 500000,
  percentage_rate_basis_points: 250,
  shared_split_basis_points: 5000,
  gross_platform_fee_minor: 12500,
  client_shifted_amount_minor: 6250,
  merchant_absorbed_amount_minor: 6250,
  merchant_liability_minor: 12500,
  currency: 'KES',
  subscription_invoice_item_id: '01ROLL',
  billable_at: '2026-07-05T10:00:00+00:00',
};

const summaryRow = {
  currency: 'KES',
  entry_count: 2,
  gross_platform_fee_minor: 25000,
  client_shifted_amount_minor: 12500,
  merchant_absorbed_amount_minor: 12500,
};

const dispute = {
  id: '01DISP',
  platform_fee_ledger_entry_id: '01ENTRY',
  subscription_invoice_id: null,
  reason: 'Overcharged',
  status: 'open',
  assigned_reviewer: null,
  resolution_note: null,
  has_evidence: false,
  created_by: '01USER',
  resolved_by: null,
  resolved_at: null,
  created_at: '2026-08-01T09:00:00+00:00',
  capabilities: { reviewable: true, resolvable: false, rejectable: true },
};

const stubs = { SvModal: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title'] } };

function routeResponses(url: string) {
  if (url === '/platform-fees/summary') return { data: { data: [summaryRow] } };
  if (url === '/platform-fees') return { data: { data: [entry] } };
  if (url === '/platform-fee-disputes') return { data: { data: [dispute] } };
  return { data: { data: [] } };
}

function mountView(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  return mount(PlatformFees, { global: { stubs } });
}

describe('PlatformFees.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    get.mockImplementation((url: string) => Promise.resolve(routeResponses(url)));
  });

  it('renders server-authoritative summary + entry amounts (no browser recomputation)', async () => {
    const wrapper = mountView(['platform_fee.view']);
    await flushPromises();
    // Summary gross: 25000 minor → Ksh 250.00; entry liability 12500 → Ksh 125.00.
    expect(wrapper.text()).toContain('250.00');
    expect(wrapper.text()).toContain('125.00');
    expect(wrapper.text()).toContain('Ksh');
    // Canonical "Shared" tier label, never split_tier.
    expect(wrapper.text()).toContain('Shared');
    expect(wrapper.text()).not.toContain('split_tier');
  });

  it('read-only role (Branch/Audit: view only) shows no dispute-create or review controls', async () => {
    const wrapper = mountView(['platform_fee.view']);
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).not.toContain('Raise a dispute');
    expect(labels).not.toContain('Start review');
    expect(labels).not.toContain('Resolve');
  });

  it('merchant admin (view + dispute) can raise a dispute but cannot review', async () => {
    const wrapper = mountView(['platform_fee.view', 'platform_fee.dispute']);
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).toContain('Raise a dispute');
    expect(labels).not.toContain('Start review');
    expect(labels).not.toContain('Resolve');
  });

  it('finance (view + dispute.review) sees the review/reject controls on an open dispute', async () => {
    const wrapper = mountView(['platform_fee.view', 'platform_fee.dispute', 'platform_fee.dispute.review']);
    await flushPromises();
    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).toContain('Start review');
    expect(labels).toContain('Reject');
  });

  it('creates a dispute with a mandatory reason (ULID target)', async () => {
    post.mockResolvedValueOnce({ data: { data: dispute } });
    const wrapper = mountView(['platform_fee.view', 'platform_fee.dispute']);
    await flushPromises();
    // Open the create modal from the entry-row "Dispute" button.
    const disputeBtn = wrapper.findAll('button').find((b) => b.text() === 'Dispute');
    await disputeBtn?.trigger('click');
    await flushPromises();
    await wrapper.get('#pf-dispute-reason').setValue('Fee looks wrong');
    await wrapper.get('form').trigger('submit.prevent');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/platform-fee-disputes', expect.objectContaining({
      platform_fee_ledger_entry: '01ENTRY',
      reason: 'Fee looks wrong',
    }));
  });
});
