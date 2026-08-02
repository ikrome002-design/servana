<script setup lang="ts" generic="TRow extends Record<string, unknown>">
/**
 * SvResponsiveRecordList — the mobile data presentation (Phase UI-04; UI/UX plan §13.3, §13.6).
 *
 * The plan is explicit: on mobile, tables become LABELLED RECORD CARDS. Not a squeezed table, not
 * a horizontally scrolling one — cards where every value keeps its field label, because a bare
 * column of numbers with the headers scrolled off-screen is unreadable.
 *
 * Reads the same `SvColumn[]` as `SvDataTable`, so a screen defines its data once.
 *
 * Two properties carry the weight:
 *  - **Nothing is lost.** `detail`-priority columns move behind a per-card disclosure, never out
 *    of the DOM. Everything the table showed is still reachable.
 *  - **Every value keeps its label.** Each card is a `<dl>` of label/value pairs, which is exactly
 *    the semantic a table cell has in its column — preserved, not approximated.
 *
 * Cards never scroll horizontally: each is a normal block, so the page cannot overflow sideways.
 */
import { computed, ref } from 'vue';
import SvErrorState from '@/components/ui/SvErrorState.vue';
import SvEmptyState from '@/components/ui/SvEmptyState.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import {
  detailColumns,
  faceColumns,
  type SvColumn,
  type SvDataState,
} from '@/components/ui/dataContract';
import { SvIconChevronDown } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    columns: SvColumn<TRow>[];
    rows: TRow[];
    rowKey: (row: TRow) => string;
    /** Accessible name for the list. */
    caption: string;
    state?: SvDataState;
    emptyMessage?: string;
    errorMessage?: string;
  }>(),
  {
    state: 'idle',
    emptyMessage: 'No records yet.',
    errorMessage: 'We couldn’t load these records.',
  },
);

const emit = defineEmits<{ retry: [] }>();

/** Which cards have their detail disclosure open. */
const expanded = ref<Set<string>>(new Set());

function toggle(key: string): void {
  const next = new Set(expanded.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  expanded.value = next;
}

const face = computed(() => faceColumns(props.columns));
const details = computed(() => detailColumns(props.columns));
</script>

<template>
  <div data-testid="sv-record-list-root">
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
    <SvSkeleton
      v-else-if="state === 'loading'"
      shape="text"
      :lines="4"
      label="Loading records"
    />

    <ul
      v-else
      :aria-label="caption"
      class="flex flex-col gap-3"
      data-testid="sv-record-list"
    >
      <li
        v-for="row in rows"
        :key="rowKey(row)"
        class="rounded-card border border-sv-border bg-sv-surface-raised p-4"
        :data-testid="`sv-record-${rowKey(row)}`"
      >
        <!--
          A description list: each value keeps the label its column header carried. This is the
          same semantic relationship a table cell has, preserved rather than approximated.
        -->
        <dl class="grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
          <template
            v-for="column in face"
            :key="column.key"
          >
            <dt class="font-medium text-sv-text-muted">
              {{ column.label }}
            </dt>
            <dd
              class="min-w-0 break-words text-sv-text"
              :class="column.align === 'numeric' ? 'sv-numeric text-right' : ''"
            >
              <slot
                :name="`cell:${column.key}`"
                :row="row"
              >
                {{ column.value ? column.value(row) : '' }}
              </slot>
            </dd>
          </template>

          <!-- Detail columns: hidden behind a disclosure, never removed. -->
          <template v-if="expanded.has(rowKey(row))">
            <template
              v-for="column in details"
              :key="column.key"
            >
              <dt class="font-medium text-sv-text-muted">
                {{ column.label }}
              </dt>
              <dd
                class="min-w-0 break-words text-sv-text"
                :class="column.align === 'numeric' ? 'sv-numeric text-right' : ''"
              >
                <slot
                  :name="`cell:${column.key}`"
                  :row="row"
                >
                  {{ column.value ? column.value(row) : '' }}
                </slot>
              </dd>
            </template>
          </template>
        </dl>

        <button
          v-if="details.length > 0"
          type="button"
          :aria-expanded="expanded.has(rowKey(row))"
          :aria-controls="`sv-record-${rowKey(row)}`"
          class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center gap-1 rounded-control text-sm font-medium text-sv-link"
          :data-testid="`sv-record-toggle-${rowKey(row)}`"
          @click="toggle(rowKey(row))"
        >
          {{ expanded.has(rowKey(row)) ? 'Hide details' : `Show ${details.length} more details` }}
          <SvIconChevronDown
            aria-hidden="true"
            class="h-4 w-4 shrink-0 transition-transform duration-sv-fast motion-reduce:transition-none"
            :class="expanded.has(rowKey(row)) ? 'rotate-180' : ''"
          />
        </button>

        <div
          v-if="$slots.actions"
          class="mt-3 flex flex-wrap gap-2 border-t border-sv-border pt-3"
        >
          <slot
            name="actions"
            :row="row"
          />
        </div>
      </li>
    </ul>
  </div>
</template>
