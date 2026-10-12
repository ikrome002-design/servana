/**
 * Server status token → human-readable label (Phase UI-16).
 *
 * `SvStatusBadge` requires human-readable text, but eight UI-08 platform table cells passed the raw
 * machine token straight through, so operators read `in_force`, `pending_setup` and
 * `suspended_billing` (UI16-CONTENT-001). This turns a token into words WITHOUT reinterpreting it:
 * the server's vocabulary is kept, only its spelling changes, and an absent value is said to be
 * absent rather than shown as a blank badge.
 */
export function statusLabel(token: string | null | undefined): string {
  if (!token) return 'Not recorded';
  const text = token.replaceAll('_', ' ').trim();
  return text.charAt(0).toUpperCase() + text.slice(1);
}
