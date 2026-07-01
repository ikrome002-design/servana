import type { InvoiceStatus } from '@/types/models';

/** Human-readable label for an invoice status (Phase 17). */
export function invoiceStatusLabel(status: InvoiceStatus): string {
  const labels: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    issued: 'Issued',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    void_pending: 'Void pending',
    voided: 'Voided',
    adjusted: 'Adjusted',
    refund_pending: 'Refund pending',
    adjustment_required: 'Adjustment required',
  };
  return labels[status];
}

/** Whether the invoice is in a finalized, read-only monetary snapshot state. */
export function isInvoiceReadOnly(status: InvoiceStatus): boolean {
  return status !== 'draft';
}
