<script setup lang="ts">
/**
 * SvDrawer — an off-canvas panel (Phase UI-04; UI/UX plan §13.3, §13.4).
 *
 * Used for the mobile navigation drawer, filter panels and detail panels. It shares the ONE
 * focus trap with `SvDialog`, so focus containment, restoration and scroll-position preservation
 * behave identically.
 *
 * Placement is a TYPED PROP resolved by CSS, never by measuring the viewport in JavaScript
 * (CLAUDE.md guardrail 1). `placement="responsive"` is a bottom sheet on mobile and a side panel
 * from tablet up — expressed purely as Tailwind breakpoint classes, so it responds to a resize
 * without any listener.
 *
 * Safe-area insets are applied to the panel's own padding so the content clears the home
 * indicator rather than sitting under it.
 */
import { computed, ref } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { useFocusTrap } from '@/composables/useFocusTrap';
import { SvIconClose } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    open: boolean;
    /** Accessible name for the drawer. Required — "dialog" alone tells a user nothing. */
    title: string;
    /** `responsive` = bottom sheet on mobile, side panel from tablet up. CSS only. */
    placement?: 'start' | 'end' | 'bottom' | 'responsive';
    persistent?: boolean;
    hideCloseButton?: boolean;
  }>(),
  { placement: 'end', persistent: false, hideCloseButton: false },
);

const emit = defineEmits<{ close: [] }>();

const panelRef = ref<HTMLElement | null>(null);
const openRef = computed(() => props.open);

useFocusTrap({ container: panelRef, open: openRef });

const titleId = 'sv-drawer-title';

/** Position and size, resolved entirely by CSS breakpoints. */
const panelClasses = computed(() => {
  switch (props.placement) {
    case 'start':
      return 'inset-y-0 left-0 h-full w-sv-drawer max-w-[85%]';
    case 'bottom':
      return 'inset-x-0 bottom-0 max-h-[85vh] w-full rounded-t-overlay';
    case 'responsive':
      // Mobile: bottom sheet. Tablet and up: right-hand side panel. No JavaScript involved.
      return 'inset-x-0 bottom-0 max-h-[85vh] w-full rounded-t-overlay md:inset-y-0 md:left-auto md:right-0 md:h-full md:max-h-none md:w-sv-drawer md:max-w-[85%] md:rounded-t-none';
    default:
      return 'inset-y-0 right-0 h-full w-sv-drawer max-w-[85%]';
  }
});

function requestClose(reason: 'escape' | 'outside' | 'button'): void {
  if (props.persistent && reason !== 'button') {
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
      class="fixed inset-0 z-sv-drawer"
      data-testid="sv-drawer-root"
    >
      <div
        class="absolute inset-0 bg-sv-scrim"
        aria-hidden="true"
        data-testid="sv-drawer-scrim"
        @click="requestClose('outside')"
      />

      <div
        ref="panelRef"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        class="absolute flex flex-col overflow-hidden bg-sv-surface-raised shadow-overlay focus:outline-none"
        :class="panelClasses"
        style="padding-bottom: var(--sv-safe-area-bottom)"
        :data-placement="placement"
        data-testid="sv-drawer"
        @keydown="onKeydown"
      >
        <div class="flex items-center justify-between gap-3 border-b border-sv-border p-4">
          <h2
            :id="titleId"
            class="font-display text-base font-bold text-sv-text-heading"
          >
            {{ title }}
          </h2>
          <SvIconButton
            v-if="!hideCloseButton"
            :icon="SvIconClose"
            :label="`Close ${title}`"
            size="md"
            class="-mr-2 shrink-0"
            data-testid="sv-drawer-close"
            @click="requestClose('button')"
          />
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-4">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="border-t border-sv-border p-4"
          data-testid="sv-drawer-footer"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
