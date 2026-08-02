<script setup lang="ts">
/**
 * SvLockedState — "this exists, you may see it, but it is closed to changes" (Phase UI-04).
 *
 * The distinction from `SvPermissionState` is real and load-bearing. A locked financial period, a
 * closed cash-up, a finalised payout run: the user is entitled to SEE these, and the reason they
 * cannot edit is a business rule with a name and often a date — not an authorization refusal.
 *
 * So this component may state the reason and the moment, because none of that is a disclosure the
 * user is not already entitled to. `SvPermissionState`, by contract, may not.
 *
 * `role="status"` — a business state, not a failure.
 */
import SvDateTime from '@/components/ui/SvDateTime.vue';
import { SvIconLocked } from '@/design-system/icons';

withDefaults(
  defineProps<{
    title?: string;
    /** The business rule, e.g. "This period was locked and can no longer be edited." */
    message?: string;
    /** When it was locked, if the record carries it. */
    lockedAt?: string | null;
    /** Who locked it, when the authorized payload includes it. */
    lockedBy?: string | null;
  }>(),
  {
    title: 'Locked',
    message: 'This record is locked and can no longer be changed.',
    lockedAt: null,
    lockedBy: null,
  },
);
</script>

<template>
  <div
    role="status"
    class="flex flex-col items-center py-12 text-center"
    data-testid="sv-locked-state"
  >
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-sv-warning-bg text-sv-warning-fg"
    >
      <SvIconLocked class="h-8 w-8" />
    </div>

    <h3 class="font-display text-base font-bold text-sv-text-heading">
      {{ title }}
    </h3>
    <p class="mt-1 max-w-sm text-sm text-sv-text-muted">
      {{ message }}
    </p>

    <p
      v-if="lockedAt !== null || lockedBy !== null"
      class="mt-2 text-xs text-sv-text-muted"
    >
      <template v-if="lockedAt !== null">
        Locked <SvDateTime :value="lockedAt" />
      </template>
      <template v-if="lockedBy !== null">
        by {{ lockedBy }}
      </template>
    </p>

    <div
      v-if="$slots.actions"
      class="mt-6 flex flex-wrap justify-center gap-2"
    >
      <slot name="actions" />
    </div>
  </div>
</template>
