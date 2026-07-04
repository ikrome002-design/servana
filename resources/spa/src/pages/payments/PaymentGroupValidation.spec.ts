import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'grp1' }, path: '/finance/pending-validations/grp1' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import PaymentGroupDetail from '@/pages/payments/PaymentGroupDetail.vue';
import { useAuthStore } from '@/stores/authStore';

function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}
function group(overrides: Record<string, unknown> = {}) {
  return {
    id: 'grp1',
    status: 'pending_validation',
    is_pending_validation: true,
    currency: 'KES',
    total: money(200000),
    maker: { id: 'u1', name: 'Front Office' },
    invoice: { id: 'inv1', invoice_number: 'KIL-INV-000001' },
    components: [{ id: 'c1', method: 'cash', amount: money(200000), status: 'pending_validation', reference_masked: null }],
    ...overrides,
  };
}

describe('PaymentGroupDetail.vue (Phase 18B validation)', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows whole-group decisions for a Finance checker and issues one receipt on validate', async () => {
    get.mockResolvedValue({ data: { data: group() } });
    post.mockResolvedValueOnce({ data: { data: group({ status: 'validated' }) } });
    useAuthStore().permissions = ['customer_payment.validate', 'customer_payment.reject'];

    const wrapper = mount(PaymentGroupDetail, { attachTo: document.body });
    await flushPromises();

    // Whole-group decision — no per-component validate control exists.
    expect(wrapper.find('[data-testid="validate-open"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="reject-open"]').exists()).toBe(true);

    await wrapper.find('[data-testid="validate-open"]').trigger('click');
    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'decision-confirm',
    ) as HTMLButtonElement;
    confirm.click();
    await flushPromises();

    const posted = post.mock.calls.find((c) => String(c[0]).endsWith('/validate'));
    expect(posted).toBeTruthy();
    expect(posted?.[2]?.headers?.['Idempotency-Key']).toBeTruthy();
    expect(document.body.querySelector('[data-testid="receipt-issued"]')).toBeTruthy();
  });

  it('hides validation controls from a user without the checker capability (Front Office)', async () => {
    get.mockResolvedValue({ data: { data: group() } });
    useAuthStore().permissions = ['customer_payment.record'];

    const wrapper = mount(PaymentGroupDetail, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="validate-open"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="reject-open"]').exists()).toBe(false);
  });

  it('requires a reason to reject or request correction (button disabled until entered)', async () => {
    get.mockResolvedValue({ data: { data: group() } });
    useAuthStore().permissions = ['customer_payment.validate', 'customer_payment.reject'];

    const wrapper = mount(PaymentGroupDetail, { attachTo: document.body });
    await flushPromises();
    await wrapper.find('[data-testid="reject-open"]').trigger('click');

    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'decision-confirm',
    ) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
  });
});
