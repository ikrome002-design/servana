import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));

const routerPush = vi.fn();
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: routerPush }),
}));

import CompensationLiabilities from '@/pages/finance/CompensationLiabilities.vue';
import { useAuthStore } from '@/stores/authStore';

const STAFF = '01HZ0000000000000000000000';

const summaryKes = {
  currency: 'KES',
  gross_salary_accrual_minor: 500000,
  salary_reversal_minor: 0,
  net_salary_liability_minor: 500000,
  gross_earned_commission_minor: 120000,
  commission_reversal_minor: -20000,
  net_commission_liability_minor: 100000,
  compensation_adjustment_minor: -5000,
  combined_net_liability_minor: 595000,
};
const summaryUsd = { ...summaryKes, currency: 'USD', combined_net_liability_minor: 42000 };

const positiveEntry = {
  id: '01ENTRYPOS0000000000000000',
  liability_type: 'salary',
  entry_type: 'accrual',
  status: 'pending',
  amount_minor: 500000,
  currency: 'KES',
  business_date: '2026-07-31',
  staff_profile_id: STAFF,
  staff_display_name: 'A. Stylist',
  branch_id: '01BRANCH00000000000000000',
  compensation_plan_id: '01PLAN0000000000000000000',
  commission_rule_id: null,
  pay_period_start: '2026-07-01',
  pay_period_end: '2026-07-31',
  invoice_reference: null,
  source_entry_id: null,
  created_at: '2026-08-01T00:00:00+00:00',
};
const negativeEntry = {
  ...positiveEntry,
  id: '01ENTRYNEG0000000000000000',
  liability_type: 'commission',
  entry_type: 'reversal',
  status: 'reversed',
  amount_minor: -20000,
  invoice_reference: 'INV-000123',
};

const adjustment = {
  id: '01ADJ00000000000000000000',
  adjustment_type: 'manual',
  amount_minor: -5000,
  currency: 'KES',
  reason: 'Goodwill correction',
  staff_profile_id: STAFF,
  staff_display_name: 'A. Stylist',
  branch_id: '01BRANCH00000000000000000',
  created_at: '2026-07-06T10:00:00+00:00',
};

function paged<T>(rows: T[]) {
  return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } } };
}

function apiError(code: string, fields: Record<string, string[]> = {}): unknown {
  return Object.assign(new Error(code), { isAxiosError: true, apiError: { code, message: 'server message', fields, meta: {} } });
}

function mockLoaded(): void {
  get.mockImplementation((url: string) => {
    if (url === '/compensation/liabilities/summary') return Promise.resolve({ data: { data: [summaryKes, summaryUsd] } });
    if (url === '/compensation/liabilities') return Promise.resolve(paged([positiveEntry, negativeEntry]));
    if (url === '/compensation/adjustments') return Promise.resolve(paged([adjustment]));
    if (url.startsWith('/compensation/adjustments/')) return Promise.resolve({ data: { data: adjustment } });
    return Promise.resolve(paged([]));
  });
}

const mountPage = () =>
  mount(CompensationLiabilities, {
    attachTo: document.body,
    global: {
      stubs: {
        SvModal: {
          template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><p>{{ description }}</p><slot /></div>',
          props: ['open', 'title', 'description'],
        },
      },
    },
  });

async function grantFinance(withAdjust = true): Promise<void> {
  const auth = useAuthStore();
  auth.permissions = withAdjust
    ? ['compensation.liability.view', 'compensation.adjustment.create']
    : ['compensation.liability.view'];
  auth.branchIds = ['b1'];
}

async function openAdjustmentForm(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('[data-testid="open-adjustment"]').trigger('click');
  await flushPromises();
}

async function fillValidAdjustment(wrapper: ReturnType<typeof mountPage>): Promise<void> {
  await wrapper.find('#adjustment-staff').setValue(STAFF);
  await wrapper.find('#adjustment-amount').setValue('50');
  await wrapper.find('#adjustment-currency').setValue('KES');
  await wrapper.find('#adjustment-reason').setValue('Agreed correction');
}

describe('CompensationLiabilities.vue (Finance)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    routerPush.mockReset();
  });

  it('renders a multi-currency summary without combining currencies', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    const cards = wrapper.findAll('[data-testid="summary-card"]');
    expect(cards).toHaveLength(2);
    expect(wrapper.text()).toContain('KES');
    expect(wrapper.text()).toContain('USD');
  });

  it('shows positive and negative amounts with a non-colour direction cue', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    const rows = wrapper.findAll('[data-testid="liability-entry-row"]');
    expect(rows).toHaveLength(2);
    expect(wrapper.text()).toContain('increases liability');
    expect(wrapper.text()).toContain('reduces liability');
  });

  it('applies filters through the store with only declared params', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    get.mockClear();
    await wrapper.find('#filter-liability-type').setValue('commission');
    await wrapper.find('[data-testid="apply-filters"]').trigger('submit');
    await flushPromises();
    const entriesCall = get.mock.calls.find((c) => c[0] === '/compensation/liabilities');
    expect(entriesCall?.[1].params).toMatchObject({ liability_type: 'commission', page: 1 });
  });

  it('renders the empty state when there are no entries', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/compensation/liabilities/summary') return Promise.resolve({ data: { data: [] } });
      return Promise.resolve(paged([]));
    });
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.text()).toContain('No liability entries match these filters.');
  });

  it('renders a forbidden state on a 403 read without leaking internals', async () => {
    const forbidden = Object.assign(new Error('403'), { isAxiosError: true, response: { status: 403 } });
    get.mockRejectedValue(forbidden);
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="liability-forbidden"]').exists()).toBe(true);
  });

  it('hides the adjustment action without compensation.adjustment.create', async () => {
    mockLoaded();
    await grantFinance(false);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="open-adjustment"]').exists()).toBe(false);
  });

  it('rejects a zero amount before calling the API', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await wrapper.find('#adjustment-staff').setValue(STAFF);
    await wrapper.find('#adjustment-amount').setValue('0');
    await wrapper.find('#adjustment-currency').setValue('KES');
    await wrapper.find('#adjustment-reason').setValue('Nope');
    await wrapper.find('[data-testid="adjustment-submit"]').trigger('submit');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('greater than zero');
  });

  it('rejects an invalid currency before calling the API', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await wrapper.find('#adjustment-staff').setValue(STAFF);
    await wrapper.find('#adjustment-amount').setValue('50');
    await wrapper.find('#adjustment-currency').setValue('KE');
    await wrapper.find('#adjustment-reason').setValue('Bad currency');
    await wrapper.find('[data-testid="adjustment-submit"]').trigger('submit');
    await flushPromises();
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('3-letter currency code');
  });

  it('shows a signed-amount direction preview that never calls a negative adjustment a payment', async () => {
    mockLoaded();
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await wrapper.find('#adjustment-amount').setValue('50');
    await wrapper.find('#adjustment-direction').setValue('decrease');
    await flushPromises();
    const preview = wrapper.find('[data-testid="adjustment-preview"]');
    expect(preview.exists()).toBe(true);
    expect(preview.text()).toContain('reduces liability');
    expect(preview.text()).toContain('not a payment');
  });

  it('surfaces the fresh-step-up state on a step_up_required response', async () => {
    mockLoaded();
    post.mockRejectedValueOnce(apiError('step_up_required'));
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await fillValidAdjustment(wrapper);
    await wrapper.find('[data-testid="adjustment-submit"]').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[data-testid="adjustment-step-up"]').exists()).toBe(true);
    // Routing to the established verification flow is available.
    await wrapper.find('[data-testid="adjustment-verify"]').trigger('click');
    expect(routerPush).toHaveBeenCalledWith({ name: 'auth.mfa.challenge' });
  });

  it('surfaces a safe period-lock message without leaking internals', async () => {
    mockLoaded();
    post.mockRejectedValueOnce(apiError('financial_period_locked'));
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await fillValidAdjustment(wrapper);
    await wrapper.find('[data-testid="adjustment-submit"]').trigger('submit');
    await flushPromises();
    const err = wrapper.find('[data-testid="adjustment-error"]');
    expect(err.exists()).toBe(true);
    expect(err.text()).toContain('financial period is locked');
    expect(err.text()).not.toContain('SQLSTATE');
  });

  it('records a valid adjustment and refreshes the server totals', async () => {
    mockLoaded();
    post.mockResolvedValueOnce({ data: { data: adjustment } });
    await grantFinance();
    const wrapper = mountPage();
    await flushPromises();
    await openAdjustmentForm(wrapper);
    await fillValidAdjustment(wrapper);
    get.mockClear();
    await wrapper.find('[data-testid="adjustment-submit"]').trigger('submit');
    await flushPromises();
    expect(post).toHaveBeenCalledTimes(1);
    const [url, body] = post.mock.calls[0];
    expect(url).toBe('/compensation/adjustments');
    expect(body).toEqual({ staff_profile_ulid: STAFF, amount_minor: 5000, currency: 'KES', reason: 'Agreed correction' });
    // Server-authoritative totals are re-fetched after a successful create.
    expect(get.mock.calls.some((c) => c[0] === '/compensation/liabilities/summary')).toBe(true);
  });
});
