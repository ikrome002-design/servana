import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'inv1' }, path: '/front-office/invoices/inv1' }),
}));

import InvoiceDetail from '@/pages/invoicing/InvoiceDetail.vue';

function money(amount: number) {
  return { amount, currency: 'KES', formatted: `KES ${(amount / 100).toFixed(2)}` };
}

function invoiceWith(status: string, can: Record<string, boolean>, extra: Record<string, unknown> = {}) {
  return {
    id: 'inv1',
    invoice_number: status === 'draft' ? null : 'KIL-INV-000001',
    status,
    is_draft: status === 'draft',
    currency: 'KES',
    client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678', phone_last_four: '5678' },
    subtotal: money(500000),
    discount: money(0),
    tax: money(0),
    preferred_personnel_fee: money(20000),
    total: money(520000),
    validated_paid: money(0),
    balance: money(520000),
    percentage_fee_config_snapshot: null,
    finalized_at: status === 'draft' ? null : '2026-07-01T07:00:00+00:00',
    voided_at: null,
    void_reason: null,
    adjusted_at: null,
    adjustment_reason: null,
    created_at: '2026-07-01T06:00:00+00:00',
    items: [
      {
        id: 'it1',
        service: { id: 'sv1', name: 'Haircut' },
        personnel: { id: 'st1', display_name: 'Joy W.' },
        description: 'Haircut',
        quantity: 1,
        unit_price: money(500000),
        line_total: money(500000),
        preferred_personnel_fee: money(20000),
        eligible_for_commission: true,
        currency: 'KES',
      },
    ],
    can,
    ...extra,
  };
}

describe('InvoiceDetail.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('shows the total and the preferred-personnel fee separately from the service price', async () => {
    get.mockResolvedValueOnce({ data: { data: invoiceWith('issued', { finalize: false, void: false, adjust: false }) } });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();

    expect(wrapper.find('[data-testid="invoice-total"]').text()).toContain('5200.00');
    expect(wrapper.find('[data-testid="item-preferred-fee"]').text()).toContain('Preferred-personnel fee');
  });

  it('shows the client-facing platform-fee line only when a shifted amount is present', async () => {
    // Shared / business-centric tiers shift a portion → the server returns a positive money object.
    get.mockResolvedValueOnce({
      data: { data: invoiceWith('issued', {}, { platform_fee_client_shifted: money(6250) }) },
    });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();
    const line = wrapper.find('[data-testid="invoice-platform-fee-line"]');
    expect(line.exists()).toBe(true);
    expect(line.text()).toContain('Platform fee');
    expect(line.text()).toContain('62.50');
  });

  it('shows no platform-fee line for a customer-centric / fixed-only invoice (server null)', async () => {
    get.mockResolvedValueOnce({
      data: { data: invoiceWith('issued', {}, { platform_fee_client_shifted: null }) },
    });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();
    expect(wrapper.find('[data-testid="invoice-platform-fee-line"]').exists()).toBe(false);
  });

  it('marks a finalized invoice as a read-only snapshot with its number', async () => {
    get.mockResolvedValueOnce({ data: { data: invoiceWith('issued', {}) } });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();

    expect(wrapper.find('[data-testid="readonly-note"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('KIL-INV-000001');
  });

  it('shows the Finalize control for a draft with the finalize capability', async () => {
    get.mockResolvedValueOnce({ data: { data: invoiceWith('draft', { finalize: true }) } });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();

    expect(wrapper.find('[data-testid="finalize"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="readonly-note"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="void"]').exists()).toBe(false);
  });

  it('shows Finance void + adjust controls only when the capability map allows them', async () => {
    get.mockResolvedValueOnce({ data: { data: invoiceWith('issued', { void: true, adjust: true }) } });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();

    expect(wrapper.find('[data-testid="void"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="adjust"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="finalize"]').exists()).toBe(false);
  });

  it('finalizes only after explicit confirmation', async () => {
    get.mockResolvedValue({ data: { data: invoiceWith('draft', { finalize: true }) } });
    post.mockResolvedValueOnce({ data: { data: invoiceWith('issued', {}) } });
    const wrapper = mount(InvoiceDetail, { attachTo: document.body });
    await flushPromises();

    await wrapper.find('[data-testid="finalize"]').trigger('click');
    await flushPromises();

    const confirm = Array.from(document.body.querySelectorAll('button')).find(
      (b) => b.getAttribute('data-testid') === 'finalize-confirm',
    );
    confirm!.click();
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/invoices/inv1/finalize', null, expect.objectContaining({
      headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
    }));
    wrapper.unmount();
  });

  it('exposes no payment or receipt action', async () => {
    get.mockResolvedValueOnce({ data: { data: invoiceWith('issued', { void: true, adjust: true }) } });
    const wrapper = mount(InvoiceDetail);
    await flushPromises();

    const text = wrapper.text().toLowerCase();
    expect(text).not.toContain('record payment');
    expect(text).not.toContain('receipt');
    expect(text).not.toContain('mark paid');
  });
});
