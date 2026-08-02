<script setup lang="ts">
/**
 * SvSkeleton — a loading placeholder (Phase UI-04; UI/UX plan §10).
 *
 * A skeleton is DECORATIVE. It is hidden from assistive technology and the loading fact is
 * announced once, by the region that owns it, through a polite live region. Exposing every
 * shimmering block would flood a screen reader with meaningless nodes.
 *
 * It must never look like data. It renders neutral blocks with no text, so nobody can mistake a
 * placeholder for a real (and especially not a financial) value.
 *
 * Dimensions come from the caller so the placeholder occupies the same box the real content will,
 * which is what stops the page reflowing when data arrives.
 *
 * Animation is suppressed by the global `prefers-reduced-motion` rule in `style.css`.
 */
withDefaults(
  defineProps<{
    /**
     * Announced once, politely, while this region loads. Pass an empty string when an ancestor
     * already announces the loading state, to avoid a duplicate.
     */
    label?: string;
    shape?: 'block' | 'text' | 'circle';
    /** Number of stacked lines for the `text` shape. */
    lines?: number;
  }>(),
  { label: 'Loading', shape: 'block', lines: 1 },
);
</script>

<template>
  <div
    class="w-full"
    data-testid="sv-skeleton"
  >
    <span
      v-if="label !== ''"
      aria-live="polite"
      class="sr-only"
    >{{ label }}</span>

    <div
      aria-hidden="true"
      :class="shape === 'text' ? 'space-y-2' : ''"
    >
      <div
        v-for="line in shape === 'text' ? lines : 1"
        :key="line"
        class="animate-pulse bg-sv-surface-subtle"
        :class="[
          shape === 'circle' ? 'aspect-square rounded-pill' : 'rounded-control',
          shape === 'text' ? 'h-4' : 'h-full min-h-4',
          // The last line of a text block is short, the way a real paragraph ends.
          shape === 'text' && line === lines && lines > 1 ? 'w-2/3' : 'w-full',
        ]"
      />
    </div>
  </div>
</template>
