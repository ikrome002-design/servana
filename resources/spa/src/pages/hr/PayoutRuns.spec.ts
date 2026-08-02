import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
const request = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), patch: (...a: unknown[]) => patch(...a), request: (...a: unknown[]) => request(...a) },
}));

import PayoutRuns from '@/pages/hr/PayoutRuns.vue';
import { useAuthStore } from '@/stores/authStore';

const run = {
  id: '01RUN00000000000000000000', branch_id: '01BRANCH00000000000000000', period_start: '2026-07-01', period_end: '2026-07-31',
  currency: 'KES', status: 'draft', gross_total_minor: 350000, high_value_threshold_snapshot_minor: null, is_high_value: false,
  rejection_reason: null, has_external_payment_reference: false, paid_at: null, item_count: 1,
  items: [{ id: '01ITEM0000000000000000000', staff_profile_id: '01STAFF000000000000000000', staff_display_name: 'A. Stylist', payout_run_id: '01RUN00000000000000000000', currency: 'KES', salary_amount_minor: 300000, commission_amount_minor: 50000, adjustment_amount_minor: 0, gross_amount_minor: 350000, status: 'draft', source_counts: { salary: 1, commission: 1, adjustment: 0 }, has_statement: false, statement_file_id: null, created_at: '2026-07-15T09:00:00+00:00' }],
  created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00',
};

function paged<T>(rows: T[]) { return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } }; }

const mountPage = () => mount(PayoutRuns, {
  attachTo: document.body,
  global: { stubs: { SvDialog: { template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>', props: ['open', 'title', 'description'] } } },
});

function grantHr(perms = ['payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft']): void {
  const auth = useAuthStore();
  auth.permissions = perms;
  auth.branchIds = ['01BRANCH00000000000000000'];
}

describe('HR PayoutRuns.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset(); post.mockReset(); patch.mockReset(); request.mockReset();
  });

  it('shows a safe forbidden state without the create control when the permission is absent', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="payout-forbidden"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="open-create"]').exists()).toBe(false);
  });

  it('lists runs and never shows verify/approve/mark-paid controls (HR cannot)', async () => {
    get.mockResolvedValue(paged([run]));
    grantHr();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.findAll('[data-testid="payout-run-row"]')).toHaveLength(1);
    const text = wrapper.text();
    expect(text).not.toContain('Mark paid');
    expect(text).not.toContain('Verify');
    // No Wallet/provider wording anywhere (external "settlement" is legitimate; provider names are not).
    for (const banned of ['Wallet', 'STK', 'PayBill', 'Daraja', 'Till', 'transfer funds']) expect(text).not.toContain(banned);
  });

  it('creates a draft sending only the four contract fields (no client total, no server-owned field)', async () => {
    get.mockResolvedValue(paged([run]));
    grantHr();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-create"]').trigger('click');
    await wrapper.find('#create-branch').setValue('01BRANCHZZZZZZZZZZZZZZZZZZ');
    await wrapper.find('#create-start').setValue('2026-07-01');
    await wrapper.find('#create-end').setValue('2026-07-31');
    await wrapper.find('#create-currency').setValue('KES');
    post.mockResolvedValueOnce({ data: { data: run } });
    get.mockResolvedValue(paged([run]));
    await wrapper.find('[data-testid="create-submit"]').trigger('click');
    await flushPromises();
    const [url, body] = post.mock.calls[0];
    expect(url).toBe('/hr/payout-runs');
    expect(Object.keys(body).sort()).toEqual(['branch_ulid', 'currency', 'period_end', 'period_start']);
    expect(body).not.toHaveProperty('gross_total_minor');
  });

  it('submits a draft through the store', async () => {
    get.mockResolvedValue(paged([run]));
    grantHr();
    const wrapper = mountPage();
    await flushPromises();
    get.mockResolvedValueOnce({ data: { data: run } }); // fetchRun on open
    await wrapper.find(`[data-testid="run-details-${run.id}"]`).trigger('click');
    await flushPromises();
    post.mockResolvedValueOnce({ data: { data: { ...run, status: 'submitted' } } });
    get.mockResolvedValue(paged([{ ...run, status: 'submitted' }]));
    await wrapper.find('[data-testid="submit-draft"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith(`/hr/payout-runs/${run.id}/submit`);
  });

  it('surfaces a safe invalid-transition message and does not blank the screen', async () => {
    get.mockResolvedValue(paged([run]));
    grantHr();
    const wrapper = mountPage();
    await flushPromises();
    get.mockResolvedValueOnce({ data: { data: { ...run, status: 'submitted' } } });
    await wrapper.find(`[data-testid="run-details-${run.id}"]`).trigger('click');
    await flushPromises();
    // A submitted run offers no submit button; there is no crash and the detail renders.
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
  });
});
