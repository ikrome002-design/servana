<script setup lang="ts">
/**
 * SvMetricCard — a single headline figure (Phase UI-04; UI/UX plan §15.1).
 *
 * A presentation shell only. It computes nothing: the value, the trend and the comparison text
 * all arrive already decided by the server or the caller. A dashboard tile that did its own
 * arithmetic would be a second financial authority, which CLAUDE.md guardrail 6 forbids.
 *
 * Trend is never colour-only — the direction is stated in text for assistive technology and the
 * arrow glyph is a Heroicon, not a character.
 *
 * `loading` and "no value" are distinct: a skeleton says "not yet", the unavailable placeholder
 * says "the server had nothing". Rendering zero for either would be a false figure.
 */
import { computed } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSkeleton from '@/components/ui/SvSkeleton.vue';
import { SvIconArrowDown, SvIconArrowUp } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    label: string;
    /** Supporting sentence, e.g. "vs. previous period". Never a computed claim. */
    context?: string;
    loading?: boolean;
    /** `up` / `down` / `flat`, already determined by the caller. */
    trend?: 'up' | 'down' | 'flat' | null;
    /** Human-readable trend text, e.g. "12% higher than last month". */
    trendLabel?: string;
    /**
     * Whether an increase is GOOD. Revenue up is good; refunds up is not. Without this the
     * component would have to guess, and it would guess wrong half the time.
     */
    increaseIsPositive?: boolean;
  }>(),
  {
    context: undefined,
    loading: false,
    trend: null,
    trendLabel: undefined,
    increaseIsPositive: true,
  },
);

const trendTone = computed(() => {
  if (props.trend === null || props.trend === 'flat') {
    return 'neutral';
  }
  const isGood = props.trend === 'up' ? props.increaseIsPositive : !props.increaseIsPositive;

  return isGood ? 'positive' : 'negative';
});
</script>

<template>
  <SvCard padding="md">
    <p class="text-sm font-medium text-sv-text-muted">
      {{ label }}
    </p>

    <SvSkeleton
      v-if="loading"
      class="mt-2 h-8 w-32"
      label="Loading metric"
    />
    <p
      v-else
      class="mt-2 font-display text-2xl font-bold text-sv-text-heading"
      data-testid="sv-metric-value"
    >
      <!-- The caller supplies SvMoney / SvDateTime / plain text; this never formats a figure. -->
      <slot />
    </p>

    <div
      v-if="!loading && trend !== null"
      class="mt-2 flex items-center gap-1 text-sm"
      :class="{
        'text-sv-text-muted': trendTone === 'neutral',
        'text-sv-success-fg': trendTone === 'positive',
        'text-sv-error-fg': trendTone === 'negative',
      }"
      data-testid="sv-metric-trend"
    >
      <component
        :is="trend === 'up' ? SvIconArrowUp : SvIconArrowDown"
        v-if="trend !== 'flat'"
        aria-hidden="true"
        class="h-4 w-4 shrink-0"
      />
      <!-- Text, so the direction survives colour blindness, monochrome and screen readers. -->
      <span>{{ trendLabel ?? (trend === 'up' ? 'Increased' : trend === 'down' ? 'Decreased' : 'Unchanged') }}</span>
    </div>

    <p
      v-if="context"
      class="mt-1 text-xs text-sv-text-muted"
    >
      {{ context }}
    </p>
  </SvCard>
</template>
