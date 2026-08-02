<script setup lang="ts">
/**
 * SvStatusBadge — a status indicator (Phase UI-04; UI/UX plan §9.4).
 *
 * Two rules the component enforces structurally rather than by convention:
 *
 *  1. **Status is never colour alone.** The label is always rendered as text. A colour-blind user,
 *     a monochrome print, and a screen reader all get the same information.
 *  2. **An unknown status is reported as unknown.** Falling back to the "success" tone would tell
 *     a user that an unrecognised state is fine, which is a false statement about a record. The
 *     neutral tone is the honest default.
 *
 * The tone vocabulary is closed and token-backed, so a caller cannot introduce an arbitrary
 * status colour.
 */
import { computed } from 'vue';

export type SvStatusTone = 'neutral' | 'success' | 'warning' | 'error' | 'info';

const props = withDefaults(
  defineProps<{
    /** Human-readable status text. ALWAYS rendered — status is never conveyed by colour alone. */
    label: string;
    tone?: SvStatusTone;
    size?: 'sm' | 'md';
    /**
     * Prefix announced to assistive technology, e.g. "Status:". Without it a bare word like
     * "Paid" in a table row can be ambiguous out of context.
     */
    srPrefix?: string;
  }>(),
  { tone: 'neutral', size: 'md', srPrefix: 'Status:' },
);

const KNOWN_TONES: SvStatusTone[] = ['neutral', 'success', 'warning', 'error', 'info'];

/** An unrecognised tone degrades to neutral, never to success. */
const resolvedTone = computed<SvStatusTone>(() =>
  KNOWN_TONES.includes(props.tone) ? props.tone : 'neutral',
);
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-pill border px-2 py-0.5 font-medium"
    :class="[
      size === 'sm' ? 'text-xs' : 'text-sm',
      {
        'border-sv-border bg-sv-surface-subtle text-sv-text-secondary': resolvedTone === 'neutral',
        'border-sv-success-border bg-sv-success-bg text-sv-success-fg': resolvedTone === 'success',
        'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg': resolvedTone === 'warning',
        'border-sv-error-border bg-sv-error-bg text-sv-error-fg': resolvedTone === 'error',
        'border-sv-info-border bg-sv-info-bg text-sv-info-fg': resolvedTone === 'info',
      },
    ]"
    :data-tone="resolvedTone"
    data-testid="sv-status-badge"
  >
    <span class="sr-only">{{ srPrefix }} </span>{{ label }}
  </span>
</template>
