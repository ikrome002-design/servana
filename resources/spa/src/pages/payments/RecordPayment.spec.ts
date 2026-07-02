import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

const push = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'inv1' }, path: '/front-office/payments/record/inv1' }),
  useRouter: () => ({ push }),
}));

import RecordPayment from '@/pages/payments/RecordPayment.vue';

function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}

function invoice() {
  return {
    id: 'inv1',
    invoice_number: 'KIL-INV-000001',
    status: 'issued',
    currency: 'KES',
    client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' },
    total: money(500000),
    validated_paid: money(0),
    balance: money(500000),
    items: [],
  };
}

function group(overrides: Record<string, unknown> = {}) {
  return {
    id: 'grp1',
    status: 'pending_validation',
    is_pending_validation: true,
    currency: 'KES',
    total: money(200000),
    components: [{ id: 'c1', method: 'cash', amount: money(200000), status: 'pending_validation', reference_masked: null }],
    ...overrides,
  };
}

describe('RecordPayment.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    push.mockReset();
  });

  it('shows the amount available to record and records a single cash payment as pending validation', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    post.mockResolvedValueOnce({ data: { data: group() } });
    const wrapper = mount(RecordPayment, { attachTo: document.body });
    await flushPromises();

    expect(wrapper.find('[data-testid="available-amount"]').text()).toContain('5000.00');

    await wrapper.find('#amount-0').setValue('2000');
    await wrapper.find('[data-testid="review-payment"]').trigger('click');
    await flushPromises();

    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'confirm-record',
    );
    confirm!.click();
    await flushPromises();

    expect(post).toHaveBeenCalledWith(
      '/invoices/inv1/payment-recording-groups',
      { components: [{ method: 'cash', amount_minor: 200000 }] },
      expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) }),
    );
    const success = wrapper.find('[data-testid="record-success"]');
    expect(success.exists()).toBe(true);
    expect(success.text().toLowerCase()).toContain('pending validation');
    expect(success.text().toLowerCase()).toContain('no receipt');
    wrapper.unmount();
  });

  it('builds a split payment and reflects the running group total', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    const wrapper = mount(RecordPayment);
    await flushPromises();

    await wrapper.find('#amount-0').setValue('1500');
    await wrapper.find('[data-testid="add-component"]').trigger('click');
    await wrapper.find('#amount-1').setValue('2500');
    await flushPromises();

    expect(wrapper.find('[data-testid="group-total"]').text()).toContain('4,000.00');
  });

  it('shows a reference field only for reference-bearing methods', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    const wrapper = mount(RecordPayment);
    await flushPromises();

    expect(wrapper.find('#reference-0').exists()).toBe(false);
    await wrapper.find('#method-0').setValue('mpesa_offline');
    await flushPromises();
    expect(wrapper.find('#reference-0').exists()).toBe(true);
  });

  it('blocks an overpayment beyond the available balance', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    const wrapper = mount(RecordPayment);
    await flushPromises();

    await wrapper.find('#amount-0').setValue('6000');
    await flushPromises();

    expect(wrapper.find('[data-testid="review-payment"]').attributes('disabled')).toBeDefined();
    expect(wrapper.text().toLowerCase()).toContain('exceeds the amount available');
  });

  it('surfaces a duplicate-suspected warning when the server holds the recording', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    post.mockRejectedValueOnce({
      response: {
        status: 409,
        data: { error: { code: 'payment_reference_duplicate_suspected', meta: { group_id: 'grp1', method: 'mpesa_offline', masked_reference: '••••1ABC' } } },
      },
    });
    const wrapper = mount(RecordPayment, { attachTo: document.body });
    await flushPromises();

    await wrapper.find('#method-0').setValue('mpesa_offline');
    await wrapper.find('#amount-0').setValue('1000');
    await wrapper.find('#reference-0').setValue('QGX7YT1ABC');
    await wrapper.find('[data-testid="review-payment"]').trigger('click');
    await flushPromises();
    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'confirm-record',
    );
    confirm!.click();
    await flushPromises();

    expect(wrapper.find('[data-testid="duplicate-warning"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('••••1ABC');
    wrapper.unmount();
  });

  it('shows no validation, receipt, or paid-status control', async () => {
    get.mockResolvedValueOnce({ data: { data: invoice() } });
    const wrapper = mount(RecordPayment);
    await flushPromises();

    const text = wrapper.text().toLowerCase();
    expect(text).not.toContain('validate');
    expect(text).not.toContain('mark paid');
  });
});
