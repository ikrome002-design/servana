import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import type {
  SearchDocumentType,
  SearchFailure,
  SearchParams,
  SearchResult,
  SearchSortToken,
} from '@/stores/searchScope';

/**
 * Global search (Plan §68; Phase 22). UX state only — the API is the boundary.
 *
 * FOUR PROPERTIES THIS STORE GUARANTEES, each covered by a test:
 *
 *  1. It sends ONLY `q`, `types`, `branch_ulids`, `sort` and `limit`. There is no code
 *     path that can add a tenant, branch, staff-profile, permission, role, engine or
 *     export parameter — the request object is built field by field from
 *     {@link SearchParams}, never spread from arbitrary input.
 *  2. It NEVER persists a result. Nothing is written to localStorage or sessionStorage,
 *     because a search result set is tenant-scoped data and browser storage outlives
 *     the session that was allowed to see it (Plan §68).
 *  3. It CLEARS on any context change. A membership or branch-scope change invalidates
 *     every held result, so a result fetched under the old scope can never be rendered
 *     under the new one.
 *  4. It holds NO contact data, because the API returns none (decision D-22-03) — there
 *     is no phone or email field anywhere in {@link SearchResult}.
 *
 * `meta.types` is deliberately not consumed: the generated contract types it
 * imprecisely (the OpenAPI generator unwraps arrays in `meta`), and the UI groups by
 * each result's own `type`, which is typed exactly.
 */
export const useSearchStore = defineStore('search', () => {
  const results = ref<SearchResult[]>([]);
  const query = ref('');
  const loading = ref(false);
  const failure = ref<SearchFailure>('none');
  const hasSearched = ref(false);

  /** Sequence guard: a slow earlier response must never overwrite a newer one. */
  let requestSequence = 0;

  const isEmpty = computed(
    () => hasSearched.value && !loading.value && failure.value === 'none' && results.value.length === 0,
  );

  /** Results grouped by type, in the order the server returned them (catalogue order). */
  const groupedResults = computed<Array<{ type: string; label: string; items: SearchResult[] }>>(() => {
    const groups: Array<{ type: string; label: string; items: SearchResult[] }> = [];

    for (const item of results.value) {
      const existing = groups.find((group) => group.type === item.type);

      if (existing === undefined) {
        groups.push({ type: item.type, label: item.type_label, items: [item] });
        continue;
      }

      existing.items.push(item);
    }

    return groups;
  });

  function $reset(): void {
    requestSequence += 1;
    results.value = [];
    query.value = '';
    loading.value = false;
    failure.value = 'none';
    hasSearched.value = false;
  }

  /** Drop held results without forgetting the term the user is still typing. */
  function clearResults(): void {
    requestSequence += 1;
    results.value = [];
    failure.value = 'none';
    hasSearched.value = false;
  }

  async function search(
    term: string,
    options: { types?: SearchDocumentType[]; sort?: SearchSortToken; limit?: number } = {},
  ): Promise<void> {
    query.value = term;

    const trimmed = term.trim();

    // Two characters is the server minimum; below it there is nothing to ask for, and
    // a one-character prefix over a whole branch would be enumeration rather than search.
    if (trimmed.length < 2) {
      clearResults();
      return;
    }

    // Built field by field — never a spread of caller input — so no extra parameter can
    // reach the query string.
    const params: SearchParams = { q: trimmed };

    if (options.types !== undefined && options.types.length > 0) {
      params.types = options.types;
    }

    if (options.sort !== undefined) {
      params.sort = options.sort;
    }

    if (options.limit !== undefined) {
      params.limit = options.limit;
    }

    const sequence = ++requestSequence;
    loading.value = true;
    failure.value = 'none';

    try {
      const { data } = await apiClient.get<{ data: SearchResult[] }>('/search', { params });

      if (sequence !== requestSequence) {
        return; // superseded by a newer query
      }

      results.value = data.data;
      hasSearched.value = true;
    } catch (error: unknown) {
      if (sequence !== requestSequence) {
        return;
      }

      results.value = [];
      hasSearched.value = true;
      failure.value = classify(error);
    } finally {
      if (sequence === requestSequence) {
        loading.value = false;
      }
    }
  }

  function classify(error: unknown): SearchFailure {
    if (!axios.isAxiosError(error) || error.response === undefined) {
      return 'error';
    }

    if (error.response.status === 429) {
      return 'rate_limited';
    }

    if (error.response.status === 403) {
      return 'forbidden';
    }

    return 'error';
  }

  // Property 3: any context change invalidates every held result. Watching the
  // membership id AND the branch list covers both a membership switch and a
  // branch-assignment change within the same merchant.
  const auth = useAuthStore();

  watch(
    () => [auth.activeMembership?.id ?? null, [...auth.branchIds].sort().join(',')],
    () => {
      clearResults();
    },
  );

  return {
    results,
    query,
    loading,
    failure,
    hasSearched,
    isEmpty,
    groupedResults,
    search,
    clearResults,
    $reset,
  };
});
