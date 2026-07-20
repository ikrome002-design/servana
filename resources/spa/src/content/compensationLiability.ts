/**
 * Canonical user-facing copy for the Phase 20G Finance compensation-liability surface (Plan §61/§80;
 * §16 of the frontend spec). Domain terms only — an "accrual" / "earned commission" / "reversal" /
 * "adjustment" is a LIABILITY fact, never a payout, settlement, disbursement, Wallet, earnings
 * statement or "paid" event. Labels mirror the backing enums; the browser never invents a status.
 */

interface Option {
  value: string;
  label: string;
}

export const LIABILITY_TYPE_LABELS: Record<string, string> = {
  salary: 'Salary',
  commission: 'Commission',
};

export const LIABILITY_TYPE_FILTER: Option[] = [
  { value: '', label: 'Salary and commission' },
  { value: 'salary', label: 'Salary' },
  { value: 'commission', label: 'Commission' },
];

/** Union of salary + commission entry-type values, labelled for display. */
export const ENTRY_TYPE_LABELS: Record<string, string> = {
  accrual: 'Salary accrual',
  earned: 'Earned commission',
  reversal: 'Reversal',
  adjustment: 'Adjustment',
  pending_preview: 'Pending preview',
};

export const ENTRY_TYPE_FILTER: Option[] = [
  { value: '', label: 'All entry types' },
  { value: 'accrual', label: 'Salary accrual' },
  { value: 'earned', label: 'Earned commission' },
  { value: 'reversal', label: 'Reversal' },
  { value: 'adjustment', label: 'Adjustment' },
];

/** Union of salary + commission ledger statuses. */
export const LEDGER_STATUS_LABELS: Record<string, string> = {
  pending: 'Pending',
  earned: 'Earned',
  included_in_payout: 'Included in payout',
  paid: 'Paid',
  reversed: 'Reversed',
  adjusted: 'Adjusted',
  cancelled: 'Cancelled',
};

export const LEDGER_STATUS_FILTER: Option[] = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'earned', label: 'Earned' },
  { value: 'included_in_payout', label: 'Included in payout' },
  { value: 'paid', label: 'Paid' },
  { value: 'reversed', label: 'Reversed' },
  { value: 'adjusted', label: 'Adjusted' },
  { value: 'cancelled', label: 'Cancelled' },
];

export const ADJUSTMENT_TYPE_LABELS: Record<string, string> = {
  manual: 'Manual adjustment',
  paid_commission_reversal: 'Paid commission reversal',
  paid_salary_reversal: 'Paid salary reversal',
  correction: 'Correction',
};

export function entryTypeLabel(value: string): string {
  return ENTRY_TYPE_LABELS[value] ?? value;
}

export function ledgerStatusLabel(value: string): string {
  return LEDGER_STATUS_LABELS[value] ?? value;
}

export function liabilityTypeLabel(value: string): string {
  return LIABILITY_TYPE_LABELS[value] ?? value;
}

export function adjustmentTypeLabel(value: string): string {
  return ADJUSTMENT_TYPE_LABELS[value] ?? value;
}
