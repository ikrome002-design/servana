<script setup lang="ts">
/**
 * SvStatTile — a compact, toned fact tile (Phase UI-14).
 *
 * The UI-13 Front Office flow strip proved that a few tinted tiles read faster than identical
 * bordered rectangles. This lifts that pattern into the shared library so every role can use it.
 *
 * Like `SvMetricCard` it is presentation only: the value arrives in the default slot already
 * formatted by the caller (`formatMoney`, `SvDateTime`, a server count). `tone` is decorative
 * emphasis and never the only carrier of meaning — the label is always rendered as text.
 */
withDefaults(
  defineProps<{
    label: string;
    hint?: string;
    tone?: 'brand' | 'teal' | 'green' | 'sun' | 'clay' | 'neutral';
    /** Larger value type for the one figure a section is built around. */
    emphasis?: boolean;
  }>(),
  { hint: undefined, tone: 'neutral', emphasis: false },
);
</script>

<template>
  <div
    class="sv-stat-tile relative min-w-0 overflow-hidden rounded-card border p-4"
    :data-tone="tone"
    data-testid="sv-stat-tile"
  >
    <div class="flex items-center gap-2">
      <span
        v-if="$slots.icon"
        aria-hidden="true"
        class="sv-stat-tile__icon inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control"
      ><slot name="icon" /></span>
      <p class="min-w-0 text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
        {{ label }}
      </p>
    </div>
    <p
      class="mt-3 break-words font-display font-extrabold leading-tight text-sv-text-heading"
      :class="emphasis ? 'text-3xl' : 'text-2xl'"
      data-testid="sv-stat-value"
    >
      <slot />
    </p>
    <p
      v-if="hint"
      class="mt-1 text-xs leading-5 text-sv-text-muted"
    >
      {{ hint }}
    </p>
  </div>
</template>

<style scoped>
.sv-stat-tile {
  --sv-stat: var(--sv-color-border-strong);
  border-color: color-mix(in srgb, var(--sv-stat) 34%, var(--sv-color-border-default));
  background: linear-gradient(160deg, color-mix(in srgb, var(--sv-stat) 11%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 72%);
  transition: transform var(--sv-motion-duration-fast) var(--sv-motion-ease-standard), box-shadow var(--sv-motion-duration-fast) var(--sv-motion-ease-standard);
}
.sv-stat-tile:hover { box-shadow: var(--sv-shadow-card); }
.sv-stat-tile[data-tone='brand'] { --sv-stat: var(--sv-color-brand-primary); }
.sv-stat-tile[data-tone='teal'] { --sv-stat: var(--sv-color-brand-secondary); }
.sv-stat-tile[data-tone='green'] { --sv-stat: var(--sv-color-growth); }
.sv-stat-tile[data-tone='sun'] { --sv-stat: var(--sv-color-accent); }
.sv-stat-tile[data-tone='clay'] { --sv-stat: var(--sv-color-status-error-border); }
.sv-stat-tile__icon {
  background: color-mix(in srgb, var(--sv-stat) 18%, transparent);
  color: var(--sv-color-text-heading);
}
@media (prefers-reduced-motion: reduce) {
  .sv-stat-tile { transition: none; }
}
</style>
