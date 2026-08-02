<script setup lang="ts">
/**
 * SvFilterBar — the filter region above a list (Phase UI-04; UI/UX plan §13.3).
 *
 * A LAYOUT and disclosure shell. It holds no business knowledge: it does not know what a filter
 * means, does not build a query, and does not touch the URL. The caller supplies controls in the
 * default slot and owns the model — which is what keeps one shared component usable by finance,
 * audit and front office without acquiring each one's query semantics.
 *
 * Responsive behaviour is CSS: on mobile the filters collapse behind a disclosure so they do not
 * consume the screen above the results; from tablet up they are always visible. The active-filter
 * count is shown on the trigger, so a user with a collapsed panel can still see that a filter is
 * narrowing their results — a hidden active filter is a common source of "where did my data go".
 */
import { ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconFilter } from '@/design-system/icons';

withDefaults(
  defineProps<{
    /** Accessible name for the filter region. */
    label?: string;
    /** How many filters are currently narrowing the results. Counted by the CALLER. */
    activeCount?: number;
    /** Show the clear-all control. */
    clearable?: boolean;
  }>(),
  { label: 'Filters', activeCount: 0, clearable: true },
);

const emit = defineEmits<{ clear: [] }>();

const expanded = ref(false);
const panelId = 'sv-filter-bar-panel';
</script>

<template>
  <section
    :aria-label="label"
    class="mb-4"
    data-testid="sv-filter-bar"
  >
    <!-- Mobile disclosure trigger. Hidden from tablet up, where the panel is always shown. -->
    <div class="flex items-center justify-between gap-3 md:hidden">
      <button
        type="button"
        :aria-expanded="expanded"
        :aria-controls="panelId"
        class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control border border-sv-border-input px-3 py-2 text-sm font-medium text-sv-text"
        data-testid="sv-filter-bar-toggle"
        @click="expanded = !expanded"
      >
        <SvIconFilter
          aria-hidden="true"
          class="h-5 w-5"
        />
        {{ label }}
        <!-- The count is TEXT, so an active filter is visible even with the panel collapsed. -->
        <span
          v-if="activeCount > 0"
          class="rounded-pill bg-sv-brand px-2 py-0.5 text-xs font-semibold text-sv-text-on-brand"
        >{{ activeCount }} active</span>
      </button>

      <SvButton
        v-if="clearable && activeCount > 0"
        variant="ghost"
        size="sm"
        @click="emit('clear')"
      >
        Clear all
      </SvButton>
    </div>

    <div
      :id="panelId"
      class="mt-3 gap-3 md:mt-0 md:flex md:flex-wrap md:items-end"
      :class="expanded ? 'flex flex-col' : 'hidden md:flex'"
      data-testid="sv-filter-bar-panel"
    >
      <slot />

      <SvButton
        v-if="clearable && activeCount > 0"
        variant="ghost"
        size="sm"
        class="hidden md:inline-flex"
        @click="emit('clear')"
      >
        Clear all
      </SvButton>
    </div>
  </section>
</template>
