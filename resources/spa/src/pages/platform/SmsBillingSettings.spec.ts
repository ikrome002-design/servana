import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) },
}));

import SmsBillingSettings from '@/pages/platform/SmsBillingSettings.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.billing_settings.view';
const UPDATE = 'platform.billing_settings.update';

const rule = (overrides: Record<string, unknown> = {}) => ({
  id: '01JRULE0000000000000000001',
  state: 'effective',
  unit_cost_minor: 150,
  currency: 'KES',
  tax_basis_points: null,
  usage_warning_threshold_units: null,
  usage_anomaly_threshold_basis_points: null,
  effective_from: '2026-07-22T00:00:00Z',
  reason: 'Genesis price',
  cancelled_at: null,
  cancellation_reason: null,
  ...overrides,
});

function respondOk(): void {
  get.mockImplementation((url: string) => {
    if (url === '/platform/sms-billing-settings') {
      return Promise.resolve({
        data: {
          data: {
            current: rule(),
            next: rule({ id: '01JRULE0000000000000000002', state: 'pending', unit_cost_minor: 175, effective_from: '2026-09-01T00:00:00Z' }),
            currency: 'KES',
            currency_authority: 'platform_billing_settings',
          },
        },
      });
    }
    if (url === '/platform/sms-billing-settings/versions') {
      return Promise.resolve({ data: { data: [rule()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } });
    }
    if (url === '/platform/sms-billing-charge-reconciliation') {
      return Promise.resolve({
        data: {
          data: {
            as_of: '2026-08-06T00:00:00Z',
            status_rollup: [],
            invoice_mapping: { linked_count: 4, linked_amount_minor: 600, unlinked_count: 1, unlinked_amount_minor: 150 },
            thresholds: { warning_state: 'within_threshold', anomaly_state: 'normal' },
            disclosed_tax: {},
          },
        },
      });
    }
    if (url === '/platform/sms-billing-usage') {
      return Promise.resolve({
        data: {
          data: [{ usage_month: '2026-08', merchant_id: '01JMERCHANT0000000000000001', currency: 'KES', message_count: 12, recipient_count: 9, billable_units: 14, amount_minor: 2100 }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        },
      });
    }
    return Promise.resolve({ data: { data: null } });
  });
}

async function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  // SvDialog renders through `<Teleport to="body">`, so its footer controls are not inside the
  // wrapper's own tree. Stubbing Teleport renders them inline, which is how the rest of the suite
  // drives dialogs (see Ui01Render001.spec.ts).
  const wrapper = mount(SmsBillingSettings, { global: { stubs: { Teleport: true } } });
  await flushPromises();
  return wrapper;
}

describe('SmsBillingSettings.vue — contract page §5.4.9', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    respondOk();
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('SMS billing settings');
  });

  it('reads the shipped SMS billing endpoints rather than inventing a shape', async () => {
    await mountWith([VIEW]);
    const called = get.mock.calls.map((c) => c[0] as string);
    expect(called).toContain('/platform/sms-billing-settings');
    expect(called).toContain('/platform/sms-billing-settings/versions');
    expect(called).toContain('/platform/sms-billing-charge-reconciliation');
    expect(called).toContain('/platform/sms-billing-usage');
  });

  it('shows the effective rule and the scheduled next rule as distinct facts', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="sms-current-rule"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sms-next-rule"]').exists()).toBe(true);
  });

  it('renders the permission boundary instead of the page when the key is absent', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="sms-current-rule"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  it('offers no mutation control to a user who may view but not update', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="sms-schedule-open"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="sms-cancel-scheduled"]').exists()).toBe(false);
  });

  it('offers the schedule and withdraw controls once the update key is held', async () => {
    const wrapper = await mountWith([VIEW, UPDATE]);
    expect(wrapper.find('[data-testid="sms-schedule-open"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sms-cancel-scheduled"]').exists()).toBe(true);
  });

  it('surfaces a retryable error state rather than an empty page', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="sms-retry"]').exists()).toBe(true);
  });

  it('records a last-refreshed time so a stale figure is identifiable', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="sms-last-refreshed"]').exists()).toBe(true);
  });

  it('distinguishes message count, recipient count, billable units and amount', async () => {
    const wrapper = await mountWith([VIEW]);
    const text = wrapper.text();
    expect(text).toContain('Messages');
    expect(text).toContain('Recipients');
    expect(text).toContain('Billable units');
  });

  it('shows the threshold and anomaly states from the server, not a client calculation', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="sms-threshold-state"]').text()).toBe('within_threshold');
    expect(wrapper.find('[data-testid="sms-anomaly-state"]').text()).toBe('normal');
  });

  it('states that a new price never re-prices recorded usage', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.text()).toContain('never re-prices SMS already recorded');
  });

  /**
   * The privacy boundary, asserted on DATA and CONTROLS rather than on words.
   *
   * A word-ban was tried first and was wrong: the page's own guarantee sentence legitimately
   * contains "phone number" and "recipient list", so the ban failed on the very copy that states
   * the boundary. What actually matters is that no recipient-level value is rendered and no
   * control exists that could produce one.
   */
  it('renders no recipient-level value and offers no contact export', async () => {
    const wrapper = await mountWith([VIEW, UPDATE]);
    const text = wrapper.text();

    // No Kenyan or international phone number is rendered anywhere.
    expect(text, 'a phone number must never be rendered').not.toMatch(/(\+254|07\d{2}|01\d{2})\s?\d{3}\s?\d{3}/);
    // No mail/tel affordance, and no export or download control.
    expect(wrapper.find('a[href^="tel:"]').exists()).toBe(false);
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false);
    expect(wrapper.find('a[download]').exists()).toBe(false);
    expect(text.toLowerCase()).not.toContain('export');
    expect(text.toLowerCase()).not.toContain('download');
  });

  it('states the privacy guarantee explicitly rather than leaving it implied', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.text()).toContain(
      'No recipient list, phone number or message body is available here',
    );
  });

  it('prevents a duplicate schedule submission while one is in flight', async () => {
    post.mockImplementation(() => new Promise(() => {}));
    const wrapper = await mountWith([VIEW, UPDATE]);

    await wrapper.find('[data-testid="sms-schedule-open"]').trigger('click');
    const submit = wrapper.find('[data-testid="sms-schedule-submit"]');
    await submit.trigger('click');
    await submit.trigger('click');
    await flushPromises();

    expect(post).toHaveBeenCalledTimes(1);
  });

  it('keeps the server error visible on the schedule dialog instead of closing it', async () => {
    const conflict = Object.assign(new Error('conflict'), {
      apiError: { message: 'Another rule already takes effect at that instant.' },
    });
    post.mockRejectedValue(conflict);

    const wrapper = await mountWith([VIEW, UPDATE]);
    await wrapper.find('[data-testid="sms-schedule-open"]').trigger('click');
    await wrapper.find('[data-testid="sms-schedule-submit"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="sms-schedule-error"]').text()).toContain('already takes effect');
  });
});
