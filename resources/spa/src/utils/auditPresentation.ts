import type { SvStatusTone } from '@/components/ui/SvStatusBadge.vue';

/**
 * Audit presentation vocabulary (Phase UI-15; UI/UX plan Appendix A §12).
 *
 * Pure display helpers. Nothing here decides access, masks a value or computes a security
 * conclusion: every value it formats has already been authorized and masked by the server. Two
 * rules are structural:
 *
 *  1. **Severity and review status are different facts.** `severity` belongs to the immutable
 *     source event; review status belongs to the mutable flag. They have separate vocabularies and
 *     separate helpers so one can never be rendered as the other (§12.4.5).
 *  2. **Unknown is reported as unknown.** An unrecognised severity, review, export or integrity
 *     value degrades to a neutral "unknown" presentation — never to a reassuring one.
 */

export type AuditSeverity = 'info' | 'notice' | 'warning' | 'high' | 'critical';
export type AuditReviewStatus = 'open' | 'under_review' | 'resolved' | 'dismissed' | 'reopened';
export type AuditIntegrityStatus = 'verified' | 'partial' | 'broken' | 'empty';

export interface AuditPresentation {
  label: string;
  tone: SvStatusTone;
}

const SEVERITY: Record<AuditSeverity, AuditPresentation & { rank: number }> = {
  info: { label: 'Info', tone: 'neutral', rank: 0 },
  notice: { label: 'Notice', tone: 'info', rank: 1 },
  warning: { label: 'Warning', tone: 'warning', rank: 2 },
  high: { label: 'High', tone: 'error', rank: 3 },
  critical: { label: 'Critical', tone: 'error', rank: 4 },
};

export function severityPresentation(severity: string | null | undefined): AuditPresentation & { rank: number; known: boolean } {
  const entry = severity ? SEVERITY[severity as AuditSeverity] : undefined;
  return entry ? { ...entry, known: true } : { label: 'Unknown severity', tone: 'neutral', rank: -1, known: false };
}

/** High and critical are the "investigate" band the dashboard pins. */
export function isEscalatedSeverity(severity: string | null | undefined): boolean {
  return severityPresentation(severity).rank >= SEVERITY.high.rank;
}

const REVIEW: Record<AuditReviewStatus, AuditPresentation> = {
  open: { label: 'Review open', tone: 'warning' },
  under_review: { label: 'Under review', tone: 'info' },
  resolved: { label: 'Resolved', tone: 'success' },
  dismissed: { label: 'Dismissed', tone: 'neutral' },
  reopened: { label: 'Reopened', tone: 'warning' },
};

export const REVIEW_STATUSES = Object.keys(REVIEW) as AuditReviewStatus[];

export function reviewPresentation(status: string | null | undefined): AuditPresentation & { known: boolean } {
  const entry = status ? REVIEW[status as AuditReviewStatus] : undefined;
  return entry ? { ...entry, known: true } : { label: 'Unknown review state', tone: 'neutral', known: false };
}

/** Open, under-review and reopened flags are the active review queue. */
export function isActiveReview(status: string | null | undefined): boolean {
  return status === 'open' || status === 'under_review' || status === 'reopened';
}

const EXPORT: Record<string, AuditPresentation & { description: string }> = {
  queued: { label: 'Queued', tone: 'info', description: 'Waiting for generation to begin.' },
  processing: { label: 'Processing', tone: 'info', description: 'The masked file is being generated.' },
  ready: { label: 'Ready', tone: 'success', description: 'Available for an authorized private download until it expires.' },
  failed: { label: 'Failed', tone: 'error', description: 'Generation failed. Request a new export if it is still needed.' },
  expired: { label: 'Expired', tone: 'neutral', description: 'The file has expired and can no longer be downloaded.' },
  revoked: { label: 'Revoked', tone: 'neutral', description: 'The file was revoked and can no longer be downloaded.' },
};

export function exportPresentation(status: string | null | undefined): AuditPresentation & { description: string; known: boolean } {
  const entry = status ? EXPORT[status] : undefined;
  return entry ? { ...entry, known: true } : { label: 'Unknown export state', tone: 'neutral', description: 'This export reported a state Servana does not recognise.', known: false };
}

const INTEGRITY: Record<AuditIntegrityStatus, AuditPresentation & { headline: string }> = {
  verified: { label: 'Verified', tone: 'success', headline: 'Every record in your chain checked out' },
  partial: { label: 'Recent records verified', tone: 'info', headline: 'The latest records check out' },
  broken: { label: 'Break detected', tone: 'error', headline: 'The audit chain does not verify' },
  empty: { label: 'No records yet', tone: 'neutral', headline: 'There is nothing to verify yet' },
};

export function integrityPresentation(status: string | null | undefined): AuditPresentation & { headline: string; known: boolean } {
  const entry = status ? INTEGRITY[status as AuditIntegrityStatus] : undefined;
  return entry ? { ...entry, known: true } : { label: 'Not checked', tone: 'neutral', headline: 'Integrity has not been checked', known: false };
}

/** Readable module label for an action prefix. The raw action token is always shown beside it. */
const MODULES: ReadonlyArray<[RegExp, string]> = [
  [/^(customer_payment|receipt|refund|finance_dispute|cash_up|financial_period|finance_export|invoice|platform_fee)\b/, 'Finance'],
  [/^(compensation|commission_rule|payout_run|earnings_statement|earnings_query)\b/, 'Compensation'],
  [/^(appointment|walk_in|queue|queue_entry|service_session)\b/, 'Operations'],
  [/^(client|client_consent)\b/, 'Clients'],
  [/^(service|service_category|personnel_eligibility|personnel_availability)\b/, 'Services and staffing'],
  [/^(branch|branch_assignment)\b/, 'Branch'],
  [/^(invitation|membership|permission|unauthorized_access|mfa|auth|login|logout)/, 'Access and security'],
  [/^personnel\.(sms|served_clients)/, 'Contact protection'],
  [/^(file|audit_export)\b/, 'Files and exports'],
  [/^audit\./, 'Audit review'],
];

export function actionModule(action: string): string {
  for (const [pattern, label] of MODULES) {
    if (pattern.test(action)) return label;
  }
  return 'Branch activity';
}

function sentence(words: string): string {
  const text = words.replace(/[_.]+/g, ' ').replace(/\s+/g, ' ').trim();
  return text.length === 0 ? text : text[0].toUpperCase() + text.slice(1);
}

/** `customer_payment.duplicate_override_approved` → "Customer payment — duplicate override approved". */
export function humanizeAction(action: string): string {
  const dot = action.indexOf('.');
  if (dot === -1) return sentence(action);
  return `${sentence(action.slice(0, dot))} — ${action.slice(dot + 1).replace(/[_.]+/g, ' ').trim()}`;
}

/** `MerchantUserPermissionOverride` → "Merchant user permission override". */
export function humanizeSubject(subject: string | null | undefined): string | null {
  if (!subject) return null;
  return sentence(subject.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase());
}

export function humanizeKey(key: string): string {
  return sentence(key);
}

/** Render a masked context value as text. Objects/arrays are flattened, never `eval`-ed or linked. */
export function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'string' || typeof value === 'number') return String(value);
  if (Array.isArray(value)) return value.map((item) => displayValue(item)).join(', ');
  if (typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .map(([key, inner]) => `${humanizeKey(key)}: ${displayValue(inner)}`)
      .join(' · ');
  }
  return String(value);
}

export interface AuditDiffRow {
  field: string;
  before: string;
  after: string;
  changed: boolean;
}

export interface AuditContextBreakdown {
  /** Paired before/after values, in the order the server listed them. */
  changes: AuditDiffRow[];
  /** Everything else, already masked by the server. */
  facts: Array<{ key: string; label: string; value: string }>;
}

const OBJECT_PAIRS: ReadonlyArray<[string, string]> = [
  ['old_values', 'new_values'],
  ['before', 'after'],
  ['old_value', 'new_value'],
];
const PREFIX_PAIRS: ReadonlyArray<[string, string]> = [
  ['previous_', 'new_'],
  ['prev_', 'new_'],
  ['old_', 'new_'],
  ['from_', 'to_'],
];

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Split a masked context map into a structured before/after diff and the remaining facts
 * (§12.4.4 "use a structured diff and avoid dumping raw JSON"). Recognises the recorder's existing
 * conventions — `old_values`/`new_values` objects and `previous_x`/`new_x`, `from_x`/`to_x` pairs.
 * It only re-arranges what the server sent; it never derives, unmasks or hides a value.
 */
export function buildContextBreakdown(context: Record<string, unknown> | null | undefined): AuditContextBreakdown {
  const source = context ?? {};
  const used = new Set<string>();
  const changes: AuditDiffRow[] = [];

  for (const [beforeKey, afterKey] of OBJECT_PAIRS) {
    const before = source[beforeKey];
    const after = source[afterKey];
    if (!isRecord(before) && !isRecord(after)) continue;
    used.add(beforeKey).add(afterKey);
    const left = isRecord(before) ? before : {};
    const right = isRecord(after) ? after : {};
    for (const field of [...new Set([...Object.keys(left), ...Object.keys(right)])]) {
      const b = displayValue(left[field]);
      const a = displayValue(right[field]);
      changes.push({ field: humanizeKey(field), before: b, after: a, changed: b !== a });
    }
  }

  for (const key of Object.keys(source)) {
    if (used.has(key)) continue;
    for (const [beforePrefix, afterPrefix] of PREFIX_PAIRS) {
      if (!key.startsWith(beforePrefix)) continue;
      const field = key.slice(beforePrefix.length);
      const partner = `${afterPrefix}${field}`;
      if (field === '' || !(partner in source) || used.has(partner)) continue;
      used.add(key).add(partner);
      const b = displayValue(source[key]);
      const a = displayValue(source[partner]);
      changes.push({ field: humanizeKey(field), before: b, after: a, changed: b !== a });
      break;
    }
  }

  const facts = Object.entries(source)
    .filter(([key]) => !used.has(key))
    .map(([key, value]) => ({ key, label: humanizeKey(key), value: displayValue(value) }));

  return { changes, facts };
}

/** True when a masked value is visibly redacted, restricted or partially masked by the server. */
export function isMaskedValue(value: string): boolean {
  return value === '[redacted]' || value === '[restricted]' || value.includes('***');
}

/** Whole elapsed days/hours since an ISO time, for review-age presentation only. */
export function ageLabel(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return 'Age unknown';
  const then = new Date(iso);
  if (Number.isNaN(then.getTime())) return 'Age unknown';
  const minutes = Math.max(0, Math.floor((now.getTime() - then.getTime()) / 60000));
  if (minutes < 60) return minutes <= 1 ? 'Just now' : `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 48) return `${hours} h`;
  return `${Math.floor(hours / 24)} days`;
}

/** Day and clock parts of an ISO time in Africa/Nairobi (business-day logic, Plan §9). */
export function nairobiParts(iso: string | null | undefined): { day: string; time: string } | null {
  if (!iso) return null;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  const day = new Intl.DateTimeFormat('en-KE', { timeZone: 'Africa/Nairobi', day: 'numeric', month: 'short' }).format(date);
  const time = new Intl.DateTimeFormat('en-KE', { timeZone: 'Africa/Nairobi', hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
  return { day, time };
}

/** Short, non-secret reference for copy/quote: the event ULID's last eight characters. */
export function shortReference(id: string): string {
  return id.length > 8 ? `…${id.slice(-8)}` : id;
}
