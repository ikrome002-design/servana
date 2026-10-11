import { computed } from 'vue';
import { useRoute, useRouter, type LocationQuery } from 'vue-router';
import type { AuditEventFilters } from '@/stores/auditEventStore';

/**
 * URL-held audit filters (Phase UI-15; Appendix A §12.4.x "preserve filters, sort order,
 * pagination … when the user returns to the page").
 *
 * The query string is the single source of filter state, so opening an event and coming back,
 * reloading, or sharing the link all reproduce the same server query. Only the backend-allowlisted
 * keys are read; anything else in the URL is ignored. This is presentation state, never authority:
 * the server re-applies branch scope and permission to whatever is asked for.
 */
const KEYS = ['severity', 'date_from', 'date_to', 'sort', 'flagged', 'correlation_id', 'action'] as const;
type FilterKey = (typeof KEYS)[number];

const FLAGGED = new Set(['flagged', 'unflagged']);
const SEVERITIES = new Set(['info', 'notice', 'warning', 'high', 'critical']);
const SORTS = new Set(['-created_at', 'created_at', '-severity', 'severity', 'action', '-action']);
const DATE = /^\d{4}-\d{2}-\d{2}$/;

function single(query: LocationQuery, key: string): string {
  const value = query[key];
  return typeof value === 'string' ? value : '';
}

export function filtersFromQuery(query: LocationQuery): AuditEventFilters & { page: number } {
  const severity = single(query, 'severity');
  const flagged = single(query, 'flagged');
  const sort = single(query, 'sort');
  const from = single(query, 'date_from');
  const to = single(query, 'date_to');
  const correlation = single(query, 'correlation_id').slice(0, 64);
  const action = single(query, 'action').slice(0, 120);
  const page = Number.parseInt(single(query, 'page'), 10);

  return {
    severity: SEVERITIES.has(severity) ? severity : '',
    flagged: FLAGGED.has(flagged) ? (flagged as 'flagged' | 'unflagged') : '',
    sort: SORTS.has(sort) ? sort : '-created_at',
    date_from: DATE.test(from) ? from : '',
    date_to: DATE.test(to) ? to : '',
    correlation_id: correlation,
    action,
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

export function useAuditRouteFilters() {
  const route = useRoute();
  const router = useRouter();

  const filters = computed(() => filtersFromQuery(route.query));

  const activeCount = computed(() => KEYS.filter((key) => key !== 'sort' && filters.value[key] !== '').length);

  function apply(next: Partial<Record<FilterKey, string>>, page = 1): void {
    const merged: Record<string, string> = {};
    for (const key of KEYS) {
      const value = key in next ? next[key] : filters.value[key];
      if (typeof value === 'string' && value !== '' && !(key === 'sort' && value === '-created_at')) merged[key] = value;
    }
    if (page > 1) merged.page = String(page);
    void router.replace({ query: merged });
  }

  function clear(): void {
    void router.replace({ query: {} });
  }

  function goToPage(page: number): void {
    apply({}, page);
  }

  return { filters, activeCount, apply, clear, goToPage };
}

/** Safe in-app return path: a same-origin absolute path, never a protocol-relative or full URL. */
export function safeReturnPath(value: unknown, fallback: string): string {
  if (typeof value !== 'string') return fallback;
  if (!value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return fallback;
  return value;
}
