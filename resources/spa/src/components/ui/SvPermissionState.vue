<script setup lang="ts">
/**
 * SvPermissionState — "you may not view this" (Phase UI-04; Plan §10.2; UI/UX plan §18).
 *
 * The security-sensitive member of the state family. Its whole discipline is what it must NOT
 * say.
 *
 * It never names the resource, its identifier, its owner, its merchant, its branch, or whether it
 * exists at all. "You don't have access to invoice INV-2026-0042 for Glow Salon" confirms that
 * the invoice exists and who it belongs to — a non-enumerating denial is the backend's contract
 * (UI-03 proved it for account routes) and the UI must not undo it by being helpful.
 *
 * It is also not an error. Nothing failed; the answer is simply no. So it uses `role="status"`
 * rather than `role="alert"`, and offers no retry — repeating a request that was correctly
 * refused is not a remedy.
 *
 * Frontend visibility is UX only. This component never decides authorization; it renders the
 * server's refusal.
 */
import { SvIconForbidden } from '@/design-system/icons';

withDefaults(
  defineProps<{
    title?: string;
    /**
     * Generic by design. A caller must not pass a resource name, identifier, owner or existence
     * hint — that is the non-enumeration boundary.
     */
    message?: string;
    /** Optional guidance, e.g. "Ask your administrator". Never names who holds the permission. */
    guidance?: string;
  }>(),
  {
    title: 'You don’t have access to this',
    message: 'Your account doesn’t include this area.',
    guidance: 'If you think this is a mistake, contact your administrator.',
  },
);
</script>

<template>
  <div
    role="status"
    class="flex flex-col items-center py-12 text-center"
    data-testid="sv-permission-state"
  >
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-sv-surface-subtle text-sv-text-muted"
    >
      <SvIconForbidden class="h-8 w-8" />
    </div>

    <h3 class="font-display text-base font-bold text-sv-text-heading">
      {{ title }}
    </h3>
    <p class="mt-1 max-w-sm text-sm text-sv-text-muted">
      {{ message }}
    </p>
    <p
      v-if="guidance"
      class="mt-1 max-w-sm text-sm text-sv-text-muted"
    >
      {{ guidance }}
    </p>
    <!-- No retry: repeating a request that was correctly refused is not a remedy. -->
  </div>
</template>
