<script setup lang="ts">
/**
 * SvTooltip — SUPPLEMENTARY hint text (Phase UI-04; UI/UX plan §10, §19).
 *
 * The boundary that matters: a tooltip may never be the only accessible name. It describes; it
 * does not label. `SvIconButton` therefore keeps its own required `label`, and a tooltip merely
 * adds detail — which is why this component wires `aria-describedby`, never `aria-labelledby`.
 *
 * Reachability:
 *  - shows on hover AND on keyboard focus, so it is not pointer-only;
 *  - Escape dismisses it while focus stays put (WCAG 1.4.13 dismissible);
 *  - the delay is a fixed token-driven constant, so behaviour is deterministic in tests.
 *
 * The tooltip contains no interactive content. Anything focusable inside would be unreachable:
 * moving toward it removes focus from the trigger and the tooltip disappears.
 */
import { computed, onBeforeUnmount, ref } from 'vue';

const props = withDefaults(
  defineProps<{
    /** The hint. Plain text only — a tooltip is not a container for markup or controls. */
    text: string;
    placement?: 'top' | 'bottom';
    /** Milliseconds before a hover-triggered tooltip appears. Focus shows it immediately. */
    delayMs?: number;
  }>(),
  { placement: 'top', delayMs: 200 },
);

const visible = ref(false);
const timer = ref<ReturnType<typeof setTimeout> | null>(null);

/** Deterministic id so `aria-describedby` is stable and assertable. */
const tooltipId = computed(
  () => `sv-tooltip-${props.text.toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 40)}`,
);

function clearTimer(): void {
  if (timer.value !== null) {
    clearTimeout(timer.value);
    timer.value = null;
  }
}

function showAfterDelay(): void {
  clearTimer();
  timer.value = setTimeout(() => {
    visible.value = true;
  }, props.delayMs);
}

/** Focus shows immediately: a keyboard user should not have to wait to read a hint. */
function showNow(): void {
  clearTimer();
  visible.value = true;
}

function hide(): void {
  clearTimer();
  visible.value = false;
}

function onKeydown(event: KeyboardEvent): void {
  // WCAG 1.4.13: dismissible without moving focus.
  if (event.key === 'Escape' && visible.value) {
    event.stopPropagation();
    hide();
  }
}

onBeforeUnmount(clearTimer);
</script>

<template>
  <span
    class="relative inline-flex"
    @mouseenter="showAfterDelay"
    @mouseleave="hide"
    @focusin="showNow"
    @focusout="hide"
    @keydown="onKeydown"
  >
    <!--
      The trigger is described by, never labelled by, the tooltip: an icon-only control keeps its
      own accessible name and this only adds detail.
    -->
    <slot :describedby="tooltipId" />

    <span
      v-if="visible"
      :id="tooltipId"
      role="tooltip"
      class="pointer-events-none absolute left-1/2 z-sv-popover w-max max-w-[16rem] -translate-x-1/2 rounded-control bg-sv-surface-raised px-2 py-1 text-xs text-sv-text shadow-overlay ring-1 ring-sv-border"
      :class="placement === 'top' ? 'bottom-full mb-1' : 'top-full mt-1'"
      data-testid="sv-tooltip"
    >{{ text }}</span>
  </span>
</template>
