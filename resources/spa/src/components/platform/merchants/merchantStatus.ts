/**
 * Shared merchant status vocabulary for the three Super Administrator merchant pages
 * (Phase UI-08; contract pages §5.4.10, §5.4.11, §5.4.12).
 *
 * Operational status and billing status are SEPARATE authorities. The contract requires them to be
 * presented as separate, prominently labelled facts, and the plan forbids a billing payment from
 * clearing a non-billing suspension — so the two vocabularies live in two maps here rather than one
 * merged "status", which is exactly how the two get conflated in a UI.
 *
 * An unrecognised value is returned verbatim and toned `neutral`. Inventing a friendly label, or
 * defaulting an unknown state to `success`, would tell a governance operator that a state they have
 * never seen is fine.
 */
import type { SvStatusTone } from '@/components/ui/SvStatusBadge.vue';

export const OPERATIONAL_STATUS_LABELS: Record<string, string> = {
  pending_setup: 'Pending setup',
  active: 'Active',
  suspended: 'Suspended',
  deactivated: 'Deactivated',
};

export const BILLING_STATUS_LABELS: Record<string, string> = {
  trialing: 'Trialing',
  active: 'Active',
  overdue: 'Overdue',
  read_only_grace: 'Read-only grace',
  suspended_billing: 'Suspended',
};

const OPERATIONAL_TONES: Record<string, SvStatusTone> = {
  pending_setup: 'info',
  active: 'success',
  suspended: 'error',
  deactivated: 'neutral',
};

const BILLING_TONES: Record<string, SvStatusTone> = {
  trialing: 'info',
  active: 'success',
  overdue: 'warning',
  read_only_grace: 'warning',
  suspended_billing: 'error',
};

export function operationalLabel(status: string): string {
  return OPERATIONAL_STATUS_LABELS[status] ?? status;
}

export function billingLabel(status: string): string {
  return BILLING_STATUS_LABELS[status] ?? status;
}

export function operationalTone(status: string): SvStatusTone {
  return OPERATIONAL_TONES[status] ?? 'neutral';
}

export function billingTone(status: string): SvStatusTone {
  return BILLING_TONES[status] ?? 'neutral';
}

/**
 * The only filter the shipped platform reads accept (`PlatformMerchantGovernanceController::
 * applyStatusFilter`, allowlisted against `MerchantStatus`). Offering any other filter control here
 * would be a control that silently does nothing.
 */
export const OPERATIONAL_STATUS_FILTER_OPTIONS = [
  { value: '', label: 'All operational statuses' },
  { value: 'pending_setup', label: 'Pending setup' },
  { value: 'active', label: 'Active' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'deactivated', label: 'Deactivated' },
];
