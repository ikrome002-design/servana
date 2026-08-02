<script setup lang="ts">
/**
 * SvCard — a grouped content surface (Phase UI-04; UI/UX plan §9.5, §10).
 *
 * A card is a CONTAINER. It is deliberately not clickable: a whole-card click target swallows the
 * links and buttons inside it, produces nested interactive elements, and gives assistive
 * technology no name for what activating it would do. Where a record needs to be openable, the
 * card holds an explicit link or `SvResponsiveRecordList` handles it — one named affordance
 * instead of an ambiguous region.
 *
 * `as` allows `section`/`article`/`li` so the card can be semantically correct in a list or a
 * landmark region, rather than always being a `div`.
 */
withDefaults(
  defineProps<{
    /** Element to render. Use `section`/`article`/`li` where the surrounding semantics need it. */
    as?: string;
    padding?: 'none' | 'sm' | 'md' | 'lg';
    /** Raise the surface for an overlay-adjacent context. */
    elevation?: 'flat' | 'card' | 'raised';
  }>(),
  { as: 'div', padding: 'md', elevation: 'card' },
);
</script>

<template>
  <component
    :is="as"
    class="rounded-card border border-sv-border bg-sv-surface-raised"
    :class="[
      { 'p-0': padding === 'none', 'p-4': padding === 'sm', 'p-6': padding === 'md', 'p-8': padding === 'lg' },
      { 'shadow-none': elevation === 'flat', 'shadow-card': elevation === 'card', 'shadow-raised': elevation === 'raised' },
    ]"
    data-testid="sv-card"
  >
    <div
      v-if="$slots.header || $slots.actions"
      class="mb-4 flex flex-wrap items-start justify-between gap-3"
    >
      <div class="min-w-0">
        <slot name="header" />
      </div>
      <div
        v-if="$slots.actions"
        class="shrink-0"
      >
        <slot name="actions" />
      </div>
    </div>

    <slot />

    <div
      v-if="$slots.footer"
      class="mt-4 border-t border-sv-border pt-4"
    >
      <slot name="footer" />
    </div>
  </component>
</template>
