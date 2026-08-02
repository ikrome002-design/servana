<script setup lang="ts">
/**
 * SvDateTime — display a timestamp or a business date (Phase UI-04; Plan §2 AS-3).
 *
 * Servana stores timestamps in UTC and expresses business-day logic in `Africa/Nairobi`. This
 * component uses the repository's existing formatters, which pin that zone explicitly — it never
 * infers a timezone from the browser, because a merchant's payout period must not shift because
 * their laptop is set to another region.
 *
 * A DATE-ONLY business value (`2026-07-31`) and a TIMESTAMP (`2026-07-31T21:00:00Z`) are
 * different things and are rendered differently: adding a time to a date-only value would invent
 * precision the record does not have.
 *
 * A missing value renders a neutral placeholder, never "now" and never the epoch.
 */
import { computed } from 'vue';
import { formatDate, formatDateTime } from '@/utils/dates';

const props = withDefaults(
  defineProps<{
    /** An ISO-8601 timestamp, or an ISO date (YYYY-MM-DD) when `mode` is `date`. */
    value?: string | null;
    /** `datetime` renders date + time in Africa/Nairobi; `date` renders a business date only. */
    mode?: 'datetime' | 'date';
    unavailableLabel?: string;
  }>(),
  { value: null, mode: 'datetime', unavailableLabel: 'Not available' },
);

const isAvailable = computed(() => {
  if (typeof props.value !== 'string' || props.value === '') {
    return false;
  }

  return !Number.isNaN(new Date(props.value).getTime());
});

const display = computed(() => {
  if (!isAvailable.value) {
    return props.unavailableLabel;
  }
  const raw = props.value as string;

  return props.mode === 'date' ? formatDate(raw) : formatDateTime(raw);
});

/**
 * The machine-readable value for `<time datetime="…">`. For a date-only business value the
 * date portion alone is emitted, so assistive technology is not told there is a time.
 */
const machineValue = computed(() => {
  if (!isAvailable.value) {
    return undefined;
  }
  const raw = props.value as string;

  return props.mode === 'date' ? raw.slice(0, 10) : raw;
});
</script>

<template>
  <time
    :datetime="machineValue"
    class="whitespace-nowrap"
    :class="isAvailable ? '' : 'italic text-sv-text-muted'"
    :data-available="isAvailable"
    data-testid="sv-datetime"
  >{{ display }}</time>
</template>
