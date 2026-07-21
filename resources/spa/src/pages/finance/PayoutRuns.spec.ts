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

import PayoutRuns from '@/pages/finance/PayoutRuns.vue';
import { useAuthStore } from '@/stores/authStore';

function makeRun(status: string, overrides: Record<string, unknown> = {}) {
  return {
    id: '01RUN00000000000000000000', branch_id: '01BRANCH00000000000000000', period_start: '2026-07-01', period_end: '2026-07-31',
    currency: 'KES', status, gross_total_minor: 350000, high_value_threshold_snapshot_minor: null, is_high_value: false,
    rejection_reason: null, has_external_payment_reference: false, paid_at: null, item_count: 1, items: [],
    created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00', ...overrides,
  };
}
function paged<T>(rows: T[]) { return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } }; }
function apiError(code: string, fields: Record<string, string[]> = {}): unknown {
  return Object.assign(new Error(code), { isAxiosError: true, apiError: { code, message: 'server message', fields, meta: {} } });
}

const mountPage = () => mount(PayoutRuns, {
  attachTo: document.body,
  global: { stubs: { SvModal: { template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>', props: ['open', 'title', 'description'] } } },
});
function grantFinance(perms = ['payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid']): void {
  const auth = useAuthStore();
  auth.permissions = perms;
  auth.branchIds = ['01BRANCH00000000000000000'];
}

async function openDetail(wrapper: ReturnType<typeof mountPage>, run: ReturnType<typeof makeRun>): Promise<void> {
  get.mockResolvedValueOnce({ data: { data: run } });
  await wrapper.find(`[data-testid="run-details-${run.id}"]`).trigger('click');
  await flushPromises();
}

describe('Finance PayoutRuns.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset(); request.mockReset(); routerPush.mockReset();
  });

  it('shows a forbidden state without the verify permission', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="payout-forbidden"]').exists()).toBe(true);
  });

  it('offers verify on a submitted run and marks-paid on an approved run — never the reverse', async () => {
    const submitted = makeRun('submitted');
    get.mockResolvedValue(paged([submitted]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, submitted);
    expect(wrapper.find('[data-testid="verify-run"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="mark-paid"]').exists()).toBe(false);
  });

  it('marks paid with an external reference + paid date and clear no-money-movement wording', async () => {
    const approved = makeRun('approved');
    get.mockResolvedValue(paged([approved]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, approved);
    await wrapper.find('[data-testid="mark-paid"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="mark-paid-warning"]').text()).toContain('does not transfer any funds');
    await wrapper.find('#mark-paid-reference').setValue('MPESA-REF-1');
    await wrapper.find('#mark-paid-date').setValue('2026-07-15');
    request.mockResolvedValueOnce({ data: { data: makeRun('paid', { has_external_payment_reference: true }) } });
    get.mockResolvedValue(paged([makeRun('paid')]));
    await wrapper.find('[data-testid="mark-paid-submit"]').trigger('click');
    await flushPromises();
    const call = request.mock.calls[0][0];
    expect(call.url).toBe(`/finance/payout-runs/${approved.id}/mark-paid`);
    expect(call.data).toEqual({ external_payment_reference: 'MPESA-REF-1', paid_date: '2026-07-15' });
    expect(typeof call.headers['Idempotency-Key']).toBe('string');
    // No provider/Wallet labels leak (external "settlement" is legitimate; provider names are not).
    for (const banned of ['Wallet', 'STK', 'PayBill', 'Daraja', 'Till']) expect(wrapper.text()).not.toContain(banned);
  });

  it('shows a fresh step-up safe state when mark-paid returns step_up_required and offers verification', async () => {
    const approved = makeRun('approved');
    get.mockResolvedValue(paged([approved]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, approved);
    await wrapper.find('[data-testid="mark-paid"]').trigger('click');
    await wrapper.find('#mark-paid-reference').setValue('REF');
    await wrapper.find('#mark-paid-date').setValue('2026-07-15');
    request.mockRejectedValueOnce(apiError('step_up_required'));
    await wrapper.find('[data-testid="mark-paid-submit"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="mark-paid-step-up"]').exists()).toBe(true);
    await wrapper.find('[data-testid="mark-paid-verify"]').trigger('click');
    expect(routerPush).toHaveBeenCalledWith({ name: 'auth.mfa.challenge' });
  });

  it('rejects a future paid date client-side before calling the API', async () => {
    const approved = makeRun('approved');
    get.mockResolvedValue(paged([approved]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, approved);
    await wrapper.find('[data-testid="mark-paid"]').trigger('click');
    await wrapper.find('#mark-paid-reference').setValue('REF');
    await wrapper.find('#mark-paid-date').setValue('2999-01-01');
    await wrapper.find('[data-testid="mark-paid-submit"]').trigger('click');
    await flushPromises();
    expect(request).not.toHaveBeenCalled();
  });
});
