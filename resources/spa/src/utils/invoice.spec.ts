import { describe, expect, it } from 'vitest';
import { invoiceStatusLabel, isInvoiceReadOnly } from '@/utils/invoice';

describe('invoice utils', () => {
  it('labels every status with text (never colour-only)', () => {
    expect(invoiceStatusLabel('draft')).toBe('Draft');
    expect(invoiceStatusLabel('issued')).toBe('Issued');
    expect(invoiceStatusLabel('partially_paid')).toBe('Partially paid');
    expect(invoiceStatusLabel('paid')).toBe('Paid');
    expect(invoiceStatusLabel('void_pending')).toBe('Void pending');
    expect(invoiceStatusLabel('voided')).toBe('Voided');
    expect(invoiceStatusLabel('adjusted')).toBe('Adjusted');
    expect(invoiceStatusLabel('refund_pending')).toBe('Refund pending');
    expect(invoiceStatusLabel('adjustment_required')).toBe('Adjustment required');
  });

  it('treats only a draft as editable; every finalized state is read-only', () => {
    expect(isInvoiceReadOnly('draft')).toBe(false);
    expect(isInvoiceReadOnly('issued')).toBe(true);
    expect(isInvoiceReadOnly('voided')).toBe(true);
    expect(isInvoiceReadOnly('adjusted')).toBe(true);
  });
});
