import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({ apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) } }));

import EarningsQueries from '@/pages/finance/EarningsQueries.vue';
import { useAuthStore } from '@/stores/authStore';

function makeQuery(status: string, overrides: Record<string, unknown> = {}) {
  return { id: '01QUERY000000000000000000', staff_profile_id: '01STAFF000000000000000000', subject_type: 'commission_ledger', query_type: 'commission_disagreement', body: 'short by 500', status, assigned_role: 'finance', resolution_note: null, resolved_adjustment_id: null, responded_at: null, created_at: '2026-07-15T09:00:00+00:00', updated_at: '2026-07-15T09:00:00+00:00', ...overrides };
}
function paged<T>(rows: T[]) { return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } }; }

const mountPage = () => mount(EarningsQueries, {
  attachTo: document.body,
  global: { stubs: { SvDialog: { template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>', props: ['open', 'title', 'description'] } } },
});
function grantFinance(): void {
  const auth = useAuthStore();
  auth.permissions = ['earnings_query.respond'];
  auth.branchIds = ['01BRANCH00000000000000000'];
}
async function openDetail(wrapper: ReturnType<typeof mountPage>, query: ReturnType<typeof makeQuery>): Promise<void> {
  get.mockResolvedValueOnce({ data: { data: query } });
  await wrapper.find(`[data-testid="query-open-${query.id}"]`).trigger('click');
  await flushPromises();
}

describe('Finance EarningsQueries.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset(); post.mockReset();
  });

  it('shows a forbidden state without the respond permission', async () => {
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="query-forbidden"]').exists()).toBe(true);
  });

  it('lists the responder queue', async () => {
    const open = makeQuery('open');
    get.mockResolvedValue(paged([open]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.findAll('[data-testid="query-row"]')).toHaveLength(1);
  });

  it('resolves with an additive correction sending the nested correction payload + Idempotency-Key', async () => {
    const open = makeQuery('open');
    get.mockResolvedValue(paged([open]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, open);
    await wrapper.find('#respond-note').setValue('Confirmed a shortfall.');
    await wrapper.find('[data-testid="with-correction"]').setValue(true);
    await wrapper.find('#correction-amount').setValue('5');
    await wrapper.find('#correction-currency').setValue('KES');
    await wrapper.find('#correction-reason').setValue('shortfall correction');
    post.mockResolvedValueOnce({ data: { data: makeQuery('resolved') } });
    get.mockResolvedValue(paged([makeQuery('resolved')]));
    await wrapper.find('[data-testid="respond-submit"]').trigger('click');
    await flushPromises();
    const [url, body, config] = post.mock.calls[0];
    expect(url).toBe(`/finance/earnings-queries/${open.id}/respond`);
    expect(body.decision).toBe('resolved');
    expect(body.correction).toEqual({ amount_minor: 500, currency: 'KES', reason: 'shortfall correction' });
    expect(typeof config.headers['Idempotency-Key']).toBe('string');
    // The screen never offers a ledger-amount editor — only an additive adjustment.
    expect(wrapper.text()).not.toContain('Edit ledger');
  });

  it('renders a terminal query read-only with no respond form', async () => {
    const resolved = makeQuery('resolved', { resolution_note: 'Corrected.' });
    get.mockResolvedValue(paged([resolved]));
    grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openDetail(wrapper, resolved);
    expect(wrapper.find('[data-testid="query-terminal"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="respond-submit"]').exists()).toBe(false);
  });
});
