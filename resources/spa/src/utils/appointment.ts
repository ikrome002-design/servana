import type { AppointmentStatus } from '@/types/models';

/**
 * Convert an `<input type="datetime-local">` wall-clock value (e.g.
 * "2026-07-06T10:00") into an ISO instant in branch business time
 * (Africa/Nairobi, fixed +03:00, no DST) so the backend stores the correct
 * absolute time (Plan §36; currency/time rules). Returns the value unchanged if it
 * already carries an offset.
 */
export function toBusinessIso(localValue: string): string {
  if (localValue === '' || /[zZ]|[+-]\d{2}:?\d{2}$/.test(localValue)) {
    return localValue;
  }

  const withSeconds = localValue.length === 16 ? `${localValue}:00` : localValue;

  return `${withSeconds}+03:00`;
}

/**
 * Human-readable appointment status label (Plan §25.2; Phase 16A). Status is never
 * communicated by colour alone — the badge always carries this text.
 */
export function appointmentStatusLabel(status: AppointmentStatus): string {
  const labels: Record<AppointmentStatus, string> = {
    scheduled: 'Scheduled',
    confirmed: 'Confirmed',
    checked_in: 'Checked in',
    rescheduled: 'Rescheduled',
    cancelled: 'Cancelled',
    cancelled_with_reason: 'Cancelled (with reason)',
    no_show: 'No-show',
  };

  return labels[status];
}
