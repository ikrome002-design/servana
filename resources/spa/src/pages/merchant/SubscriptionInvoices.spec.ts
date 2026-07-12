import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import SubscriptionInvoices from '@/pages/merchant/SubscriptionInvoices.vue';
import { useAuthStore } from '@/stores/authStore';

function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: '01INV', invoice_number: 'SUB-000001', status: 'issued',
    period_start: '2026-07-01', period_end: '2026-08-01',
    subtotal_minor: 500000, discount_minor: 0, total_minor: 500000, balance_minor: 500000, currency: 'KES',
    issued_at: '2026-07-01T00:00:00Z', due_at: '2026-07-08T00:00:00Z',
    payment_reference_pending: true, account_reference: null, has_pdf: false, pdf_version: 0, ...overrides,
  };
}

function mockApi(invoices: unknown[], readOnly = false) {
  get.mockImplementation((url: string) => {
    if (url === '/subscription-invoices') return Promise.resolve({ data: { data: invoices } });
    if (url === '/subscription') return Promise.resolve({ data: { data: { id: '01SUB', billing_read_only: readOnly } } });
    if (String(url).includes('/pdf/download-link')) return Promise.resolve({ data: { data: { url: 'https://signed/x?signature=abc', expires_at: 'x' } } });
    return Promise.resolve({ data: { data: null } });
  });
}

describe('merchant/SubscriptionInvoices.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    vi.stubGlobal('open', vi.fn());
  });

  it('lists invoices and shows the payment-reference-pending copy in the detail', async () => {
    mockApi([invoice()]);
    useAuthStore().permissions = ['merchant.subscription.invoice.view'];
    const wrapper = mount(SubscriptionInvoices);
    await flushPromises();
    expect(wrapper.get('[data-testid="invoice-number"]').text()).toContain('SUB-000001');
    expect(wrapper.get('[data-testid="payment-reference-pending"]').text()).toBe('Payment reference pending — see your billing dashboard');
  });

  it('generates a PDF (mutation) and then downloads the existing PDF (read)', async () => {
    mockApi([invoice()]);
    post.mockResolvedValueOnce({ data: { data: invoice({ has_pdf: true, pdf_version: 1 }) } });
    useAuthStore().permissions = ['merchant.subscription.invoice.view', 'merchant.subscription.invoice.download'];
    const wrapper = mount(SubscriptionInvoices);
    await flushPromises();
    await wrapper.get('[data-testid="generate-pdf"]').trigger('click');
    await flushPromises();
    expect(post).toHaveBeenCalledWith('/subscription-invoices/01INV/pdf');
    await wrapper.get('[data-testid="download-pdf"]').trigger('click');
    await flushPromises();
    expect(get).toHaveBeenCalledWith('/subscription-invoices/01INV/pdf/download-link');
    expect(window.open).toHaveBeenCalled();
  });

  it('disables new-PDF generation in billing read-only but keeps an existing PDF downloadable', async () => {
    mockApi([invoice({ has_pdf: true })], true);
    useAuthStore().permissions = ['merchant.subscription.invoice.view', 'merchant.subscription.invoice.download'];
    const wrapper = mount(SubscriptionInvoices);
    await flushPromises();
    expect(wrapper.get('[data-testid="generate-pdf"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-testid="download-pdf"]').exists()).toBe(true);
  });

  it('hides generate/download controls without the download permission', async () => {
    mockApi([invoice({ has_pdf: true })]);
    useAuthStore().permissions = ['merchant.subscription.invoice.view'];
    const wrapper = mount(SubscriptionInvoices);
    await flushPromises();
    expect(wrapper.find('[data-testid="generate-pdf"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="download-pdf"]').exists()).toBe(false);
  });
});
