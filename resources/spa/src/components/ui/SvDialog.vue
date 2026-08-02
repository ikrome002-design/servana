<script setup lang="ts">
/**
 * SvDialog — the canonical modal dialog (Phase UI-04; UI/UX plan §10, §19).
 *
 * This REPLACES the ad-hoc focus handling in the pre-UI-04 `SvModal`, which focused its container
 * but never trapped Tab, never restored focus on close, and reset the page scroll position when
 * it released the body lock. All three are now owned by the single shared `useFocusTrap`
 * composable, so `SvDialog`, `SvConfirmDialog` and `SvDrawer` cannot drift apart.
 *
 * Contract:
 *  - `role="dialog"` + `aria-modal`, labelled by its own title and described by its description;
 *  - focus moves inside on open and is RESTORED to the invoking control on close;
 *  - Tab and Shift+Tab cycle within the dialog;
 *  - Escape closes unless `persistent` (a dialog mid-submission must not vanish);
 *  - outside click closes only when `dismissOnOutsideClick`, and never for a persistent dialog;
 *  - z-index comes from the `--sv-z-dialog` token, above the fixed footer, so the footer can
 *    never obstruct dialog controls (ADR-024);
 *  - the panel is height-capped and scrolls internally, so it fits at 360px and at 200% zoom;
 *  - safe-area insets are respected on mobile.
 */
import { computed, ref } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { useFocusTrap } from '@/composables/useFocusTrap';
import { SvIconClose } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg';
    /** Escape and outside click do nothing. For a dialog that must not vanish mid-operation. */
    persistent?: boolean;
    dismissOnOutsideClick?: boolean;
    /** Hide the close control for a dialog whose only exits are its own explicit actions. */
    hideCloseButton?: boolean;
  }>(),
  {
    description: undefined,
    size: 'md',
    persistent: false,
    dismissOnOutsideClick: true,
    hideCloseButton: false,
  },
);

const emit = defineEmits<{ close: [] }>();

const panelRef = ref<HTMLElement | null>(null);
const openRef = computed(() => props.open);

useFocusTrap({ container: panelRef, open: openRef });

/** Deterministic ids so the label/description association is stable and testable. */
const titleId = 'sv-dialog-title';
const descriptionId = 'sv-dialog-description';

function requestClose(reason: 'escape' | 'outside' | 'button'): void {
  if (props.persistent && reason !== 'button') {
    return;
  }
  if (reason === 'outside' && !props.dismissOnOutsideClick) {
    return;
  }
  emit('close');
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation();
    requestClose('escape');
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-sv-dialog flex items-center justify-center p-4"
      style="padding-bottom: max(1rem, var(--sv-safe-area-bottom))"
      data-testid="sv-dialog-root"
    >
      <div
        class="absolute inset-0 bg-sv-scrim"
        aria-hidden="true"
        data-testid="sv-dialog-scrim"
        @click="requestClose('outside')"
      />

      <div
        :id="'sv-dialog'"
        ref="panelRef"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="description ? descriptionId : undefined"
        tabindex="-1"
        class="relative z-10 flex max-h-[85vh] w-full flex-col overflow-hidden rounded-overlay bg-sv-surface-raised shadow-overlay focus:outline-none"
        :class="{
          'max-w-sv-dialog-sm': size === 'sm',
          'max-w-sv-dialog-md': size === 'md',
          'max-w-sv-dialog-lg': size === 'lg',
        }"
        data-testid="sv-dialog"
        @keydown="onKeydown"
      >
        <div class="flex items-start justify-between gap-3 p-6 pb-0">
          <div class="min-w-0">
            <h2
              :id="titleId"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              {{ title }}
            </h2>
            <p
              v-if="description"
              :id="descriptionId"
              class="mt-1 text-sm text-sv-text-muted"
            >
              {{ description }}
            </p>
          </div>

          <SvIconButton
            v-if="!hideCloseButton"
            :icon="SvIconClose"
            label="Close dialog"
            size="md"
            class="-mr-2 -mt-2 shrink-0"
            data-testid="sv-dialog-close"
            @click="requestClose('button')"
          />
        </div>

        <!-- The BODY scrolls, not the page, so the header and footer stay reachable at any height. -->
        <div class="min-h-0 flex-1 overflow-y-auto p-6">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="flex flex-wrap justify-end gap-2 border-t border-sv-border p-4"
          data-testid="sv-dialog-footer"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
