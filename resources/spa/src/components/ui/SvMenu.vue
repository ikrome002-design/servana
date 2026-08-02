<script setup lang="ts">
/**
 * SvMenu — an ACTION menu (Phase UI-04; UI/UX plan §10).
 *
 * Deliberately scoped to actions. Ordinary navigation stays a list of links: the ARIA menu pattern
 * hijacks arrow keys and suppresses a screen reader's link semantics, so applying it to a
 * navigation list makes navigation worse, not better. `RoleNavigation` therefore stays a list.
 *
 * Implements the WAI-ARIA menu-button pattern properly rather than partially:
 *  - trigger carries `aria-haspopup="menu"`, `aria-expanded` and `aria-controls`;
 *  - Down/Up open the menu and land on the first/last item;
 *  - Down/Up cycle, Home/End jump, Escape closes and returns focus to the trigger;
 *  - disabled items are skipped by keyboard navigation and are not activatable;
 *  - focus is MANAGED (roving `tabindex`), not faked with `aria-activedescendant` on a container
 *    that never receives focus.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { Component } from 'vue';
import { SvIconChevronDown } from '@/design-system/icons';

export interface SvMenuItem {
  /** Stable key. Also used to build the deterministic option id. */
  id: string;
  label: string;
  icon?: Component;
  disabled?: boolean;
  /** Marks an irreversible action so it can be styled distinctly. */
  destructive?: boolean;
}

const props = withDefaults(
  defineProps<{
    items: SvMenuItem[];
    /** Trigger text. Also the menu's accessible name. */
    label: string;
    align?: 'start' | 'end';
  }>(),
  { align: 'end' },
);

const emit = defineEmits<{ select: [id: string] }>();

const open = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const menuRef = ref<HTMLElement | null>(null);
const activeIndex = ref(-1);

const menuId = computed(() => `sv-menu-${props.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);

/** Indices a keyboard user may land on. Disabled items are skipped, never focused-then-refused. */
const enabledIndices = computed(() =>
  props.items.map((item, index) => (item.disabled === true ? -1 : index)).filter((index) => index >= 0),
);

async function focusItem(index: number): Promise<void> {
  activeIndex.value = index;
  await nextTick();
  menuRef.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')[index]?.focus();
}

async function openMenu(position: 'first' | 'last'): Promise<void> {
  open.value = true;
  const indices = enabledIndices.value;
  if (indices.length === 0) {
    return;
  }
  await focusItem(position === 'first' ? indices[0] : indices[indices.length - 1]);
}

function closeMenu(returnFocus = true): void {
  open.value = false;
  activeIndex.value = -1;
  if (returnFocus) {
    triggerRef.value?.focus();
  }
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    void openMenu('first');

    return;
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault();
    void openMenu('last');
  }
}

function onMenuKeydown(event: KeyboardEvent): void {
  const indices = enabledIndices.value;
  if (indices.length === 0) {
    return;
  }
  const position = indices.indexOf(activeIndex.value);

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      void focusItem(indices[(position + 1) % indices.length]);
      break;
    case 'ArrowUp':
      event.preventDefault();
      void focusItem(indices[(position - 1 + indices.length) % indices.length]);
      break;
    case 'Home':
      event.preventDefault();
      void focusItem(indices[0]);
      break;
    case 'End':
      event.preventDefault();
      void focusItem(indices[indices.length - 1]);
      break;
    case 'Escape':
      event.preventDefault();
      closeMenu();
      break;
    case 'Tab':
      // Tabbing away closes the menu but must NOT steal the focus move.
      closeMenu(false);
      break;
    default:
      break;
  }
}

function select(item: SvMenuItem): void {
  if (item.disabled === true) {
    return;
  }
  emit('select', item.id);
  closeMenu();
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (!open.value) {
    return;
  }
  const target = event.target as Node | null;
  if (menuRef.value?.contains(target ?? null) === true || triggerRef.value?.contains(target ?? null) === true) {
    return;
  }
  closeMenu(false);
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);

    return;
  }
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});
</script>

<template>
  <div class="relative inline-block">
    <button
      ref="triggerRef"
      type="button"
      :aria-haspopup="'menu'"
      :aria-expanded="open"
      :aria-controls="menuId"
      class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-2 rounded-control border border-sv-border-input px-3 py-2 text-sm font-medium text-sv-text hover:bg-sv-surface-subtle"
      data-testid="sv-menu-trigger"
      @click="open ? closeMenu(false) : openMenu('first')"
      @keydown="onTriggerKeydown"
    >
      {{ label }}
      <SvIconChevronDown
        aria-hidden="true"
        class="h-4 w-4 shrink-0"
      />
    </button>

    <div
      v-if="open"
      :id="menuId"
      ref="menuRef"
      role="menu"
      :aria-label="label"
      class="absolute z-sv-popover mt-2 max-h-[70vh] w-max min-w-[12rem] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised py-1 shadow-overlay"
      :class="align === 'end' ? 'right-0' : 'left-0'"
      data-testid="sv-menu"
      @keydown="onMenuKeydown"
    >
      <button
        v-for="(item, index) in items"
        :id="`${menuId}-item-${item.id}`"
        :key="item.id"
        role="menuitem"
        type="button"
        :disabled="item.disabled"
        :aria-disabled="item.disabled"
        :tabindex="activeIndex === index ? 0 : -1"
        class="sv-focus-ring flex min-h-sv-touch w-full items-center gap-2 px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:text-sv-disabled-fg"
        :class="item.destructive === true ? 'text-sv-error-fg hover:bg-sv-error-bg' : 'text-sv-text hover:bg-sv-surface-subtle'"
        @click="select(item)"
      >
        <component
          :is="item.icon"
          v-if="item.icon"
          aria-hidden="true"
          class="h-5 w-5 shrink-0"
        />
        {{ item.label }}
      </button>
    </div>
  </div>
</template>
