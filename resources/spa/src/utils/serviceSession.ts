import type { CommissionPreview, ServiceSessionStatus } from '@/types/models';

/**
 * Human-readable service-session status label (Plan §25.2; Phase 16C). Status is
 * never communicated by colour alone — the badge always carries this text.
 */
export function serviceSessionStatusLabel(status: ServiceSessionStatus): string {
  const labels: Record<ServiceSessionStatus, string> = {
    pending: 'Pending',
    in_progress: 'In progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
  };

  return labels[status];
}

/**
 * The fixed Phase 16C completion-preview heading. A commission preview is NEVER
 * earned or payable before validated payment — the wording makes that explicit.
 */
export const COMMISSION_PREVIEW_LABEL = 'Preview — not earned or payable';

/**
 * A human-readable summary of a non-payable commission preview. "Not configured" is
 * never shown as a zero amount; salary-only is "not applicable".
 */
export function commissionPreviewSummary(preview: CommissionPreview | null): string {
  if (preview === null) return '';

  switch (preview.preview_status) {
    case 'not_configured':
      return 'Commission is not configured yet.';
    case 'not_applicable':
      return 'Commission does not apply (salary only).';
    case 'unavailable':
      return 'Commission preview is unavailable.';
    case 'available':
      return preview.amount_minor !== null && preview.currency !== null
        ? `${preview.currency} ${(preview.amount_minor / 100).toFixed(2)}`
        : 'Commission preview is unavailable.';
  }
}
