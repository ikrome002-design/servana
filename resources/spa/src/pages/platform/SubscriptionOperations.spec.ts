import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const put = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
    put: (...a: unknown[]) => put(...a),
  },
}));

import SubscriptionOperations from '@/pages/platform/SubscriptionOperations.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.merchant.view';

const subscription = {
  id: '01JSUB00000000000000000001',
  merchant: { id: '01JM00000000000000000001', name: 'Glow Studio', status: 'active', billing_status: 'active' },
  plan: { id: '01JP00000000000000000001', key: 'growth', name: 'Growth' },
  status: 'active',
  billing_interval: 'monthly',
  trial_started_at: null,
  trial_ends_at: null,
  trial_days_snapshot: 14,
  current_period_start: '2026-08-01T00:00:00Z',
  current_period_end: '2026-09-01T00:00:00Z',
  cancelled_at: null,
  expired_at: null,
  scheduled_plan_change: null,
  current_state: { status: 'active', authorization_authority: 'MerchantPolicy::viewGovernance', explanation: 'Paid through the current period.' },
};

function respondOk(): void {
  get.mockImplementation((url: string) => {
    if (url === '/platform/subscription-operations/summary') {
      return Promise.resolve({
        data: {
          data: {
            as_of: '2026-08-06T00:00:00Z',
            subscriptions_by_status: {},
            invoices_by_status: {},
            cohorts: {},
            funnel: {},
            totals: { subscriptions: 12, invoices: 30, open_invoice_balance_minor: 450000 },
          },
          meta: {
            definitions: {
              subscriptions_by_status: 'Count of merchant subscriptions grouped by lifecycle status.',
              open_invoice_balance_minor: 'Sum of unpaid balances on issued invoices, in minor units.',
            },
            time_range: 'As of the instant shown.',
            authorization_authority: 'platform.merchant.view',
          },
        },
      });
    }
    if (url === '/platform/subscriptions') return Promise.resolve({ data: { data: [subscription] } });
    if (url === '/platform/subscription-invoices') return Promise.resolve({ data: { data: [] } });
    if (url === '/platform/billing-credits') return Promise.resolve({ data: { data: [] } });
    if (url === '/platform/subscription-escalations') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({ data: { data: [] } });
  });
}

async function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  const wrapper = mount(SubscriptionOperations, { global: { stubs: { Teleport: true } } });
  await flushPromises();
  return wrapper;
}

describe('SubscriptionOperations.vue — contract page §5.4.13', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
    put.mockReset();
    respondOk();
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Subscription operations');
  });

  it('reads the shipped subscription-operations endpoints', async () => {
    await mountWith([VIEW]);
    const called = get.mock.calls.map((c) => c[0] as string);
    expect(called).toContain('/platform/subscription-operations/summary');
    expect(called).toContain('/platform/subscriptions');
  });

  it('renders the permission boundary and issues no request without the key', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="subscriptions-summary"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  /**
   * The decisive property of this page. Subscription operations is monitoring ONLY: the delivered
   * backend is seven GET operations. A mutation control here would advertise a capability the
   * platform does not have — and Servana never records a subscription payment by hand, because
   * Wallet by Citrus owns money-movement truth.
   */
  it('renders no mutation control of any kind', async () => {
    const wrapper = await mountWith([VIEW]);
    const text = wrapper.text().toLowerCase();
    for (const forbidden of [
      'record payment',
      'mark paid',
      'mark as paid',
      'edit invoice',
      'override',
      'create credit',
      'query provider',
      'retry payment',
    ]) {
      expect(text, `"${forbidden}" must not appear on a read-only screen`).not.toContain(forbidden);
    }
  });

  it('never issues a write request while the page is used', async () => {
    const wrapper = await mountWith([VIEW]);
    await wrapper.find('[data-testid="subs-tab-invoices"]').trigger('click');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(patch).not.toHaveBeenCalled();
    expect(put).not.toHaveBeenCalled();
  });

  it('switches tabs through the API rather than filtering a single cached list', async () => {
    const wrapper = await mountWith([VIEW]);
    get.mockClear();

    await wrapper.find('[data-testid="subs-tab-escalations"]').trigger('click');
    await flushPromises();

    expect(get.mock.calls.map((c) => c[0] as string)).toContain('/platform/subscription-escalations');
  });

  it('exposes the definition of every headline figure', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.text()).toContain('How each figure is defined');
    expect(wrapper.text()).toContain('Sum of unpaid balances on issued invoices');
  });

  it('explains why a subscription is in its current state', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.text()).toContain('Paid through the current period.');
  });

  it('records a last-refreshed time', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="subscriptions-last-refreshed"]').exists()).toBe(true);
  });

  it('says plainly that billing credits are invoice lines, not a ledger', async () => {
    const wrapper = await mountWith([VIEW]);
    await wrapper.find('[data-testid="subs-tab-credits"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Servana holds no credit ledger');
  });

  it('surfaces a retryable error state', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="subscriptions-retry"]').exists()).toBe(true);
  });

  it('marks the active tab for assistive technology', async () => {
    const wrapper = await mountWith([VIEW]);
    const active = wrapper.find('[data-testid="subs-tab-subscriptions"]');
    expect(active.attributes('aria-selected')).toBe('true');
    expect(active.attributes('tabindex')).toBe('0');
  });
});
