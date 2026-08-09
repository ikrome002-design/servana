import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

const get = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a) },
}));

import PlatformGetStarted from '@/pages/platform/PlatformGetStarted.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.billing_settings.view';
const Blank = { template: '<div />' };

let router: Router;

interface Sources {
  billingMode?: string;
  effectiveFrom?: string | null;
  plans?: unknown[];
  preferredFeeTotal?: number;
  smsCurrent?: unknown;
  registrations?: number;
  mfaEnrolled?: boolean;
  mfaConfirmed?: boolean;
}

/** A fully-configured platform, overridable per case. */
function respond(sources: Sources = {}): void {
  const {
    billingMode = 'fixed_amount',
    effectiveFrom = '2026-07-01T00:00:00Z',
    plans = [{
      id: '01JPLAN0000000000000000001',
      key: 'growth',
      name: 'Growth',
      status: 'active',
      prices: [{ id: '01JPRICE000000000000000001', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' }],
      entitlements: [{ id: '01JENT00000000000000000001', key: 'branches.max', value: '3' }],
    }],
    preferredFeeTotal = 1,
    smsCurrent = { id: '01JSMS00000000000000000001', unit_cost_minor: 150 },
    registrations = 12,
    mfaEnrolled = true,
    mfaConfirmed = true,
  } = sources;

  get.mockImplementation((url: string) => {
    if (url === '/platform/billing-settings') {
      return Promise.resolve({ data: { data: { id: '01JSET00000000000000000001', billing_mode: billingMode, default_trial_days: 14, grace_days: 7, currency: 'KES', settings: {}, effective_from: effectiveFrom } } });
    }
    if (url === '/platform/plans') return Promise.resolve({ data: { data: plans } });
    if (url === '/platform/preferred-personnel-fee-rules') return Promise.resolve({ data: { data: [], meta: { total: preferredFeeTotal } } });
    if (url === '/platform/sms-billing-settings') return Promise.resolve({ data: { data: { current: smsCurrent, next: null, currency: 'KES' } } });
    if (url === '/platform/registration-monitor') return Promise.resolve({ data: { data: [], meta: { total: registrations } } });
    if (url === '/auth/mfa') return Promise.resolve({ data: { data: { mfa: { enrolled: mfaEnrolled, confirmed: mfaConfirmed } } } });
    return Promise.reject(new Error(`unexpected ${url}`));
  });
}

async function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  auth.user = {
    id: '01JUSER0000000000000000001',
    email: 'owner@citruslabs.co.ke',
    name: 'Owner',
    status: 'active',
    email_verified_at: null,
    is_platform_staff: true,
    theme_preference: null,
    resolved_theme: 'light',
  };
  const wrapper = mount(PlatformGetStarted, { global: { plugins: [router] } });
  await flushPromises();
  return wrapper;
}

const stepState = (wrapper: ReturnType<typeof mount>, id: string): string =>
  wrapper.find(`[data-testid="get-started-step-${id}"]`).text();

describe('PlatformGetStarted.vue — contract page §5.4.2', () => {
  beforeEach(async () => {
    setActivePinia(createPinia());
    localStorage.clear();
    get.mockReset();
    respond();

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/get-started', name: 'platform.get-started', component: Blank },
        { path: '/billing/settings', name: 'platform.billing-settings', component: Blank },
        { path: '/billing/plans', name: 'platform.billing-plans', component: Blank },
        { path: '/billing/preferred-personnel-fees', name: 'platform.billing-preferred-personnel-fees', component: Blank },
        { path: '/merchants/registrations', name: 'platform.merchant-registrations', component: Blank },
        { path: '/account', name: 'platform.account', component: Blank },
      ],
    });
    router.push('/get-started');
    await router.isReady();
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Get started');
  });

  it('renders the seven contract steps in dependency order', async () => {
    const wrapper = await mountWith([VIEW]);
    const steps = wrapper.findAll('[data-testid^="get-started-step-"]');
    expect(steps).toHaveLength(7);

    const labels = steps.map((s) => s.text().split('\n')[0].trim());
    expect(labels[0]).toContain('1. Configure the active billing mode');
    expect(labels[1]).toContain('2. Create and verify plan entitlements and effective prices');
    expect(labels[2]).toContain('3. Configure trial, grace, overdue and suspension thresholds');
    expect(labels[3]).toContain('4. Configure preferred-personnel fee and SMS billing rules');
    expect(labels[4]).toContain('5. Verify Wallet and Refer & Earn integration readiness');
    expect(labels[5]).toContain('6. Review merchant registration monitoring');
    expect(labels[6]).toContain('7. Enrol and verify multi-factor authentication');
  });

  it('reads every evidence source from the shipped endpoints and adds none', async () => {
    await mountWith([VIEW]);
    const called = get.mock.calls.map((c) => c[0] as string).sort();
    expect(called).toEqual([
      '/auth/mfa',
      '/platform/billing-settings',
      '/platform/plans',
      '/platform/preferred-personnel-fee-rules',
      '/platform/registration-monitor',
      '/platform/sms-billing-settings',
    ]);
  });

  it('renders the permission boundary and issues no request without the key', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="get-started-steps"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  /**
   * The decisive property. Completion comes from the platform, so a step goes back to incomplete
   * when the server evidence disappears — which a stored checkbox could never do.
   */
  it('derives completion from server evidence, not from stored state', async () => {
    const configured = await mountWith([VIEW]);
    expect(stepState(configured, 'configure-billing-mode')).toContain('Complete');

    setActivePinia(createPinia());
    respond({ billingMode: '' });
    const unconfigured = await mountWith([VIEW]);
    expect(stepState(unconfigured, 'configure-billing-mode')).toContain('Not started');
    expect(wrapperEvidence(unconfigured, 'configure-billing-mode')).toContain('No effective billing-settings version');
  });

  function wrapperEvidence(wrapper: ReturnType<typeof mount>, id: string): string {
    return wrapper.find(`[data-testid="get-started-evidence-${id}"]`).text();
  }

  it('states the evidence behind every step', async () => {
    const wrapper = await mountWith([VIEW]);
    for (const id of ['configure-billing-mode', 'configure-plans-entitlements', 'configure-free-period-grace', 'configure-preferred-personnel-fee', 'configure-mpesa', 'review-registration-monitoring', 'enroll-mfa']) {
      expect(wrapper.find(`[data-testid="get-started-evidence-${id}"]`).text().length, id).toBeGreaterThan(10);
    }
  });

  it('requires an active plan to carry BOTH a price and an entitlement', async () => {
    respond({ plans: [{ id: 'p1', key: 'growth', name: 'Growth', status: 'active', prices: [], entitlements: [{ id: 'e1' }] }] });
    const wrapper = await mountWith([VIEW]);
    expect(stepState(wrapper, 'configure-plans-entitlements')).toContain('Not started');
    expect(wrapperEvidence(wrapper, 'configure-plans-entitlements')).toContain('none is active with both');
  });

  it('warns that prices are not complete while no billing mode exists', async () => {
    respond({ billingMode: '' });
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="get-started-dependency-configure-plans-entitlements"]').text())
      .toContain('no active billing mode and interval exist');
  });

  it('needs both the preferred-personnel fee rule and the SMS rule', async () => {
    respond({ smsCurrent: null });
    const wrapper = await mountWith([VIEW]);
    expect(stepState(wrapper, 'configure-preferred-personnel-fee')).toContain('Not started');
    expect(wrapperEvidence(wrapper, 'configure-preferred-personnel-fee')).toContain('SMS billing rule in force: no');
  });

  /**
   * The gated step. It must never be completable — not by evidence, and not by hand.
   */
  it('shows the Wallet/R&E step as blocked, with no way to mark it complete', async () => {
    const wrapper = await mountWith([VIEW]);
    const step = wrapper.find('[data-testid="get-started-step-configure-mpesa"]');

    expect(step.text()).toContain('Blocked');
    expect(wrapper.find('[data-testid="get-started-gate-configure-mpesa"]').text()).toContain('external_gate_w');
    expect(wrapper.find('[data-testid="get-started-mark-configure-mpesa"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="get-started-open-configure-mpesa"]').exists()).toBe(false);
    // Never described as healthy or verified.
    expect(step.text().toLowerCase()).not.toContain('healthy');
  });

  it('excludes the blocked step from progress rather than counting it as failed', async () => {
    const wrapper = await mountWith([VIEW]);
    const progress = wrapper.find('[data-testid="get-started-progress"]').text();
    expect(progress).toContain('of 6 steps complete');
    expect(progress).toContain('1 step is blocked by an external dependency');
  });

  it('reflects real MFA state', async () => {
    respond({ mfaEnrolled: true, mfaConfirmed: false });
    const wrapper = await mountWith([VIEW]);
    expect(stepState(wrapper, 'enroll-mfa')).toContain('Not started');
    expect(wrapperEvidence(wrapper, 'enroll-mfa')).toContain('not yet confirmed');
  });

  it('lets the user mark only the review step, and persists it', async () => {
    const wrapper = await mountWith([VIEW]);

    // The review step is the ONLY one offering a manual control.
    expect(wrapper.findAll('[data-testid^="get-started-mark-"]')).toHaveLength(1);

    await wrapper.find('[data-testid="get-started-mark-review-registration-monitoring"]').trigger('click');
    await flushPromises();
    expect(stepState(wrapper, 'review-registration-monitoring')).toContain('Complete');

    // Resumable: a fresh mount reads the same persisted acknowledgement.
    setActivePinia(createPinia());
    const remounted = await mountWith([VIEW]);
    expect(stepState(remounted, 'review-registration-monitoring')).toContain('Complete');
  });

  it('deep-links each actionable step to its canonical destination', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="get-started-open-configure-billing-mode"]').attributes('href')).toBe('/billing/settings');
    expect(wrapper.find('[data-testid="get-started-open-configure-plans-entitlements"]').attributes('href')).toBe('/billing/plans');
    expect(wrapper.find('[data-testid="get-started-open-review-registration-monitoring"]').attributes('href')).toBe('/merchants/registrations');
    expect(wrapper.find('[data-testid="get-started-open-enroll-mfa"]').attributes('href')).toBe('/account');
  });

  it('offers dismissal only once every completable step is done, and reopens after', async () => {
    // Not everything is done yet → no dismiss control.
    respond({ mfaEnrolled: false });
    const partial = await mountWith([VIEW]);
    expect(partial.find('[data-testid="get-started-dismiss"]').exists()).toBe(false);

    // Everything done (including the manual review) → dismissal is offered.
    setActivePinia(createPinia());
    respond();
    const wrapper = await mountWith([VIEW]);
    await wrapper.find('[data-testid="get-started-mark-review-registration-monitoring"]').trigger('click');
    await flushPromises();

    await wrapper.find('[data-testid="get-started-dismiss"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="get-started-dismissed"]').exists()).toBe(true);

    await wrapper.find('[data-testid="get-started-reopen"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="get-started-steps"]').exists()).toBe(true);
  });

  it('keeps the other six steps when one evidence source is denied', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/platform/sms-billing-settings') return Promise.reject(new Error('forbidden'));
      if (url === '/platform/billing-settings') {
        return Promise.resolve({ data: { data: { billing_mode: 'fixed_amount', default_trial_days: 14, grace_days: 7, currency: 'KES', settings: {}, effective_from: '2026-07-01T00:00:00Z' } } });
      }
      if (url === '/platform/plans') return Promise.resolve({ data: { data: [] } });
      if (url === '/platform/preferred-personnel-fee-rules') return Promise.resolve({ data: { data: [], meta: { total: 1 } } });
      if (url === '/platform/registration-monitor') return Promise.resolve({ data: { data: [], meta: { total: 0 } } });
      if (url === '/auth/mfa') return Promise.resolve({ data: { data: { mfa: { enrolled: true, confirmed: true } } } });
      return Promise.reject(new Error('x'));
    });

    const wrapper = await mountWith([VIEW]);
    expect(wrapper.findAll('[data-testid^="get-started-step-"]')).toHaveLength(7);
    expect(stepState(wrapper, 'configure-billing-mode')).toContain('Complete');
    expect(stepState(wrapper, 'configure-preferred-personnel-fee')).toContain('Not started');
  });

  it('surfaces a retryable error only when every source fails', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="get-started-retry"]').exists()).toBe(true);
  });

  it('records when progress was last checked', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="get-started-last-refreshed"]').exists()).toBe(true);
  });
});
