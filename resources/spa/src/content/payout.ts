/**
 * Phase 20H payout + earnings-query display copy (Plan §62/§63). Labels are UX only; the authoritative
 * status/type values come from the server enums. Status is never conveyed by colour alone — the label
 * word carries the meaning.
 */

const PAYOUT_RUN_STATUS: Record<string, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  finance_verified: 'Finance verified',
  pending_merchant_admin_approval: 'Awaiting merchant approval',
  approved: 'Approved',
  paid: 'Paid (external settlement recorded)',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

export function payoutRunStatusLabel(value: string): string {
  return PAYOUT_RUN_STATUS[value] ?? value;
}

export const PAYOUT_RUN_STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  ...Object.entries(PAYOUT_RUN_STATUS).map(([value, label]) => ({ value, label })),
];

const EARNINGS_QUERY_STATUS: Record<string, string> = {
  open: 'Open',
  assigned: 'Assigned',
  resolved: 'Resolved',
  rejected: 'Rejected',
};

export function earningsQueryStatusLabel(value: string): string {
  return EARNINGS_QUERY_STATUS[value] ?? value;
}

export const EARNINGS_QUERY_STATUS_FILTER = [
  { value: '', label: 'All statuses' },
  ...Object.entries(EARNINGS_QUERY_STATUS).map(([value, label]) => ({ value, label })),
];

const EARNINGS_QUERY_SUBJECT: Record<string, string> = {
  commission_ledger: 'A commission entry',
  salary_ledger: 'A salary entry',
  payout_item: 'A payout item',
};

export function earningsQuerySubjectLabel(value: string): string {
  return EARNINGS_QUERY_SUBJECT[value] ?? value;
}

export const EARNINGS_QUERY_SUBJECT_OPTIONS = Object.entries(EARNINGS_QUERY_SUBJECT).map(
  ([value, label]) => ({ value, label }),
);

const EARNINGS_QUERY_TYPE: Record<string, string> = {
  commission_disagreement: 'Commission disagreement',
  salary_disagreement: 'Salary disagreement',
  payout_missing: 'A payout is missing',
  payout_amount: 'Payout amount looks wrong',
  statement_request: 'Statement request',
  other: 'Other',
};

export function earningsQueryTypeLabel(value: string): string {
  return EARNINGS_QUERY_TYPE[value] ?? value;
}

export const EARNINGS_QUERY_TYPE_OPTIONS = Object.entries(EARNINGS_QUERY_TYPE).map(
  ([value, label]) => ({ value, label }),
);

const ASSIGNED_ROLE: Record<string, string> = {
  finance: 'Finance',
  hr: 'Human Resource',
};

export function assignedRoleLabel(value: string | null | undefined): string {
  if (!value) return '—';
  return ASSIGNED_ROLE[value] ?? value;
}
