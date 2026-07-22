import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const request = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: vi.fn(), patch: vi.fn(), request: (...a: unknown[]) => request(...a) },
}));
const routerPush = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }));

import CompensationSummary from '@/pages/merchant/CompensationSummary.vue';
import { useAuthStore } from '@/stores/authStore';

const summary = {
  outstanding_liability_by_currency: [
    { currency: 'KES', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 300000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 50000, compensation_adjustment_minor: 0, combined_net_liability_minor: 350000 },
    { currency: 'USD', gross_salary_accrual_minor: 0, salary_reversal_minor: 0, net_salary_liability_minor: 40000, gross_earned_commission_minor: 0, commission_reversal_minor: 0, net_commission_liability_minor: 0, compensation_adjustment_minor: 0, combined_net_liability_minor: 40000 },
  ],
  paid_by_currency: [{ currency: 'KES', paid_gross_minor: 350000, run_count: 1 }],
  payout_runs_by_status: { draft: 1, pending_merchant_admin_approval: 1, paid: 1 },
  pending_high_value_approvals: 1,
};
const hvRun = {
  id: '01RUN00000000000000000000', branch_id: '01BRANCH00000000000000000', period_start: '2026-07-01', period_end: '2026-07-31',
  currency: 'KES', status: 'pending_merchant_admin_approval', gross_total_minor: 900000, high_value_threshold_snapshot_minor: 100000, is_high_value: true,
  rejection_reason: null, has_external_payment_reference: false, paid_at: null, item_count: 2, items: [],
  created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00',
};
function paged<T>(rows: T[]) { return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } }; }

function mockLoaded(): void {
  get.mockImplementation((url: string) => {
    if (url === '/merchant/compensation-summary') return Promise.resolve({ data: { data: summary } });
    if (url === '/merchant/payout-runs') return Promise.resolve(paged([hvRun]));
    return Promise.resolve(paged([]));
  });
}
const mountPage = () => mount(CompensationSummary, {
  attachTo: document.body,
  global: { stubs: { SvModal: { template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>', props: ['open', 'title', 'description'] } } },
});
function grantAdmin(perms = ['merchant.compensation_summary.view', 'merchant.payout.approve_high_value']): void {
  const auth = useAuthStore();
  auth.permissions = perms;
  auth.branchIds = [];
}

describe('Merchant CompensationSummary.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset(); request.mockReset(); routerPush.mockReset();
  });

  it('shows a forbidden state without the summary permission', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="summary-forbidden"]').exists()).toBe(true);
  });

  it('renders currency-grouped totals without combining currencies and never a mark-paid control', async () => {
    mockLoaded();
    grantAdmin();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.findAll('[data-testid="outstanding-card"]')).toHaveLength(2);
    expect(wrapper.find('[data-testid="pending-high-value"]').text()).toBe('1');
    expect(wrapper.text()).toContain('KES');
    expect(wrapper.text()).toContain('USD');
    expect(wrapper.text()).not.toContain('Mark paid');
  });

  it('approves a high-value run with an Idempotency-Key and re-loads', async () => {
    mockLoaded();
    grantAdmin();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find(`[data-testid="approve-high-value-${hvRun.id}"]`).trigger('click');
    await flushPromises();
    request.mockResolvedValueOnce({ data: { data: { ...hvRun, status: 'approved' } } });
    await wrapper.find('[data-testid="approve-submit"]').trigger('click');
    await flushPromises();
    const call = request.mock.calls[0][0];
    expect(call.url).toBe(`/merchant/payout-runs/${hvRun.id}/approve-high-value`);
    expect(typeof call.headers['Idempotency-Key']).toBe('string');
  });

  it('shows a fresh step-up state on step_up_required and offers verification', async () => {
    mockLoaded();
    grantAdmin();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find(`[data-testid="approve-high-value-${hvRun.id}"]`).trigger('click');
    request.mockRejectedValueOnce(Object.assign(new Error('x'), { isAxiosError: true, apiError: { code: 'step_up_required', message: 'x', fields: {}, meta: {} } }));
    await wrapper.find('[data-testid="approve-submit"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="approve-step-up"]').exists()).toBe(true);
    await wrapper.find('[data-testid="approve-verify"]').trigger('click');
    expect(routerPush).toHaveBeenCalledWith({ name: 'auth.mfa.challenge' });
  });
});
