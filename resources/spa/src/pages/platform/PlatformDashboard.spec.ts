import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

const get = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import PlatformDashboard from '@/pages/platform/PlatformDashboard.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.merchant.view';
const Blank = { template: '<div />' };

let router: Router;

const payload = {
  data: {
    as_of: '2026-08-08T00:00:00Z',
    merchant_lifecycle: {
      availability: 'available',
      gate: null,
      as_of: '2026-08-08T00:00:00Z',
      total_merchants: 42,
      by_operational_status: { pending_setup: 2, active: 38, suspended: 1, deactivated: 1 },
      by_billing_status: { active: 30, overdue: 5, suspended_billing: 7 },
      billing_suspended: 7,
      active_branches: 57,
      definitions: {
        total_merchants: 'Every self-registered merchant record, in any lifecycle state.',
        active_branches: 'Branch records across every merchant.',
      },
      time_range: 'Point-in-time counts as of the instant shown.',
      drill_through: 'platform.merchants',
    },
    commercial: {
      availability: 'available',
      gate: null,
      as_of: '2026-08-08T00:00:00Z',
      invoices_by_status: { issued: 10, paid: 20 },
      issued_invoices: 30,
      open_invoice_balance_minor: 1234500,
      definitions: { open_invoice_balance_minor: 'Sum of balance_minor on open invoices, in integer minor units.' },
      time_range: 'Point-in-time totals as of the instant shown.',
      drill_through: 'platform.billing-subscriptions',
    },
    registration_monitoring: {
      availability: 'available',
      gate: null,
      as_of: '2026-08-08T00:00:00Z',
      registered_last_7_days: 4,
      registered_last_30_days: 11,
      awaiting_setup_completion: 3,
      definitions: { awaiting_setup_completion: 'Merchants still in pending_setup. Self-registration is the only creation path.' },
      time_range: 'Rolling 7-day and 30-day windows.',
      drill_through: 'platform.merchant-registrations',
    },
    governance_tasks: {
      availability: 'available',
      gate: null,
      merchants_suspended_for_billing: 7,
      merchants_suspended_by_policy: 1,
      overdue_invoices: 5,
      definitions: { merchants_suspended_by_policy: 'A billing payment never clears this.' },
      time_range: 'Current state.',
      drill_through: 'platform.merchants',
    },
    audit_alerts: {
      availability: 'available',
      gate: null,
      as_of: '2026-08-08T00:00:00Z',
      events_last_7_days: 128,
      by_severity: { info: 100, high: 28 },
      definitions: { events_last_7_days: 'Append-only audit events in the last 7 days.' },
      time_range: 'Rolling 7-day window.',
      drill_through: 'platform.audit',
    },
    integrations: {
      availability: 'disabled_by_gate',
      gate: 'external_gate_w',
      gate_statement: 'External Gate W (Wallet by Citrus collections readiness) is closed.',
      wallet: null,
      reconciliation_exceptions: null,
      refer_and_earn: null,
      definitions: {},
      time_range: null,
      drill_through: null,
    },
  },
  meta: {
    authorization_authority: 'platform.merchant.view via MerchantPolicy::viewGovernance',
    read_only: true,
    gate_policy: 'A gated section reports null values, never zero.',
  },
};

async function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  const wrapper = mount(PlatformDashboard, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

describe('PlatformDashboard.vue — contract page §5.4.1', () => {
  beforeEach(async () => {
    setActivePinia(createPinia());
    get.mockReset();
    get.mockResolvedValue({ data: payload });

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/dashboard', name: 'platform.dashboard', component: Blank },
        { path: '/merchants', name: 'platform.merchants', component: Blank },
        { path: '/merchants/registrations', name: 'platform.merchant-registrations', component: Blank },
        { path: '/billing/subscriptions', name: 'platform.billing-subscriptions', component: Blank },
        { path: '/audit', name: 'platform.audit', component: Blank },
      ],
    });
    router.push('/dashboard');
    await router.isReady();
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Platform dashboard');
  });

  it('reads the one aggregate endpoint and computes no total in the browser', async () => {
    const wrapper = await mountWith([VIEW]);

    expect(get).toHaveBeenCalledTimes(1);
    expect(get.mock.calls[0][0]).toBe('/platform/dashboard');
    // The rendered total is the SERVER's figure, not a length of anything the browser holds.
    expect(wrapper.find('[data-testid="dashboard-lifecycle"]').text()).toContain('42');
  });

  it('renders the permission boundary and issues no request without the key', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="dashboard-lifecycle"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  it('keeps operational and billing suspension as separate figures', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="dashboard-billing-suspended"]').text()).toBe('7');

    const tasks = wrapper.find('[data-testid="dashboard-tasks"]').text();
    expect(tasks).toContain('Suspended for billing');
    expect(tasks).toContain('Suspended by policy');
    expect(tasks).toContain('A billing payment never clears this.');
  });

  it('states the definition and time range the server supplied for each figure', async () => {
    const wrapper = await mountWith([VIEW]);
    const text = wrapper.text();
    expect(text).toContain('Every self-registered merchant record');
    expect(text).toContain('Sum of balance_minor on open invoices');
    expect(text).toContain('Point-in-time counts as of the instant shown.');
    expect(text).toContain('Rolling 7-day window.');
  });

  /**
   * The decisive property. A gated section must read as unavailable — not as zero, and not as
   * healthy, which on a governance screen is indistinguishable from good news.
   */
  it('shows the gated integration section as unavailable, with no figure and no health claim', async () => {
    const wrapper = await mountWith([VIEW]);
    const gated = wrapper.find('[data-testid="dashboard-integrations-gated"]');

    expect(gated.exists()).toBe(true);
    expect(gated.text()).toContain('External Gate W');

    const text = gated.text().toLowerCase();
    expect(text).not.toContain('healthy');
    expect(text).not.toMatch(/\b0\b/);
  });

  it('renders money in minor units through the shared money component', async () => {
    const wrapper = await mountWith([VIEW]);
    // 1,234,500 minor units — never printed as a raw integer.
    expect(wrapper.find('[data-testid="dashboard-commercial"]').text()).not.toContain('1234500');
  });

  it('offers a drill-through to the page that can act on each figure', async () => {
    const wrapper = await mountWith([VIEW]);
    const drill = wrapper.find('[data-testid="dashboard-drill-merchants"]');
    expect(drill.exists()).toBe(true);
    expect(drill.attributes('href')).toBe('/merchants');
  });

  it('records a last-refreshed time', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="dashboard-last-refreshed"]').exists()).toBe(true);
  });

  it('surfaces a retryable error state rather than an empty dashboard', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="dashboard-retry"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="dashboard-lifecycle"]').exists()).toBe(false);
  });

  it('renders no merchant-operation control', async () => {
    const wrapper = await mountWith([VIEW]);
    const text = wrapper.text().toLowerCase();
    for (const forbidden of ['create merchant', 'impersonate', 'record payment', 'mark paid']) {
      expect(text, `"${forbidden}" must not appear on the platform dashboard`).not.toContain(forbidden);
    }
  });
});
