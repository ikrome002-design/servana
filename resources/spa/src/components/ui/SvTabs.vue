<script setup lang="ts">
/**
 * SvTabs — the WAI-ARIA tabs pattern (Phase UI-04; UI/UX plan §10).
 *
 * Roving tabindex, not `aria-activedescendant`: exactly one tab is in the tab order, and arrow
 * keys move real focus between tabs. Tab then moves OUT of the tablist into the panel, which is
 * what a keyboard user expects.
 *
 * `activation` is explicit rather than assumed:
 *  - `automatic` (the default) selects as focus moves — correct for cheap, local panel swaps;
 *  - `manual` requires Enter/Space — correct when selecting a tab triggers a request, so arrowing
 *    past three tabs does not fire three requests.
 *
 * Panels are associated both ways (`aria-controls` / `aria-labelledby`) and only the selected
 * panel is rendered, so hidden panels cannot be reached by Tab.
 */
import { computed, nextTick, ref } from 'vue';

export interface SvTab {
  id: string;
  label: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{
    tabs: SvTab[];
    /** Selected tab id (controlled by the caller). */
    modelValue: string;
    /** Accessible name for the tablist. */
    label: string;
    activation?: 'automatic' | 'manual';
  }>(),
  { activation: 'automatic' },
);

const emit = defineEmits<{ 'update:modelValue': [id: string] }>();

const tablistRef = ref<HTMLElement | null>(null);

const enabledIndices = computed(() =>
  props.tabs.map((tab, index) => (tab.disabled === true ? -1 : index)).filter((index) => index >= 0),
);

const selectedIndex = computed(() => props.tabs.findIndex((tab) => tab.id === props.modelValue));

async function focusTab(index: number): Promise<void> {
  await nextTick();
  tablistRef.value?.querySelectorAll<HTMLElement>('[role="tab"]')[index]?.focus();
}

async function moveTo(index: number): Promise<void> {
  if (props.activation === 'automatic') {
    emit('update:modelValue', props.tabs[index].id);
  }
  await focusTab(index);
}

function onKeydown(event: KeyboardEvent, index: number): void {
  const indices = enabledIndices.value;
  const position = indices.indexOf(index);

  switch (event.key) {
    case 'ArrowRight':
      event.preventDefault();
      void moveTo(indices[(position + 1) % indices.length]);
      break;
    case 'ArrowLeft':
      event.preventDefault();
      void moveTo(indices[(position - 1 + indices.length) % indices.length]);
      break;
    case 'Home':
      event.preventDefault();
      void moveTo(indices[0]);
      break;
    case 'End':
      event.preventDefault();
      void moveTo(indices[indices.length - 1]);
      break;
    case 'Enter':
    case ' ':
      // Manual activation: the explicit commit.
      event.preventDefault();
      select(props.tabs[index]);
      break;
    default:
      break;
  }
}

function select(tab: SvTab): void {
  if (tab.disabled === true) {
    return;
  }
  emit('update:modelValue', tab.id);
}
</script>

<template>
  <div>
    <div
      ref="tablistRef"
      role="tablist"
      :aria-label="label"
      class="flex flex-wrap gap-1 border-b border-sv-border"
      data-testid="sv-tablist"
    >
      <button
        v-for="(tab, index) in tabs"
        :id="`sv-tab-${tab.id}`"
        :key="tab.id"
        role="tab"
        type="button"
        :aria-selected="tab.id === modelValue"
        :aria-controls="`sv-tabpanel-${tab.id}`"
        :aria-disabled="tab.disabled"
        :disabled="tab.disabled"
        :tabindex="index === (selectedIndex === -1 ? 0 : selectedIndex) ? 0 : -1"
        class="sv-focus-ring -mb-px min-h-sv-touch border-b-2 px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:text-sv-disabled-fg"
        :class="
          tab.id === modelValue
            ? 'border-sv-brand text-sv-text-heading'
            : 'border-transparent text-sv-text-muted hover:text-sv-text'
        "
        @click="select(tab)"
        @keydown="onKeydown($event, index)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!--
      Only the selected panel is rendered, so a hidden panel's controls can never be reached by
      Tab — a common and confusing failure of tab implementations that merely hide with CSS.
    -->
    <div
      v-for="tab in tabs.filter((t) => t.id === modelValue)"
      :id="`sv-tabpanel-${tab.id}`"
      :key="tab.id"
      role="tabpanel"
      :aria-labelledby="`sv-tab-${tab.id}`"
      tabindex="0"
      class="sv-focus-ring pt-4"
      data-testid="sv-tabpanel"
    >
      <slot :name="tab.id" />
      <slot />
    </div>
  </div>
</template>
