<script setup lang="ts">
/**
 * SvBanner — a page- or account-level notice (Phase UI-04; UI/UX plan §10).
 *
 * Sits at the top of a surface and stays there: suspended billing, a period lock, a maintenance
 * window. Unlike `SvAlert` it is not attached to one field or card, and unlike `SvToast` it does
 * not disappear on a timer — a condition the user cannot dismiss by waiting must not be announced
 * by something that vanishes.
 *
 * It is a `region` landmark with an accessible name so it can be navigated to deliberately, and
 * it is deliberately NOT a live region: a banner is present when the page loads, and announcing
 * it as a change would be a lie about what just happened.
 */
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { SvIconClose } from '@/design-system/icons';

withDefaults(
  defineProps<{
    severity?: 'info' | 'warning' | 'error';
    /** The landmark's accessible name. Required so the region is identifiable, not just "region". */
    label: string;
    dismissible?: boolean;
  }>(),
  { severity: 'info', dismissible: false },
);

defineEmits<{ dismiss: [] }>();
</script>

<template>
  <section
    role="region"
    :aria-label="label"
    class="flex flex-wrap items-center gap-3 border-b px-4 py-3 md:px-6"
    :class="{
      'border-sv-info-border bg-sv-info-bg text-sv-info-fg': severity === 'info',
      'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg': severity === 'warning',
      'border-sv-error-border bg-sv-error-bg text-sv-error-fg': severity === 'error',
    }"
    :data-severity="severity"
    data-testid="sv-banner"
  >
    <div class="min-w-0 flex-1 text-sm">
      <slot />
    </div>

    <div
      v-if="$slots.actions"
      class="flex shrink-0 flex-wrap gap-2"
    >
      <slot name="actions" />
    </div>

    <SvIconButton
      v-if="dismissible"
      :icon="SvIconClose"
      :label="`Dismiss ${label}`"
      size="md"
      class="shrink-0"
      @click="$emit('dismiss')"
    />
  </section>
</template>
