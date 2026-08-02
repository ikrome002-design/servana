<script setup lang="ts">
/**
 * SvErrorState — "we could not load this" (Phase UI-04; Plan §6.4, §11.5).
 *
 * Deliberately distinct from `SvEmptyState`, `SvPermissionState`, `SvOfflineState` and
 * `SvLockedState`. Collapsing them is how a user gets told "no records" when the truth is
 * "the request failed" or "you may not see these" — three different facts requiring three
 * different actions.
 *
 * `role="alert"` because a failure the user did not cause should be announced when it appears.
 *
 * The retry affordance is offered only when the caller says the operation is safely repeatable;
 * offering "Try again" for a failed financial mutation would invite a duplicate submission.
 */
import { SvIconError } from '@/design-system/icons';

withDefaults(
  defineProps<{
    title?: string;
    /**
     * The failure to show. Callers should pass the server's own message from the Plan §11.5
     * envelope where one exists — inventing a friendlier message hides what actually happened.
     */
    message?: string;
    /** A correlation id, when present, so support can find the request. Never a token. */
    correlationId?: string | null;
    /** Only when repeating the operation is genuinely safe. */
    retryable?: boolean;
    retryLabel?: string;
  }>(),
  {
    title: 'Something went wrong',
    message: 'We couldn’t load this. Please try again.',
    correlationId: null,
    retryable: true,
    retryLabel: 'Try again',
  },
);

defineEmits<{ retry: [] }>();
</script>

<template>
  <div
    role="alert"
    class="flex flex-col items-center py-12 text-center"
    data-testid="sv-error-state"
  >
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-sv-error-bg text-sv-error-fg"
    >
      <SvIconError class="h-8 w-8" />
    </div>

    <h3 class="font-display text-base font-bold text-sv-text-heading">
      {{ title }}
    </h3>
    <p class="mt-1 max-w-sm text-sm text-sv-text-muted">
      {{ message }}
    </p>

    <p
      v-if="correlationId"
      class="mt-2 text-xs text-sv-text-muted"
    >
      Reference: <span class="sv-numeric">{{ correlationId }}</span>
    </p>

    <button
      v-if="retryable"
      type="button"
      class="sv-focus-ring mt-6 inline-flex min-h-sv-touch items-center rounded-control border border-sv-border-input px-4 py-2 text-sm font-medium text-sv-text hover:bg-sv-surface-subtle"
      data-testid="sv-error-retry"
      @click="$emit('retry')"
    >
      {{ retryLabel }}
    </button>
  </div>
</template>
