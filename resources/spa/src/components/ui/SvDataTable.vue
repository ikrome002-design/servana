<script setup lang="ts" generic="TRow extends Record<string, unknown>">
/**
 * SvDataTable — the desktop/tablet data presentation (Phase UI-04; UI/UX plan §13.5, §13.6).
 *
 * A SEMANTIC `<table>` with a caption, so a screen reader announces the table's purpose, its
 * dimensions, and the column each cell belongs to. A grid of divs cannot do any of that.
 *
 * Paired with `SvResponsiveRecordList` through one shared column contract: this renders from
 * tablet up, the record list renders on mobile, and both read the same `SvColumn[]`.
 *
 * Deliberate constraints:
 *  - sort controls appear ONLY on columns declared sortable, and carry `aria-sort`;
 *  - `numeric` columns get tabular figures and right alignment so amounts line up;
 *  - the four non-idle states render their own distinct components — a permission refusal is never
 *    shown as an empty table, because "no records" and "you may not see the records" are
 *    different facts;
 *  - horizontal scrolling requires the explicit `scrollable` prop. It is never the silent default,
 *    because a table that quietly scrolls sideways is how page-level overflow reaches production.
 */
import { computed } from 'vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import { ariaSortFor, type SvColumn, type SvDataState, type SvSortState } from '@/components/ui/dataContract';
import { SvIconSort } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    columns: SvColumn<TRow>[];
    rows: TRow[];
    /** Stable identity for each row. Required — index keys break on sort and pagination. */
    rowKey: (row: TRow) => string;
    /** The table's accessible name. Required: "table with 6 columns" alone tells a user nothing. */
    caption: string;
    /** Visually hide the caption while keeping it for assistive technology. */
    captionHidden?: boolean;
    state?: SvDataState;
    sort?: SvSortState | null;
    emptyMessage?: string;
    errorMessage?: string;
    /**
     * Permit horizontal scrolling of the table region. Requires a screen-spec justification
     * (UI/UX plan §13.6) — hence explicit, labelled, and keyboard-scrollable.
     */
    scrollable?: boolean;
  }>(),
  {
    captionHidden: false,
    state: 'idle',
    sort: null,
    emptyMessage: 'No records yet.',
    errorMessage: 'We couldn’t load these records.',
    scrollable: false,
  },
);

const emit = defineEmits<{ sort: [key: string]; retry: [] }>();

const showsTable = computed(() => props.state === 'idle' || props.state === 'loading');

function alignmentClass(column: SvColumn<TRow>): string {
  if (column.align === 'numeric') {
    return 'text-right sv-numeric';
  }

  return column.align === 'end' ? 'text-right' : 'text-left';
}
</script>

<template>
  <div data-testid="sv-data-table-root">
    <!-- Each state is its own component: they are different facts, not variations of one. -->
    <SvPermissionState v-if="state === 'forbidden'" />
    <SvErrorState
      v-else-if="state === 'error'"
      :message="errorMessage"
      @retry="emit('retry')"
    />
    <SvEmptyState
      v-else-if="state === 'empty'"
      :title="emptyMessage"
    />

    <div
      v-else-if="showsTable"
      :class="scrollable ? 'overflow-x-auto' : ''"
      :tabindex="scrollable ? 0 : undefined"
      :role="scrollable ? 'region' : undefined"
      :aria-label="scrollable ? `${caption} (scrollable)` : undefined"
    >
      <table
        class="w-full border-collapse text-sm"
        data-testid="sv-data-table"
      >
        <caption
          class="text-left text-sm text-sv-text-muted"
          :class="captionHidden ? 'sr-only' : 'mb-2'"
        >
          {{ caption }}
        </caption>

        <thead>
          <tr class="bg-sv-table-header">
            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :aria-sort="column.sortable === true ? ariaSortFor(column.key, sort) : undefined"
              class="border-b border-sv-border px-3 py-2 font-semibold text-sv-text"
              :class="alignmentClass(column)"
            >
              <button
                v-if="column.sortable === true"
                type="button"
                class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-1 rounded-control"
                :data-testid="`sv-sort-${column.key}`"
                @click="emit('sort', column.key)"
              >
                {{ column.label }}
                <SvIconSort
                  aria-hidden="true"
                  class="h-4 w-4 shrink-0 text-sv-text-muted"
                />
              </button>
              <template v-else>
                {{ column.label }}
              </template>
            </th>
            <th
              v-if="$slots.actions"
              scope="col"
              class="border-b border-sv-border px-3 py-2 text-right font-semibold text-sv-text"
            >
              Actions
            </th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="state === 'loading'">
            <td
              :colspan="columns.length + ($slots.actions ? 1 : 0)"
              class="px-3 py-6"
            >
              <SvSkeleton
                shape="text"
                :lines="3"
                label="Loading records"
              />
            </td>
          </tr>

          <tr
            v-for="row in rows"
            v-else
            :key="rowKey(row)"
            class="hover:bg-sv-table-hover"
            :data-testid="`sv-data-row-${rowKey(row)}`"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              class="border-b border-sv-border px-3 py-2 text-sv-text"
              :class="alignmentClass(column)"
            >
              <!-- Rich cells come from a slot; no caller HTML is ever rendered. -->
              <slot
                :name="`cell:${column.key}`"
                :row="row"
              >
                {{ column.value ? column.value(row) : '' }}
              </slot>
            </td>
            <td
              v-if="$slots.actions"
              class="border-b border-sv-border px-3 py-2 text-right"
            >
              <slot
                name="actions"
                :row="row"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
