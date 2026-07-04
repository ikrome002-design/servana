import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'cu1' }, path: '/finance/cash-up/cu1' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import CashUpDetail from '@/pages/finance/CashUpDetail.vue';
import { useAuthStore } from '@/stores/authStore';

function money(minor: number) {
  return { amount: minor, currency: 'KES', formatted: `KES ${(minor / 100).toFixed(2)}` };
}
function cashUp(overrides: Record<string, unknown> = {}) {
  return {
    id: 'cu1',
    business_date: '2026-07-03',
    status: 'submitted',
    expected: money(300000),
    counted: money(299000),
    variance: money(-1000),
    expected_minor: 300000,
    counted_minor: 299000,
    variance_minor: -1000,
    review_note: null,
    lines: [{ method: 'cash', expected_minor: 300000, counted_minor: 299000, variance_minor: -1000 }],
    ...overrides,
  };
}

describe('finance/CashUpDetail.vue (Finance checker)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows approve/reject/request-correction for a Finance checker on a submitted cash-up', async () => {
    get.mockResolvedValue({ data: { data: cashUp() } });
    useAuthStore().permissions = ['cash_up.view', 'cash_up.approve', 'cash_up.reject', 'cash_up.request_correction'];

    const wrapper = mount(CashUpDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="cash-up-approve"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="cash-up-reject"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="cash-up-request-correction"]').exists()).toBe(true);

    post.mockResolvedValueOnce({ data: { data: cashUp({ status: 'approved' }) } });
    await wrapper.find('[data-testid="cash-up-approve"]').trigger('click');
    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'cash-up-decision-confirm',
    ) as HTMLButtonElement;
    confirm.click();
    await flushPromises();
    const posted = post.mock.calls.find((c) => String(c[0]).endsWith('/approve'));
    expect(posted?.[2]?.headers?.['Idempotency-Key']).toBeTruthy();
  });

  it('hides approval controls from a viewer without cash_up.approve', async () => {
    get.mockResolvedValue({ data: { data: cashUp() } });
    useAuthStore().permissions = ['cash_up.view'];

    const wrapper = mount(CashUpDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="cash-up-approve"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="cash-up-reject"]').exists()).toBe(false);
  });
});
