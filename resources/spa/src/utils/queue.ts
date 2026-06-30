import type { QueueAssignmentMode, QueueEntryStatus } from '@/types/models';

/**
 * Human-readable queue-entry status label (Plan §25.2, §37; Phase 16B). Status is
 * never communicated by colour alone — the badge always carries this text.
 */
export function queueStatusLabel(status: QueueEntryStatus): string {
  const labels: Record<QueueEntryStatus, string> = {
    waiting: 'Waiting',
    assigned: 'Assigned',
    called: 'Called',
    in_service: 'In service',
    completed: 'Completed',
    transferred: 'Transferred',
    cancelled: 'Cancelled',
    no_show: 'No-show',
  };

  return labels[status];
}

/** Human-readable assignment-mode label. */
export function assignmentModeLabel(mode: QueueAssignmentMode): string {
  const labels: Record<QueueAssignmentMode, string> = {
    next_available: 'Next available',
    manual: 'Manual',
    preferred_personnel: 'Preferred personnel',
  };

  return labels[mode];
}

/** A labelled wait estimate, e.g. "Estimate · ~25 min". Never a guaranteed time. */
export function waitEstimateLabel(minutes: number): string {
  return `Estimate · ~${minutes} min`;
}
