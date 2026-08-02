<script setup lang="ts">
/**
 * SvMoney — display a monetary amount (Phase UI-04; CLAUDE.md guardrail 6; Plan §2 AS-3).
 *
 * The rules this component exists to make unbreakable:
 *
 *  1. **Integer minor units only.** The prop is `number` minor units and is validated as an
 *     integer. There is no float path, and no arithmetic happens here — formatting only.
 *  2. **Unavailable is NOT zero.** `null`/`undefined` renders a neutral placeholder, never
 *     `KES 0.00`. This is the direct lesson of `UI01-RENDER-001`: a screen that coerces missing
 *     money to zero reports a false financial fact, which is worse than the TypeError it replaces.
 *  3. **The currency code is preserved**, never assumed away.
 *  4. **Tabular numerals** so a column of amounts aligns (UI/UX plan §9.3).
 *
 * The backend remains the sole authority on every amount; this only renders what it was given.
 */
import { computed } from 'vue';
import { formatMoney } from '@/utils/money';

const props = withDefaults(
  defineProps<{
    /**
     * The amount in INTEGER minor units, or null/undefined when the server did not supply one.
     * Null is rendered as "not available", never as zero.
     */
    minorUnits?: number | null;
    currency?: string;
    /** Pre-formatted string from the API, preferred when present so the server's format wins. */
    formatted?: string | null;
    /** What to show when the amount is genuinely unavailable. Never a number. */
    unavailableLabel?: string;
    /** Render a negative amount in the error colour AND with an explicit sign (never colour alone). */
    signed?: boolean;
    size?: 'sm' | 'md' | 'lg';
  }>(),
  {
    minorUnits: null,
    currency: 'KES',
    formatted: null,
    unavailableLabel: 'Not available',
    signed: false,
    size: 'md',
  },
);

const isAvailable = computed(
  () =>
    (typeof props.formatted === 'string' && props.formatted !== '')
    || (typeof props.minorUnits === 'number' && Number.isFinite(props.minorUnits)),
);

/**
 * A non-integer minor-unit value means someone did float arithmetic upstream. Rendering it would
 * hide the defect, so it is surfaced as unavailable rather than silently rounded.
 */
const isIntegerMinorUnits = computed(
  () => props.minorUnits === null || Number.isInteger(props.minorUnits),
);

const display = computed(() => {
  if (!isAvailable.value || !isIntegerMinorUnits.value) {
    return props.unavailableLabel;
  }
  if (typeof props.formatted === 'string' && props.formatted !== '') {
    return props.formatted;
  }

  return formatMoney(props.minorUnits as number, props.currency);
});

const isNegative = computed(
  () => typeof props.minorUnits === 'number' && props.minorUnits < 0,
);
</script>

<template>
  <span
    class="sv-numeric whitespace-nowrap"
    :class="[
      { 'text-xs': size === 'sm', 'text-sm': size === 'md', 'text-base font-semibold': size === 'lg' },
      !isAvailable || !isIntegerMinorUnits ? 'text-sv-text-muted italic' : '',
      signed && isNegative ? 'text-sv-error-fg' : '',
    ]"
    :data-available="isAvailable && isIntegerMinorUnits"
    data-testid="sv-money"
  >{{ display }}</span>
</template>
