<script setup lang="ts">
/**
 * One gate-blocked navigation entry (Phase UI-16; UI/UX plan §7.4, ADR-019).
 *
 * The contract lists the page, so it stays visible and named — but it has no destination, so it
 * is inert (`aria-disabled`, no `href`) and it states the EXACT gate. `tabindex="0"` keeps it
 * keyboard-discoverable: a user must be able to find out why it is unavailable.
 *
 * UI16-NAV-001: every left-navigation account printed the full gate sentence as a permanent
 * three-to-five-line block under each blocked entry, so a Merchant Administrator's sidebar ran to
 * roughly 1,600px and the eight gated rows out-weighed the fifteen live ones. The statement is not
 * shortened or hidden — it stays in the DOM, is announced as the entry's description, and expands
 * in full on hover and keyboard focus. At rest it shows one clamped line under a compact
 * "Unavailable" marker, which restores the hierarchy without dropping a word of the dependency.
 */
import { useId, type Component } from 'vue';
import { SvIconLocked } from '@/design-system/icons';

defineProps<{
  label: string;
  reason: string | null;
  icon?: Component | null;
  /** Test hook of the entry itself (kept identical to the pre-UI-16 hooks). */
  testid: string;
  /** Test hook of the reason element, when the surface exposes one. */
  reasonTestid?: string;
  /** `panel` = inside a header disclosure; `stacked` = sidebar, rail or drawer. */
  surface?: 'panel' | 'stacked';
}>();

/**
 * Unique per rendered instance. The same tree renders in the sidebar, the tablet rail and the
 * drawer, so an id derived from the entry key was duplicated in the document and
 * `aria-describedby` pointed at an ambiguous element (found by the UI-16 gate sweep).
 */
const reasonId = `${useId()}-gate-reason`;
</script>

<template>
  <span
    role="link"
    aria-disabled="true"
    tabindex="0"
    class="sv-gated-entry group sv-focus-ring flex min-h-sv-touch items-start gap-3 rounded-control px-3 py-2 text-sm text-sv-disabled-fg"
    :class="surface === 'panel' ? 'rounded-none' : ''"
    :aria-describedby="reason ? reasonId : undefined"
    :data-testid="testid"
  >
    <span
      v-if="icon"
      aria-hidden="true"
      class="sv-gated-entry__icon relative mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-control"
    >
      <component
        :is="icon"
        class="h-[18px] w-[18px]"
      />
      <SvIconLocked class="sv-gated-entry__lock absolute -bottom-1 -right-1 h-3.5 w-3.5 rounded-full p-px" />
    </span>
    <span class="min-w-0 flex-1">
      <span class="block leading-5">{{ label }}</span>
      <span
        v-if="reason"
        :id="reasonId"
        class="sv-gated-entry__reason mt-1 text-xs leading-4"
        :data-testid="reasonTestid"
      ><span class="sv-gated-entry__pill mr-1 rounded-pill px-1.5 py-px text-[0.6875rem] font-semibold uppercase tracking-wide">Unavailable</span> — {{ reason }}</span>
    </span>
  </span>
</template>

<style scoped>
.sv-gated-entry__icon {
  background: var(--sv-color-surface-subtle);
}
.sv-gated-entry__lock {
  background: var(--sv-color-surface-raised);
  color: var(--sv-color-text-muted);
}
.sv-gated-entry__pill {
  background: var(--sv-color-surface-subtle);
  color: var(--sv-color-text-muted);
}
/* At rest the dependency reads as one line; it expands in full on hover and keyboard focus. */
.sv-gated-entry__reason {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.sv-gated-entry:hover .sv-gated-entry__reason,
.sv-gated-entry:focus-visible .sv-gated-entry__reason {
  display: block;
  -webkit-line-clamp: unset;
}
</style>
