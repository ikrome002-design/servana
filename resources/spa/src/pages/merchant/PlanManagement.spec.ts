import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import PlanManagement from '@/pages/merchant/PlanManagement.vue';
import { useAuthStore } from '@/stores/authStore';

function sub(overrides: Record<string, unknown> = {}) {
  return {
    id: '01SUB', status: 'active', billing_status: 'active', billing_read_only: false,
    billing_interval: 'monthly', current_period_start: '2026-07-01', current_period_end: '2026-08-01',
    plan: { id: '01PLAN', key: 'starter', name: 'Starter', tier: null },
    price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
    scheduled_plan_change: null, can: { schedule_plan_change: true, download_invoice: true }, ...overrides,
  };
}

const plans = [
  { id: '01PLAN', key: 'starter', name: 'Starter', description: null, tier: null, is_current: true, effective_price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' } },
  { id: '02PLAN', key: 'growth', name: 'Growth', description: 'More', tier: null, is_current: false, effective_price: { id: '02PRICE', amount_minor: 900000, currency: 'KES', billing_interval: 'monthly' } },
];

function mockApi(subscription: Record<string, unknown>, scheduled: unknown = null) {
  get.mockImplementation((url: string) => {
    if (url === '/subscription') return Promise.resolve({ data: { data: subscription } });
    if (url === '/subscription/plans') return Promise.resolve({ data: { data: plans } });
    if (url === '/subscription/scheduled-plan-change') return Promise.resolve({ data: { data: scheduled } });
    return Promise.resolve({ data: { data: null } });
  });
}

describe('merchant/PlanManagement.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows the no-proration message and schedules a next-cycle change', async () => {
    mockApi(sub());
    post.mockResolvedValueOnce({ data: { data: { id: '01SC', status: 'scheduled', effective_at: '2026-08-01', target_plan: { id: '02PLAN', key: 'growth', name: 'Growth', tier: null }, target_price: { id: '02PRICE', amount_minor: 900000, currency: 'KES', billing_interval: 'monthly' } } } });
    useAuthStore().permissions = ['merchant.subscription.view', 'merchant.subscription.plan_change'];
    const wrapper = mount(PlanManagement);
    await flushPromises();
    expect(wrapper.text().toLowerCase()).toContain('no proration');
    await wrapper.get('[data-testid="schedule-growth"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/subscription/scheduled-plan-change', {
      subscription_plan_ulid: '02PLAN', subscription_plan_price_ulid: '02PRICE',
    });
  });

  it('removes mutation controls in billing read-only', async () => {
    mockApi(sub({ status: 'read_only_grace', billing_read_only: true }));
    useAuthStore().permissions = ['merchant.subscription.view', 'merchant.subscription.plan_change'];
    const wrapper = mount(PlanManagement);
    await flushPromises();
    expect(wrapper.find('[data-testid="schedule-growth"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('read-only mode');
  });

  it('surfaces a structured 409 when a change is already scheduled', async () => {
    mockApi(sub());
    const err = new axios.AxiosError('conflict');
    err.apiError = { code: 'scheduled_plan_change_exists', message: 'exists', fields: {}, meta: {} };
    post.mockRejectedValueOnce(err);
    useAuthStore().permissions = ['merchant.subscription.view', 'merchant.subscription.plan_change'];
    const wrapper = mount(PlanManagement);
    await flushPromises();
    await wrapper.get('[data-testid="schedule-growth"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('already scheduled');
  });

  it('cancels a pending scheduled change', async () => {
    mockApi(sub(), { id: '01SC', status: 'scheduled', effective_at: '2026-08-01', target_plan: { name: 'Growth' }, target_price: { amount_minor: 900000, currency: 'KES' } });
    post.mockResolvedValueOnce({ data: { data: { id: '01SC', status: 'cancelled' } } });
    useAuthStore().permissions = ['merchant.subscription.view', 'merchant.subscription.plan_change'];
    const wrapper = mount(PlanManagement);
    await flushPromises();
    await wrapper.get('[data-testid="cancel-scheduled-change"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/subscription/scheduled-plan-change/cancel');
  });
});
