import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: vi.fn() },
}));

import SubscriptionDashboard from '@/pages/merchant/SubscriptionDashboard.vue';
import { useAuthStore } from '@/stores/authStore';

function sub(overrides: Record<string, unknown> = {}) {
  return {
    id: '01SUB', status: 'active', billing_status: 'active', billing_status_reason: null,
    billing_read_only: false, billing_interval: 'monthly',
    trial_started_at: '2026-06-01T00:00:00Z', trial_ends_at: '2026-06-15T00:00:00Z',
    current_period_start: '2026-07-01', current_period_end: '2026-08-01',
    plan: { id: '01PLAN', key: 'starter', name: 'Starter', tier: null },
    price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
    scheduled_plan_change: null,
    can: { schedule_plan_change: true, download_invoice: true },
    ...overrides,
  };
}

function mockApi(subscription: Record<string, unknown>, invoices: unknown[] = []) {
  get.mockImplementation((url: string) => {
    if (url === '/subscription') return Promise.resolve({ data: { data: subscription } });
    if (url === '/subscription-invoices') return Promise.resolve({ data: { data: invoices } });
    return Promise.resolve({ data: { data: null } });
  });
}

const stubs = { RouterLink: { template: '<a><slot /></a>' } };

describe('merchant/SubscriptionDashboard.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
  });

  it('shows subscription status and billing status as separate fields', async () => {
    mockApi(sub());
    useAuthStore().permissions = ['merchant.subscription.view'];
    const wrapper = mount(SubscriptionDashboard, { global: { stubs } });
    await flushPromises();
    expect(wrapper.get('[data-testid="subscription-status"]').text()).toBe('Active');
    expect(wrapper.get('[data-testid="billing-status"]').text()).toBe('Active');
    expect(wrapper.text()).toContain('Starter');
    expect(wrapper.text()).toContain('2026-08-01');
  });

  it('explains billing read-only mode when in read_only_grace', async () => {
    mockApi(sub({ status: 'read_only_grace', billing_status: 'read_only_grace', billing_read_only: true }));
    useAuthStore().permissions = ['merchant.subscription.view'];
    const wrapper = mount(SubscriptionDashboard, { global: { stubs } });
    await flushPromises();
    expect(wrapper.text()).toContain('Billing is in read-only mode');
  });

  it('summarises a pending scheduled change and the payment-reference-pending invoice', async () => {
    mockApi(
      sub({ scheduled_plan_change: { id: '01SC', status: 'scheduled', effective_at: '2026-08-01', target_plan: { name: 'Growth' }, target_price: { amount_minor: 900000, currency: 'KES' } } }),
      [{ id: '01INV', invoice_number: 'SUB-000001', total_minor: 500000, currency: 'KES', status: 'issued', payment_reference_pending: true }],
    );
    useAuthStore().permissions = ['merchant.subscription.view', 'merchant.subscription.invoice.view'];
    const wrapper = mount(SubscriptionDashboard, { global: { stubs } });
    await flushPromises();
    expect(wrapper.get('[data-testid="scheduled-change-summary"]').text()).toContain('Growth');
    expect(wrapper.get('[data-testid="latest-invoice"]').text()).toContain('Payment reference pending');
  });

  it('denies the surface without merchant.subscription.view', async () => {
    useAuthStore().permissions = [];
    const wrapper = mount(SubscriptionDashboard, { global: { stubs } });
    await flushPromises();
    expect(wrapper.text()).toContain('do not have access');
    expect(get).not.toHaveBeenCalled();
  });
});
