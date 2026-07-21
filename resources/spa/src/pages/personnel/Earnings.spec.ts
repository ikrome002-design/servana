import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) } }));

import Earnings from '@/pages/personnel/Earnings.vue';
import { useAuthStore } from '@/stores/authStore';

const overview = {
  tab_visibility: { model: 'salary_plus_commission', has_current_plan: true, conflicting: false, salary_tab: true, commission_tab: true },
  currencies: [{ currency: 'KES', salary_unpaid_minor: 300000, salary_paid_minor: 0, commission_unpaid_minor: 50000, commission_paid_minor: 0, adjustment_unpaid_minor: 0, adjustment_paid_minor: 0, unpaid_minor: 350000, paid_minor: 0, net_minor: 350000 }],
};
const paidItem = { id: '01ITEM0000000000000000000', staff_profile_id: null, staff_display_name: null, payout_run_id: null, currency: 'KES', salary_amount_minor: 0, commission_amount_minor: 50000, adjustment_amount_minor: 0, gross_amount_minor: 50000, status: 'paid', source_counts: { salary: 0, commission: 1, adjustment: 0 }, has_statement: false, statement_file_id: null, created_at: '2026-07-15T09:00:00+00:00' };
const query = { id: '01QUERY000000000000000000', staff_profile_id: '01STAFF000000000000000000', subject_type: 'commission_ledger', query_type: 'commission_disagreement', body: 'short by 500', status: 'open', assigned_role: 'finance', resolution_note: null, resolved_adjustment_id: null, responded_at: null, created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00' };

function paged<T>(rows: T[]) { return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } }; }

function mockLoaded(): void {
  get.mockImplementation((url: string) => {
    if (url === '/personnel/me/earnings') return Promise.resolve({ data: { data: overview } });
    if (url === '/personnel/me/compensation') return Promise.resolve({ data: { data: { has_current_plan: true, conflicting: false, compensation_model: 'salary_plus_commission' } } });
    if (url === '/personnel/me/payouts') return Promise.resolve(paged([paidItem]));
    if (url === '/personnel/me/earnings-queries') return Promise.resolve(paged([query]));
    if (url.startsWith('/personnel/me/earnings-queries/')) return Promise.resolve({ data: { data: query } });
    return Promise.resolve(paged([]));
  });
}
const mountPage = () => mount(Earnings, {
  attachTo: document.body,
  global: { stubs: { SvModal: { template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>', props: ['open', 'title', 'description'] } } },
});
function grantPersonnel(): void {
  const auth = useAuthStore();
  auth.permissions = ['personnel.my_earnings.view', 'personnel.my_compensation.view', 'personnel.my_payouts.view', 'personnel.my_statements.download', 'personnel.my_earnings_query.create'];
  auth.branchIds = ['01BRANCH00000000000000000'];
}

describe('Personnel Earnings.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset(); post.mockReset();
    vi.stubGlobal('open', vi.fn());
  });

  it('shows a forbidden state without the earnings permission', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="earnings-forbidden"]').exists()).toBe(true);
  });

  it('renders own per-currency earnings and both tabs, with no staff selector', async () => {
    mockLoaded();
    grantPersonnel();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.findAll('[data-testid="earnings-currency-card"]')).toHaveLength(1);
    expect(wrapper.text()).toContain('Salary (unpaid / paid)');
    expect(wrapper.text()).toContain('Commission (unpaid / paid)');
    // Own-scope: there is no staff-selection control on the screen.
    expect(wrapper.find('#staff_profile_ulid').exists()).toBe(false);
  });

  it('generates a statement for a paid item and opens the authorised download link', async () => {
    mockLoaded();
    grantPersonnel();
    const wrapper = mountPage();
    await flushPromises();
    post.mockResolvedValueOnce({ data: { data: { statement: { id: '01FILE000000000000000000', filename: 'statement.pdf', mime_type: 'application/pdf', size_bytes: 100, generated_at: '2026-07-16T00:00:00+00:00' }, download: { url: '/api/v1/files/01FILE000000000000000000/download?sig=x', expires_at: '2026-07-16T00:05:00+00:00' } } } });
    await wrapper.find(`[data-testid="statement-${paidItem.id}"]`).trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith(`/personnel/me/payout-items/${paidItem.id}/statement`);
    expect(window.open).toHaveBeenCalled();
    expect(wrapper.find(`[data-testid="statement-link-${paidItem.id}"]`).exists()).toBe(true);
  });

  it('raises an own-scope query sending only the contract fields', async () => {
    mockLoaded();
    grantPersonnel();
    const wrapper = mountPage();
    await flushPromises();
    await wrapper.find('[data-testid="open-query"]').trigger('click');
    await wrapper.find('#query-subject-ulid').setValue('01LEDGERZZZZZZZZZZZZZZZZZZ');
    await wrapper.find('#query-body').setValue('My commission looks short.');
    post.mockResolvedValueOnce({ data: { data: query } });
    get.mockResolvedValue(paged([query]));
    await wrapper.find('[data-testid="query-submit"]').trigger('click');
    await flushPromises();
    const [url, body] = post.mock.calls[0];
    expect(url).toBe('/personnel/me/earnings-queries');
    expect(Object.keys(body).sort()).toEqual(['body', 'query_type', 'subject_type', 'subject_ulid']);
    expect(body).not.toHaveProperty('staff_profile_ulid');
  });
});
