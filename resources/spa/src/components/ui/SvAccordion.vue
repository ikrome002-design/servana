<script setup lang="ts">
/**
 * SvAccordion — a disclosure group (Phase UI-04; UI/UX plan §10).
 *
 * Distinct from `SvFaq`, which renders approved FAQ content on native `<details>`. This one is
 * for application panels where the caller controls open state and needs the ARIA disclosure
 * relationship (`aria-expanded` / `aria-controls`).
 *
 * `headingLevel` exists so the accordion does not corrupt the document outline. Each header is a
 * heading element containing a button — the required pattern, because a bare button is not a
 * heading and a bare heading is not operable.
 *
 * `multiple` is typed rather than implicit: single-open and multi-open behave differently for a
 * keyboard user, and guessing produces the wrong one half the time.
 */
import { computed } from 'vue';
import { SvIconChevronDown } from '@/design-system/icons';

export interface SvAccordionItem {
  id: string;
  label: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{
    items: SvAccordionItem[];
    /** Open item ids (controlled). */
    modelValue: string[];
    /** Allow more than one open panel at a time. */
    multiple?: boolean;
    headingLevel?: 'h2' | 'h3' | 'h4';
  }>(),
  { multiple: false, headingLevel: 'h3' },
);

const emit = defineEmits<{ 'update:modelValue': [ids: string[]] }>();

const openIds = computed(() => new Set(props.modelValue));

function toggle(item: SvAccordionItem): void {
  if (item.disabled === true) {
    return;
  }

  if (openIds.value.has(item.id)) {
    emit('update:modelValue', props.modelValue.filter((id) => id !== item.id));

    return;
  }

  emit('update:modelValue', props.multiple ? [...props.modelValue, item.id] : [item.id]);
}
</script>

<template>
  <div
    class="divide-y divide-sv-border rounded-card border border-sv-border"
    data-testid="sv-accordion"
  >
    <div
      v-for="item in items"
      :key="item.id"
    >
      <!-- A heading CONTAINING a button: the button is operable, the heading keeps the outline. -->
      <component :is="headingLevel">
        <button
          :id="`sv-accordion-header-${item.id}`"
          type="button"
          :aria-expanded="openIds.has(item.id)"
          :aria-controls="`sv-accordion-panel-${item.id}`"
          :disabled="item.disabled"
          class="sv-focus-ring flex min-h-sv-touch w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm font-medium text-sv-text disabled:cursor-not-allowed disabled:text-sv-disabled-fg"
          @click="toggle(item)"
        >
          {{ item.label }}
          <SvIconChevronDown
            aria-hidden="true"
            class="h-5 w-5 shrink-0 text-sv-text-muted transition-transform duration-sv-fast motion-reduce:transition-none"
            :class="openIds.has(item.id) ? 'rotate-180' : ''"
          />
        </button>
      </component>

      <div
        v-if="openIds.has(item.id)"
        :id="`sv-accordion-panel-${item.id}`"
        role="region"
        :aria-labelledby="`sv-accordion-header-${item.id}`"
        class="border-t border-sv-border px-4 py-3 text-sm text-sv-text-secondary"
      >
        <slot :name="item.id" />
      </div>
    </div>
  </div>
</template>
