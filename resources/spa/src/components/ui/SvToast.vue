<script setup lang="ts">
/**
 * SvToast — transient confirmations (Phase UI-04; UI/UX plan §10; ADR-024).
 *
 * Phase UI-04 corrected five contract defects in the Phase 4 implementation:
 *
 *  1. **Colour-only status.** The type was carried solely by a coloured dot. Each toast now
 *     renders a Heroicon AND a visually hidden severity word, so the meaning survives colour
 *     blindness, monochrome and speech.
 *  2. **A hand-rolled SVG close icon** in a 20px target. Replaced with the shared Heroicons close
 *     glyph inside `SvIconButton`, which brings the 44px minimum with it.
 *  3. **A duplicate announcement.** The container was `aria-live="polite"` AND each toast was
 *     `role="status"` — a live region nested inside a live region, so screen readers announced
 *     every message twice. Only the container is a live region now.
 *  4. **It sat under the fixed footer.** `bottom-4` placed the dismiss control inside the band the
 *     footer occupies, which ADR-024 names explicitly. It is now offset by the same footer-height
 *     token the page reserves.
 *  5. **Later toasts never expired.** Removal was scheduled only on mount, so anything added
 *     afterwards stayed until the caller intervened.
 *
 * Timers pause on hover AND on focus. Pausing only on hover strands a keyboard user, who cannot
 * hover: the toast would vanish while they were tabbing toward its dismiss button.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import {
  SvIconClose,
  SvIconError,
  SvIconInfo,
  SvIconSuccess,
  SvIconWarning,
} from '@/design-system/icons';
import { useNotificationStore } from '@/stores/notificationStore';

const store = useNotificationStore();

const DISMISS_MS = 5000;

const ICONS = {
  success: SvIconSuccess,
  error: SvIconError,
  warning: SvIconWarning,
  info: SvIconInfo,
} as const;

/** Spoken severity, so the tone colour is never the only carrier of meaning. */
const SEVERITY_WORD = {
  success: 'Success',
  error: 'Error',
  warning: 'Warning',
  info: 'Information',
} as const;

const timers = ref<Map<string, ReturnType<typeof setTimeout>>>(new Map());

function cancelRemove(id: string): void {
  const timer = timers.value.get(id);
  if (timer !== undefined) {
    clearTimeout(timer);
    timers.value.delete(id);
  }
}

function scheduleRemove(id: string): void {
  cancelRemove(id);
  timers.value.set(id, setTimeout(() => store.removeToast(id), DISMISS_MS));
}

function dismiss(id: string): void {
  cancelRemove(id);
  store.removeToast(id);
}

onMounted(() => {
  store.toasts.forEach((toast) => scheduleRemove(toast.id));
});

/** Schedule any toast that appeared since the last tick, and drop timers for ones that left. */
watch(
  () => store.toasts.map((toast) => toast.id),
  (ids) => {
    for (const id of ids) {
      if (!timers.value.has(id)) {
        scheduleRemove(id);
      }
    }
    for (const id of [...timers.value.keys()]) {
      if (!ids.includes(id)) {
        cancelRemove(id);
      }
    }
  },
);

onBeforeUnmount(() => {
  for (const timer of timers.value.values()) {
    clearTimeout(timer);
  }
  timers.value.clear();
});

defineExpose({ scheduleRemove });
</script>

<template>
  <Teleport to="body">
    <!--
      ONE live region for the whole stack. `bottom` clears the reserved fixed-footer band using the
      SAME token the page reserves (ADR-024), so the dismiss control is never covered.

      `role="status"` sits on the SAME element as `aria-live` — one region, not the nested pair this
      component used to have. It is what exposes the stack as a status landmark; `aria-live` alone
      confers no role, so without it the announcement is made but the region cannot be addressed by
      role at all.
    -->
    <div
      role="status"
      aria-live="polite"
      aria-atomic="false"
      class="fixed right-4 z-sv-toast flex flex-col gap-2"
      style="bottom: calc(var(--sv-footer-height-mobile) + var(--sv-safe-area-bottom) + 1rem)"
      data-testid="sv-toast-region"
    >
      <div
        v-for="toast in store.toasts"
        :key="toast.id"
        class="flex min-w-[280px] max-w-sm items-start gap-3 rounded-card border border-sv-border bg-sv-surface-raised p-4 shadow-raised"
        :data-type="toast.type"
        :data-testid="`sv-toast-${toast.id}`"
        @mouseenter="cancelRemove(toast.id)"
        @mouseleave="scheduleRemove(toast.id)"
        @focusin="cancelRemove(toast.id)"
        @focusout="scheduleRemove(toast.id)"
      >
        <component
          :is="ICONS[toast.type]"
          aria-hidden="true"
          class="mt-0.5 h-5 w-5 shrink-0"
          :class="{
            'text-sv-success-fg': toast.type === 'success',
            'text-sv-error-fg': toast.type === 'error',
            'text-sv-warning-fg': toast.type === 'warning',
            'text-sv-info-fg': toast.type === 'info',
          }"
        />

        <p class="flex-1 text-sm text-sv-text">
          <!-- Severity in TEXT: the icon and colour are reinforcement, never the only carrier. -->
          <span class="sr-only">{{ SEVERITY_WORD[toast.type] }}: </span>{{ toast.message }}
        </p>

        <SvIconButton
          :icon="SvIconClose"
          :label="`Dismiss: ${toast.message}`"
          size="sm"
          class="-mr-2 -mt-2 shrink-0"
          :data-testid="`sv-toast-dismiss-${toast.id}`"
          @click="dismiss(toast.id)"
        />
      </div>
    </div>
  </Teleport>
</template>
