import type { PlatformFeeConfiguration } from '@/stores/platformFeeConfigStore';

/**
 * Canonical user-facing copy for the Phase 20E percentage platform-fee surfaces (Plan §51; §14 of the
 * frontend spec). The persisted merchant tier value `split_tier` is NEVER shown — the canonical label is
 * "Shared". Wallet/settlement terminology is never used for Phase 20E.
 */

export const PLATFORM_FEE_TIER_OPTIONS: { value: string; label: string }[] = [
  { value: 'customer_centric', label: 'Customer-centric (client pays the fee)' },
  { value: 'shared', label: 'Shared (split between client and merchant)' },
  { value: 'business_centric', label: 'Business-centric (merchant absorbs the fee)' },
];

export const PLATFORM_FEE_TIER_LABELS: Record<string, string> = {
  customer_centric: 'Customer-centric',
  shared: 'Shared',
  business_centric: 'Business-centric',
};

export const PLATFORM_FEE_BILLING_MODE_OPTIONS: { value: string; label: string }[] = [
  { value: 'percentage_on_merchant_client_invoice', label: 'Percentage on merchant-client invoice' },
  { value: 'fixed_amount_plus_percentage_on_merchant_client_invoice', label: 'Fixed amount plus percentage' },
];

export const PLATFORM_FEE_BASIS_OPTIONS: { value: string; label: string }[] = [
  { value: 'merchant_client_invoice_service_subtotal', label: 'Merchant-client invoice service subtotal' },
  { value: 'merchant_client_invoice_total', label: 'Merchant-client invoice total' },
  { value: 'net_after_discount', label: 'Net after discount' },
  { value: 'invoice_item_subtotal', label: 'Invoice item subtotal' },
  { value: 'validated_paid_amount', label: 'Validated paid amount (customer-centric only)' },
];

export const PLATFORM_FEE_BASIS_LABELS: Record<string, string> = Object.fromEntries(
  PLATFORM_FEE_BASIS_OPTIONS.map((o) => [o.value, o.label]),
);

export const PLATFORM_FEE_STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  scheduled: 'Scheduled',
  active: 'Active',
  superseded: 'Superseded',
  cancelled: 'Cancelled',
};

export const PLATFORM_FEE_ENTRY_TYPE_LABELS: Record<string, string> = {
  earned: 'Earned',
  reversal: 'Reversal',
  adjustment: 'Adjustment',
};

export const PLATFORM_FEE_LEDGER_STATUS_LABELS: Record<string, string> = {
  pending: 'Pending',
  aggregated: 'Aggregated',
  invoiced: 'Invoiced',
  reversed: 'Reversed',
  adjusted: 'Adjusted',
};

export const PLATFORM_FEE_DISPUTE_STATUS_LABELS: Record<string, string> = {
  open: 'Open',
  under_review: 'Under review',
  resolved: 'Resolved',
  rejected: 'Rejected',
};

export const PLATFORM_FEE_ENTRY_TYPE_FILTER: { value: string; label: string }[] = [
  { value: '', label: 'All entry types' },
  { value: 'earned', label: 'Earned' },
  { value: 'reversal', label: 'Reversal' },
  { value: 'adjustment', label: 'Adjustment' },
];

export const PLATFORM_FEE_DISPUTE_STATUS_FILTER: { value: string; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'open', label: 'Open' },
  { value: 'under_review', label: 'Under review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
];

export function tierLabel(tier: string): string {
  return PLATFORM_FEE_TIER_LABELS[tier] ?? tier;
}

/** Human-readable terms for a configuration row, e.g. "2.50% · Customer-centric". */
export function platformFeeConfigTermsLabel(config: PlatformFeeConfiguration): string {
  const parts: string[] = [];
  if (config.percentage_basis_points !== null) parts.push(`${(config.percentage_basis_points / 100).toFixed(2)}%`);
  if (config.fixed_component_minor !== null && config.fixed_component_minor > 0) {
    parts.push(`+ ${config.currency} ${(config.fixed_component_minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`);
  }
  if (config.tier_behavior !== null) parts.push(tierLabel(config.tier_behavior));
  return parts.join(' · ');
}
