<script setup lang="ts">
/**
 * SvAlert — an inline message attached to the content it concerns (Phase UI-04; UI/UX plan §10).
 *
 * Distinct from `SvBanner` (page- or account-level, persistent) and `SvToast` (transient,
 * globally positioned). Using one component for all three is how a routine confirmation ends up
 * interrupting a screen-reader user mid-sentence.
 *
 * Politeness is derived from severity, not from a caller flag:
 *  - `error` uses `role="alert"` (assertive) — a failure the user must know about now;
 *  - everything else uses `role="status"` (polite) — announced at the next natural pause.
 *
 * Severity is never colour-only: each tone renders a distinct Heroicon AND a visually hidden
 * severity word, so the meaning survives monochrome, colour blindness and speech.
 */
import { computed } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import {
  SvIconClose,
  SvIconError,
  SvIconInfo,
  SvIconSuccess,
  SvIconWarning,
} from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    severity?: 'info' | 'success' | 'warning' | 'error';
    /** Optional heading. The message itself goes in the default slot. */
    title?: string;
    dismissible?: boolean;
  }>(),
  { severity: 'info', title: undefined, dismissible: false },
);

defineEmits<{ dismiss: [] }>();

const ICONS = {
  info: SvIconInfo,
  success: SvIconSuccess,
  warning: SvIconWarning,
  error: SvIconError,
} as const;

/** Errors interrupt; everything else waits for a pause. */
const role = computed(() => (props.severity === 'error' ? 'alert' : 'status'));

/** Spoken severity, so the tone colour is not the only carrier of meaning. */
const severityWord = computed(
  () => ({ info: 'Information', success: 'Success', warning: 'Warning', error: 'Error' })[props.severity],
);
</script>

<template>
  <div
    :role="role"
    class="flex gap-3 rounded-card border p-4"
    :class="{
      'border-sv-info-border bg-sv-info-bg text-sv-info-fg': severity === 'info',
      'border-sv-success-border bg-sv-success-bg text-sv-success-fg': severity === 'success',
      'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg': severity === 'warning',
      'border-sv-error-border bg-sv-error-bg text-sv-error-fg': severity === 'error',
    }"
    :data-severity="severity"
    data-testid="sv-alert"
  >
    <component
      :is="ICONS[severity]"
      aria-hidden="true"
      class="mt-0.5 h-5 w-5 shrink-0"
    />

    <div class="min-w-0 flex-1">
      <span class="sr-only">{{ severityWord }}: </span>
      <p
        v-if="title"
        class="font-semibold"
      >
        {{ title }}
      </p>
      <div class="text-sm">
        <slot />
      </div>
      <div
        v-if="$slots.actions"
        class="mt-3 flex flex-wrap gap-2"
      >
        <slot name="actions" />
      </div>
    </div>

    <SvIconButton
      v-if="dismissible"
      :icon="SvIconClose"
      label="Dismiss message"
      size="md"
      class="-m-2 shrink-0"
      @click="$emit('dismiss')"
    />
  </div>
</template>
