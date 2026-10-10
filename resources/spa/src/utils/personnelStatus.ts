import type { SvStatusTone } from '@/components/ui/SvStatusBadge.vue';

/**
 * Presentation-only status vocabulary for the Personnel experience (Phase UI-14).
 *
 * The server owns every status value; this only chooses a badge tone and a readable label for a
 * value it already sent. An unrecognised status is shown verbatim with the neutral tone — never
 * promoted to "success" — so an unknown state can never look like a good one.
 */
const SUCCESS = new Set(['completed', 'paid', 'earned', 'resolved', 'delivered', 'sent', 'available', 'approved', 'confirmed', 'active', 'ready']);
const INFO = new Set(['called', 'in_service', 'in_progress', 'assigned', 'scheduled', 'queued', 'open', 'under_review', 'accrued', 'sending', 'checked_in', 'generated', 'busy']);
const WARNING = new Set(['waiting', 'pending', 'due', 'information_requested', 'escalated', 'reopened', 'on_break', 'partially_sent', 'partially_delivered', 'draft', 'requested', 'outstanding']);
const ERROR = new Set(['cancelled', 'no_show', 'reversed', 'failed', 'unavailable', 'suspended', 'disputed', 'withdrawn', 'offline', 'rejected', 'transferred', 'transferred_away', 'expired']);

export function personnelStatusTone(status: string | null | undefined): SvStatusTone {
  const value = (status ?? '').toLowerCase();
  if (SUCCESS.has(value)) return 'success';
  if (INFO.has(value)) return 'info';
  if (WARNING.has(value)) return 'warning';
  if (ERROR.has(value)) return 'error';
  return 'neutral';
}

export function humanizeStatus(status: string | null | undefined): string {
  if (!status) return 'Not recorded';
  const text = status.replaceAll('_', ' ').trim();
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function compensationModelLabel(model: string | null | undefined): string {
  switch (model) {
    case 'commission_only': return 'Commission only';
    case 'salary_only': return 'Salary only';
    case 'salary_plus_commission': return 'Salary plus commission';
    default: return 'No current plan';
  }
}

const NAIROBI = 'Africa/Nairobi';

export function nairobiDate(value: string | null | undefined, withTime = false): string {
  if (!value) return 'Not recorded';
  // A bare YYYY-MM-DD is a business date, not an instant: format it without shifting the day.
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`));
  }
  return new Intl.DateTimeFormat('en-KE', withTime ? { dateStyle: 'medium', timeStyle: 'short', timeZone: NAIROBI } : { dateStyle: 'medium', timeZone: NAIROBI }).format(new Date(value));
}

export function nairobiTime(value: string | null | undefined): string {
  if (!value) return '—';
  return new Intl.DateTimeFormat('en-KE', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: NAIROBI }).format(new Date(value));
}

export function nairobiDayLabel(value: string): string {
  return new Intl.DateTimeFormat('en-KE', { weekday: 'long', day: 'numeric', month: 'short', timeZone: NAIROBI }).format(new Date(value));
}

export function nairobiDayKey(value: string): string {
  return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: NAIROBI }).format(new Date(value));
}
