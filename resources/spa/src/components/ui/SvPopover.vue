<script setup lang="ts">
/**
 * SvPopover — anchored, NON-MODAL content (Phase UI-04; UI/UX plan §10).
 *
 * Not a dialog: the rest of the page stays interactive and focus is not trapped. It is the base
 * for `SvMenu` and the profile control.
 *
 * Three rules it enforces:
 *
 *  1. **Never hover-only.** The trigger is a real button that opens on click and on Enter/Space.
 *     Hover may enhance, but a pointer-only affordance is unreachable by keyboard and by touch.
 *  2. **Escape closes and returns focus to the trigger**, so a keyboard user is never left in a
 *     panel that has vanished.
 *  3. **Collision handling is CSS.** The panel is constrained to the viewport width and flips its
 *     alignment by class, not by measuring the window — no JavaScript viewport reads.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    /** Accessible name for the panel region. */
    label: string;
    align?: 'start' | 'end';
    placement?: 'top' | 'bottom';
    /** Move focus into the panel on open. Off for a popover that is purely informational. */
    focusOnOpen?: boolean;
  }>(),
  { align: 'end', placement: 'bottom', focusOnOpen: true },
);

const emit = defineEmits<{ close: [] }>();

const panelRef = ref<HTMLElement | null>(null);
const triggerRef = ref<HTMLElement | null>(null);

/** Registered by the parent so focus can be returned precisely. */
function setTrigger(element: HTMLElement | null): void {
  triggerRef.value = element;
}

function close(): void {
  emit('close');
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (!props.open) {
    return;
  }
  const target = event.target as Node | null;
  if (panelRef.value?.contains(target ?? null) === true) {
    return;
  }
  if (triggerRef.value?.contains(target ?? null) === true) {
    // The trigger handles its own toggle; closing here too would immediately reopen.
    return;
  }
  close();
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (props.open && event.key === 'Escape') {
    close();
    // Returning focus to the trigger is the whole point: otherwise Escape strands the user.
    triggerRef.value?.focus();
  }
}

/**
 * Register the dismiss listeners.
 *
 * Called from BOTH the watcher and `onMounted`: `open` is a caller-controlled prop, so a popover
 * can legitimately mount already open — and a watcher alone never fires for that, leaving Escape
 * and outside-click dead for exactly the case where the panel is visible from the first frame.
 */
function attachDismissListeners(): void {
  document.addEventListener('pointerdown', onDocumentPointerDown, true);
  document.addEventListener('keydown', onDocumentKeydown, true);
}

function detachDismissListeners(): void {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
  document.removeEventListener('keydown', onDocumentKeydown, true);
}

onMounted(() => {
  if (props.open) {
    attachDismissListeners();
  }
});

watch(
  () => props.open,
  async (open) => {
    if (open) {
      attachDismissListeners();
      if (props.focusOnOpen) {
        await nextTick();
        // `focus()` returns void, so `a?.focus() ?? b?.focus()` would ALWAYS run both — the
        // fallback has to be chosen from the element, not from the call's result.
        const target =
          panelRef.value?.querySelector<HTMLElement>('a, button, input, [tabindex]:not([tabindex="-1"])')
          ?? panelRef.value;
        target?.focus();
      }

      return;
    }
    detachDismissListeners();
  },
);

onBeforeUnmount(detachDismissListeners);

/**
 * Alignment and flip, expressed as classes. `max-w-[calc(100vw-2rem)]` is the collision guard:
 * the panel can never be wider than the viewport, so it cannot push the page sideways.
 */
const panelClasses = computed(() => [
  props.align === 'end' ? 'right-0' : 'left-0',
  props.placement === 'top' ? 'bottom-full mb-2' : 'top-full mt-2',
]);

defineExpose({ setTrigger });
</script>

<template>
  <div class="relative inline-block">
    <slot name="trigger" />

    <div
      v-if="open"
      ref="panelRef"
      role="group"
      :aria-label="label"
      tabindex="-1"
      class="absolute z-sv-popover max-h-[70vh] w-max max-w-[calc(100vw-2rem)] overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised shadow-overlay focus:outline-none"
      :class="panelClasses"
      data-testid="sv-popover"
    >
      <slot />
    </div>
  </div>
</template>
