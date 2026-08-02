<script setup lang="ts">
/**
 * SvOfflineState — "the browser has no connection" (Phase UI-04).
 *
 * Separate from `SvErrorState` because the remedy is different: a server error may be worth
 * retrying immediately, whereas an offline browser needs the connection back first. Telling a
 * user on a dropped mobile connection that "something went wrong" sends them to support for a
 * problem support cannot fix.
 *
 * Retry IS offered here, because reconnecting and retrying is precisely the user's next step.
 *
 * Servana registers no service worker and claims no offline capability (UI/UX plan §12.3): this
 * component reports a connection state, it does not enable working offline.
 */
import { SvIconOffline } from '@/design-system/icons';

withDefaults(
  defineProps<{
    title?: string;
    message?: string;
    retryLabel?: string;
  }>(),
  {
    title: 'You’re offline',
    message: 'Check your connection and try again. Nothing has been lost.',
    retryLabel: 'Try again',
  },
);

defineEmits<{ retry: [] }>();
</script>

<template>
  <div
    role="status"
    class="flex flex-col items-center py-12 text-center"
    data-testid="sv-offline-state"
  >
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-sv-surface-subtle text-sv-text-muted"
    >
      <SvIconOffline class="h-8 w-8" />
    </div>

    <h3 class="font-display text-base font-bold text-sv-text-heading">
      {{ title }}
    </h3>
    <p class="mt-1 max-w-sm text-sm text-sv-text-muted">
      {{ message }}
    </p>

    <button
      type="button"
      class="sv-focus-ring mt-6 inline-flex min-h-sv-touch items-center rounded-control border border-sv-border-input px-4 py-2 text-sm font-medium text-sv-text hover:bg-sv-surface-subtle"
      data-testid="sv-offline-retry"
      @click="$emit('retry')"
    >
      {{ retryLabel }}
    </button>
  </div>
</template>
