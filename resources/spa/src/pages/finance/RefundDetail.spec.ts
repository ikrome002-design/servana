import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'rf1' }, path: '/finance/refunds/rf1' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import RefundDetail from '@/pages/finance/RefundDetail.vue';
import { useAuthStore } from '@/stores/authStore';

function money(minor: number) {
  return { amount: minor, currency: 'KES', formatted: `KES ${(minor / 100).toFixed(2)}` };
}
function refund(overrides: Record<string, unknown> = {}) {
  return {
    id: 'rf1',
    status: 'requested',
    amount: money(50000),
    currency: 'KES',
    method: 'cash',
    reference_masked: null,
    reason: 'Client returned the service.',
    refund_group: 'g1',
    approved_at: null,
    finalized_at: null,
    rejected_at: null,
    created_at: '2026-07-03T09:00:00Z',
    invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001', status: 'refund_pending' },
    payment_record: { id: 'c1', method: 'cash' },
    ...overrides,
  };
}

describe('finance/RefundDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows approve/reject for a checker on a requested refund and sends an idempotency key', async () => {
    get.mockResolvedValue({ data: { data: refund() } });
    useAuthStore().permissions = ['refund.approve'];

    const wrapper = mount(RefundDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="refund-approve"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="refund-reject"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="refund-finalize"]').exists()).toBe(false);

    post.mockResolvedValueOnce({ data: { data: refund({ status: 'approved' }) } });
    await wrapper.find('[data-testid="refund-approve"]').trigger('click');
    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'refund-confirm',
    ) as HTMLButtonElement;
    confirm.click();
    await flushPromises();
    const posted = post.mock.calls.find((c) => String(c[0]).endsWith('/approve'));
    expect(posted?.[2]?.headers?.['Idempotency-Key']).toBeTruthy();
  });

  it('shows an irreversible finalize only on an approved refund with refund.finalize', async () => {
    get.mockResolvedValue({ data: { data: refund({ status: 'approved', approved_at: '2026-07-03T10:00:00Z' }) } });
    useAuthStore().permissions = ['refund.finalize'];

    const wrapper = mount(RefundDetail, { attachTo: document.body });
    await flushPromises();

    const finalize = wrapper.find('[data-testid="refund-finalize"]');
    expect(finalize.exists()).toBe(true);
    await finalize.trigger('click');
    expect(document.body.textContent).toContain('IRREVERSIBLE');
  });

  it('hides all controls from a requester without approve/finalize', async () => {
    get.mockResolvedValue({ data: { data: refund() } });
    useAuthStore().permissions = ['refund.create'];

    const wrapper = mount(RefundDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="refund-approve"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="refund-finalize"]').exists()).toBe(false);
  });
});
