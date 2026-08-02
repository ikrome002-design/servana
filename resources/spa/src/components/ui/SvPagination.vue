<script setup lang="ts">
/**
 * SvPagination — page navigation for a collection (Phase UI-04; Plan §11 pagination rule).
 *
 * It reports what the SERVER said and nothing more. `total` and `lastPage` come from the API's
 * meta; the component never estimates a total it was not given, because a fabricated count is a
 * false claim about how much data exists.
 *
 * When the total is genuinely unknown (a cursor-paginated endpoint), pass `total: null`: the
 * component then offers previous/next without inventing a page count.
 *
 * Focus behaviour is deliberate and documented: after a page change focus stays on the control
 * the user pressed, so repeated paging works without hunting for the button again. The caller
 * announces the new range through the list's own live region.
 *
 * The caller owns the query and the URL. This emits an intent.
 */
import { computed } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { SvIconChevronLeft, SvIconChevronRight } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    currentPage: number;
    /** Last page, from the server's meta. */
    lastPage: number;
    /** Total records, or null when the endpoint does not report one. Never estimated. */
    total?: number | null;
    perPage?: number | null;
    /** Accessible name; distinguishes multiple paginators on one page. */
    label?: string;
    disabled?: boolean;
  }>(),
  { total: null, perPage: null, label: 'Pagination', disabled: false },
);

const emit = defineEmits<{ change: [page: number] }>();

const canGoPrevious = computed(() => !props.disabled && props.currentPage > 1);
const canGoNext = computed(() => !props.disabled && props.currentPage < props.lastPage);

/** Only stated when the server supplied the numbers. */
const rangeLabel = computed(() => {
  if (props.total === null || props.perPage === null) {
    return `Page ${props.currentPage} of ${props.lastPage}`;
  }
  const first = (props.currentPage - 1) * props.perPage + 1;
  const last = Math.min(props.currentPage * props.perPage, props.total);

  return `${first}–${last} of ${props.total}`;
});

function go(page: number): void {
  if (page < 1 || page > props.lastPage || page === props.currentPage) {
    return;
  }
  emit('change', page);
}
</script>

<template>
  <nav
    :aria-label="label"
    class="flex flex-wrap items-center justify-between gap-3"
    data-testid="sv-pagination"
  >
    <p
      class="text-sm text-sv-text-muted"
      data-testid="sv-pagination-range"
    >
      {{ rangeLabel }}
    </p>

    <div class="flex items-center gap-2">
      <SvIconButton
        :icon="SvIconChevronLeft"
        label="Previous page"
        :disabled="!canGoPrevious"
        size="md"
        variant="subtle"
        data-testid="sv-pagination-previous"
        @click="go(currentPage - 1)"
      />
      <!-- Compact on mobile, spelled out from tablet up. CSS only. -->
      <span class="text-sm text-sv-text">
        <span class="hidden md:inline">Page </span>{{ currentPage }}<span class="hidden md:inline"> of </span><span class="md:hidden">/</span>{{ lastPage }}
      </span>
      <SvIconButton
        :icon="SvIconChevronRight"
        label="Next page"
        :disabled="!canGoNext"
        size="md"
        variant="subtle"
        data-testid="sv-pagination-next"
        @click="go(currentPage + 1)"
      />
    </div>
  </nav>
</template>
