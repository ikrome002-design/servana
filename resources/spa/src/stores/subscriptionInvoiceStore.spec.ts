import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { PAYMENT_REFERENCE_PENDING_TEXT, useSubscriptionInvoiceStore } from '@/stores/subscriptionInvoiceStore';

const invoice = {
  id: '01INV',
  invoice_number: 'SUB-000001',
  status: 'issued',
  total_minor: 500000,
  currency: 'KES',
  payment_reference_pending: true,
  has_pdf: false,
  pdf_version: 0,
};

describe('subscriptionInvoiceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('exposes the exact pending-reference copy', () => {
    expect(PAYMENT_REFERENCE_PENDING_TEXT).toBe('Payment reference pending — see your billing dashboard');
  });

  it('fetches invoices and applies an allowlisted status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [invoice] } });
    const store = useSubscriptionInvoiceStore();
    store.filterStatus = 'issued';
    await store.fetchInvoices();
    expect(get).toHaveBeenCalledWith('/subscription-invoices', { params: { status: 'issued' } });
    expect(store.invoices).toHaveLength(1);
  });

  it('generates a PDF via POST (mutation) and updates the row', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...invoice, has_pdf: true, pdf_version: 1 } } });
    const store = useSubscriptionInvoiceStore();
    store.invoices = [invoice] as never;
    const updated = await store.generatePdf('01INV');
    expect(post).toHaveBeenCalledWith('/subscription-invoices/01INV/pdf');
    expect(updated.has_pdf).toBe(true);
    expect(store.invoices[0]?.has_pdf).toBe(true);
  });

  it('requests an existing-PDF download link via GET (read)', async () => {
    get.mockResolvedValueOnce({ data: { data: { url: 'https://signed/x?signature=abc', expires_at: '2026-08-01T00:00:00Z' } } });
    const store = useSubscriptionInvoiceStore();
    const link = await store.downloadLink('01INV');
    expect(get).toHaveBeenCalledWith('/subscription-invoices/01INV/pdf/download-link');
    expect(link.url).toContain('signature=');
  });
});
