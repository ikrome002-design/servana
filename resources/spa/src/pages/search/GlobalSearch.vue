<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useSearchStore } from '@/stores/searchStore';
import type { SearchResult } from '@/stores/searchScope';

/**
 * Global search (Plan §68; Phase 22) — the Phase 22 search surface.
 *
 * Neither a layout search slot nor a planned search screen existed at Phase 22 entry, so this is the
 * smallest route that matches the current UI architecture, reachable from every merchant-side role's
 * navigation.
 *
 * What this screen deliberately does NOT have (ADR-010; Plan §19.4 non-overridable):
 * no export, download, print or clipboard-copy control; no full phone or email anywhere (the API
 * returns none — decision D-22-03); nothing persisted to localStorage or sessionStorage; and no
 * contact value in the URL (only the term the user typed travels, and a phone-like term is redacted
 * out of the response the server echoes back).
 *
 * The result list is entirely server-authorized: every row shown is a row whose own detail-route
 * policy already passed. A role with no searchable authority simply sees the empty state.
 */
const search = useSearchStore();
const term = ref('');

let debounce: ReturnType<typeof setTimeout> | undefined;

const state = computed<'idle' | 'loading' | 'empty' | 'rate_limited' | 'forbidden' | 'error' | 'results'>(() => {
  if (search.loading) return 'loading';
  if (search.failure === 'rate_limited') return 'rate_limited';
  if (search.failure === 'forbidden') return 'forbidden';
  if (search.failure === 'error') return 'error';
  if (search.isEmpty) return 'empty';
  if (search.results.length > 0) return 'results';
  return 'idle';
});

function submit(): void {
  window.clearTimeout(debounce);
  void search.search(term.value);
}

function onInput(): void {
  window.clearTimeout(debounce);

  // Debounced so typing does not burn the 60/min allowance in a few seconds.
  debounce = setTimeout(() => {
    void search.search(term.value);
  }, 350);
}

function clear(): void {
  window.clearTimeout(debounce);
  term.value = '';
  search.clearResults();
  document.getElementById('search-q')?.focus();
}

/** A result links to its target route only when the server supplied one. */
function target(result: SearchResult): { name: string; params?: Record<string, string> } {
  return result.route.id === null
    ? { name: result.route.name }
    : { name: result.route.name, params: { id: result.route.id } };
}

onBeforeUnmount(() => {
  window.clearTimeout(debounce);
});
</script>

<template>
  <section class="mx-auto max-w-3xl p-4 md:p-6">
    <RouterLink
      class="text-sm text-brand underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
      :to="{ name: 'home' }"
    >
      Back
    </RouterLink>

    <h1 class="mt-3 font-display text-2xl font-bold text-heading">
      Search
    </h1>
    <p class="mt-1 text-sm text-body">
      Search across the records you already have access to. Results are limited to your branch.
    </p>

    <form
      class="mt-4 flex items-end gap-2"
      novalidate
      role="search"
      @submit.prevent="submit"
    >
      <div class="grow">
        <SvTextInput
          id="search-q"
          v-model="term"
          label="Search"
          placeholder="Name, reference, invoice or receipt number"
          type="search"
          @input="onInput"
        />
      </div>
      <SvButton
        data-testid="search-submit"
        type="submit"
        variant="primary"
      >
        Search
      </SvButton>
      <SvButton
        data-testid="search-clear"
        type="button"
        variant="secondary"
        @click="clear"
      >
        Clear
      </SvButton>
    </form>

    <!-- One live region for every outcome, so a screen reader is told what happened. -->
    <div
      aria-live="polite"
      class="mt-6"
      data-testid="search-status"
      role="status"
    >
      <p
        v-if="state === 'loading'"
        class="text-sm text-body"
      >
        Searching…
      </p>
      <p
        v-else-if="state === 'rate_limited'"
        class="text-sm text-warning-strong"
        data-testid="search-rate-limited"
      >
        Too many searches in a short time. Wait a moment and try again.
      </p>
      <p
        v-else-if="state === 'forbidden'"
        class="text-sm text-warning-strong"
        data-testid="search-forbidden"
      >
        Your access changed while you were searching. Reload the page and try again.
      </p>
      <p
        v-else-if="state === 'error'"
        class="text-sm text-danger-strong"
        data-testid="search-error"
      >
        Search is unavailable right now. Try again shortly.
      </p>
      <p
        v-else-if="state === 'empty'"
        class="text-sm text-body"
        data-testid="search-empty"
      >
        Nothing matched, or you do not have access to records matching that search.
      </p>
      <p
        v-else-if="state === 'idle'"
        class="text-sm text-body"
        data-testid="search-idle"
      >
        Type at least two characters to search.
      </p>
      <p
        v-else
        class="text-sm text-body"
      >
        {{ search.results.length }} result{{ search.results.length === 1 ? '' : 's' }}.
      </p>
    </div>

    <div
      v-if="state === 'results'"
      class="mt-4 space-y-6"
      data-testid="search-results"
    >
      <section
        v-for="group in search.groupedResults"
        :key="group.type"
      >
        <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">
          {{ group.label }}
        </h2>
        <ul class="mt-2 space-y-2">
          <li
            v-for="result in group.items"
            :key="result.ulid"
          >
            <RouterLink
              class="block rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
              :data-testid="`search-result-${result.type}`"
              :to="target(result)"
            >
              <SvCard class="min-h-[44px] p-3 hover:border-brand">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                  <span class="font-medium text-heading">{{ result.title }}</span>
                  <span
                    v-if="result.amount"
                    class="text-sm font-medium text-heading"
                  >{{ result.amount.formatted }}</span>
                </div>
                <p
                  v-if="result.subtitle"
                  class="mt-0.5 text-sm text-body"
                >
                  {{ result.subtitle }}
                </p>
                <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted">
                  <span v-if="result.status">{{ result.status }}</span>
                  <span v-if="result.branch?.name">{{ result.branch.name }}</span>
                  <span v-if="result.date">{{ result.date.slice(0, 10) }}</span>
                </p>
              </SvCard>
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>
