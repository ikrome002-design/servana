<script setup lang="ts">
/**
 * SvBreadcrumbs — ancestor navigation (Phase UI-04; UI/UX plan §7).
 *
 * A `nav` landmark with an accessible name, containing an ORDERED list, because the sequence is
 * the meaning. The current page carries `aria-current="page"` and is rendered as plain text, not
 * a link — a link to the page you are already on is a dead affordance.
 *
 * Separators are Heroicons hidden from assistive technology; a screen reader gets the list
 * structure, which conveys the hierarchy without "chevron chevron chevron".
 *
 * On mobile the middle of a long trail collapses, but the collapsed items remain in the DOM for
 * assistive technology — truncation is a VISUAL economy, never a loss of accessible information.
 */
import { computed } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { SvIconChevronRight } from '@/design-system/icons';

export interface SvBreadcrumb {
  label: string;
  /** Omitted for the current page, which is never a link. */
  to?: RouteLocationRaw;
}

const props = withDefaults(
  defineProps<{
    items: SvBreadcrumb[];
    label?: string;
    /** Collapse the middle on mobile when the trail is longer than this. */
    collapseAfter?: number;
  }>(),
  { label: 'Breadcrumb', collapseAfter: 3 },
);

/** Indices hidden on mobile only: everything except the first and last two. */
const collapsedIndices = computed(() => {
  if (props.items.length <= props.collapseAfter) {
    return new Set<number>();
  }

  return new Set(
    props.items.map((_, index) => index).filter((index) => index !== 0 && index < props.items.length - 2),
  );
});
</script>

<template>
  <nav
    :aria-label="label"
    data-testid="sv-breadcrumbs"
  >
    <ol class="flex flex-wrap items-center gap-1 text-sm">
      <li
        v-for="(item, index) in items"
        :key="`${item.label}-${index}`"
        class="flex items-center gap-1"
        :class="collapsedIndices.has(index) ? 'hidden md:flex' : ''"
      >
        <SvIconChevronRight
          v-if="index > 0"
          aria-hidden="true"
          class="h-4 w-4 shrink-0 text-sv-text-muted"
        />

        <RouterLink
          v-if="item.to !== undefined"
          :to="item.to"
          class="sv-focus-ring rounded-control text-sv-link hover:text-sv-link-hover hover:underline"
        >
          {{ item.label }}
        </RouterLink>
        <!-- Current page: text, plus aria-current so its role in the trail is explicit. -->
        <span
          v-else
          aria-current="page"
          class="font-medium text-sv-text"
        >{{ item.label }}</span>
      </li>
    </ol>
  </nav>
</template>
