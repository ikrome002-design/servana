// Business-day helpers. Timestamps are UTC; display uses Africa/Nairobi (Plan §2 AS-3).
const NAIROBI_TZ = 'Africa/Nairobi';

export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat('en-KE', {
    timeZone: NAIROBI_TZ,
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(new Date(iso));
}

export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat('en-KE', {
    timeZone: NAIROBI_TZ,
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(iso));
}

/**
 * Hour and minute in Africa/Nairobi (Phase UI-16, UI16-CONTENT-003). Several pages used
 * `toLocaleTimeString([])`, which renders in the VIEWER'S zone — a Nairobi appointment read from a
 * browser set to another zone showed the wrong hour.
 */
export function formatTime(iso: string): string {
  return new Intl.DateTimeFormat('en-KE', {
    timeZone: NAIROBI_TZ,
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(iso));
}
