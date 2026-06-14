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
