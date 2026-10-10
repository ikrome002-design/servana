<script setup lang="ts">
/**
 * SvLeadRecord — a record card led by its single most important fact (Phase UI-14).
 *
 * Work and ledger lists read fastest when the deciding fact — a queue position, a start time, a
 * pay period, an amount — sits in a fixed lead block and the rest of the record flows beside it.
 * The lead block is tinted by `tone`; the status is always a text badge in the `status` slot,
 * never the tint alone.
 *
 * It stays a container (like `SvCard`): actions are explicit links/buttons in the `actions` slot,
 * never a whole-card click target. On mobile the lead block shrinks but never scrolls sideways.
 */
withDefaults(
  defineProps<{
    leadValue: string;
    leadLabel?: string;
    tone?: 'brand' | 'teal' | 'green' | 'sun' | 'clay' | 'neutral';
    as?: string;
  }>(),
  { leadLabel: undefined, tone: 'brand', as: 'article' },
);
</script>

<template>
  <component
    :is="as"
    class="sv-lead-record grid grid-cols-[auto_minmax(0,1fr)] gap-4 rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-card md:p-5"
    :data-tone="tone"
    data-testid="sv-lead-record"
  >
    <div class="sv-lead-record__lead flex min-w-[4.5rem] flex-col items-center justify-center rounded-control px-3 py-3 text-center md:min-w-[5.5rem]">
      <span class="font-display text-xl font-extrabold leading-none text-sv-text-heading md:text-2xl">{{ leadValue }}</span>
      <span
        v-if="leadLabel"
        class="mt-1 text-[0.6875rem] font-semibold uppercase tracking-wide text-sv-text-secondary"
      >{{ leadLabel }}</span>
    </div>
    <div class="min-w-0">
      <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
        <div
          class="min-w-0 flex-1"
          data-testid="sv-lead-content"
        >
          <slot />
        </div>
        <div
          v-if="$slots.status"
          class="shrink-0"
          data-testid="sv-lead-status"
        >
          <slot name="status" />
        </div>
      </div>
      <div
        v-if="$slots.meta"
        class="mt-3 text-sm text-sv-text-secondary"
      >
        <slot name="meta" />
      </div>
      <div
        v-if="$slots.actions"
        class="mt-3 flex flex-wrap gap-2"
      >
        <slot name="actions" />
      </div>
    </div>
  </component>
</template>

<style scoped>
.sv-lead-record {
  --sv-lead: var(--sv-color-brand-primary);
  border-left: 4px solid color-mix(in srgb, var(--sv-lead) 70%, var(--sv-color-border-default));
}
.sv-lead-record[data-tone='teal'] { --sv-lead: var(--sv-color-brand-secondary); }
.sv-lead-record[data-tone='green'] { --sv-lead: var(--sv-color-growth); }
.sv-lead-record[data-tone='sun'] { --sv-lead: var(--sv-color-accent); }
.sv-lead-record[data-tone='clay'] { --sv-lead: var(--sv-color-status-error-border); }
.sv-lead-record[data-tone='neutral'] { --sv-lead: var(--sv-color-border-strong); }
.sv-lead-record__lead {
  background: color-mix(in srgb, var(--sv-lead) 13%, var(--sv-color-surface-raised));
}
</style>
