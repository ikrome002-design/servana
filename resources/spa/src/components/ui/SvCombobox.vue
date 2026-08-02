<script setup lang="ts">
/**
 * SvCombobox — a filterable single-select (Phase UI-04; WAI-ARIA combobox pattern).
 *
 * Used ONLY where a native `<select>` genuinely cannot serve: a long list that needs type-ahead
 * filtering, or options loaded from the server. `SvSelect` remains the default, because a native
 * select is better on mobile and free of the failure modes below.
 *
 * The pattern is implemented completely rather than partially, because a half-built combobox is
 * worse than a select:
 *  - `role="combobox"` on the INPUT with `aria-expanded`, `aria-controls` and `aria-autocomplete`;
 *  - `aria-activedescendant` pointing at the highlighted option, so focus stays in the input and
 *    the user can keep typing while arrowing;
 *  - deterministic option ids derived from option values;
 *  - Down/Up cycle, Home/End jump, Enter commits, Escape closes and keeps focus in the field;
 *  - loading and no-results are DISTINCT announced states — "no results" while still loading is a
 *    false statement;
 *  - the selection is announced politely on commit.
 *
 * Option labels are plain text. No caller HTML is rendered.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';
import { SvIconChevronDown } from '@/design-system/icons';

export interface SvComboboxOption {
  value: string;
  label: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    options: SvComboboxOption[];
    modelValue?: string;
    placeholder?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    /** Options are being fetched. Distinct from "no results". */
    loading?: boolean;
    noResultsLabel?: string;
  }>(),
  {
    modelValue: '',
    placeholder: undefined,
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    loading: false,
    noResultsLabel: 'No matches found.',
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; filter: [query: string] }>();

const open = ref(false);
const query = ref('');
const activeIndex = ref(-1);
const rootRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);

const listboxId = computed(() => `${props.id}-listbox`);
const optionId = (value: string): string => `${props.id}-option-${value}`;

const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);

/** Filtering is local; a server-driven caller can ignore it and re-supply `options` on `filter`. */
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (q === '') {
    return props.options;
  }

  return props.options.filter((option) => option.label.toLowerCase().includes(q));
});

const selectableIndices = computed(() =>
  filtered.value.map((option, index) => (option.disabled === true ? -1 : index)).filter((index) => index >= 0),
);

/** What the input displays: the live query while open, the selected label when closed. */
const displayValue = computed(() => (open.value ? query.value : selectedOption.value?.label ?? ''));

/** Announced on commit, so a selection made by keyboard is confirmed audibly. */
const announcement = ref('');

function openList(): void {
  if (props.disabled) {
    return;
  }
  open.value = true;
  query.value = '';
  activeIndex.value = selectableIndices.value[0] ?? -1;
}

function closeList(): void {
  open.value = false;
  activeIndex.value = -1;
}

function commit(index: number): void {
  const option = filtered.value[index];
  if (option === undefined || option.disabled === true) {
    return;
  }
  emit('update:modelValue', option.value);
  announcement.value = `${option.label} selected.`;
  closeList();
  inputRef.value?.focus();
}

function onInput(event: Event): void {
  query.value = (event.target as HTMLInputElement).value;
  open.value = true;
  activeIndex.value = selectableIndices.value[0] ?? -1;
  emit('filter', query.value);
}

function onKeydown(event: KeyboardEvent): void {
  if (!open.value && (event.key === 'ArrowDown' || event.key === 'Enter')) {
    event.preventDefault();
    openList();

    return;
  }

  const indices = selectableIndices.value;
  const position = indices.indexOf(activeIndex.value);

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      if (indices.length > 0) {
        activeIndex.value = indices[(position + 1) % indices.length];
      }
      break;
    case 'ArrowUp':
      event.preventDefault();
      if (indices.length > 0) {
        activeIndex.value = indices[(position - 1 + indices.length) % indices.length];
      }
      break;
    case 'Home':
      event.preventDefault();
      activeIndex.value = indices[0] ?? -1;
      break;
    case 'End':
      event.preventDefault();
      activeIndex.value = indices[indices.length - 1] ?? -1;
      break;
    case 'Enter':
      event.preventDefault();
      commit(activeIndex.value);
      break;
    case 'Escape':
      event.preventDefault();
      // Focus deliberately stays in the field: Escape cancels the list, not the field.
      closeList();
      break;
    default:
      break;
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (open.value && rootRef.value?.contains(event.target as Node | null) !== true) {
    closeList();
  }
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
  <SvFormField
    :id="id"
    :label="label"
    :help="help"
    :errors="errors"
    :message="message"
    :status="status"
    :required="required"
    :disabled="disabled"
  >
    <template #default="field">
      <div
        ref="rootRef"
        class="relative"
      >
        <div class="flex items-stretch">
          <input
            v-bind="field"
            ref="inputRef"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="listboxId"
            :aria-activedescendant="
              open && activeIndex >= 0 && filtered[activeIndex] ? optionId(filtered[activeIndex].value) : undefined
            "
            :value="displayValue"
            :placeholder="placeholder"
            class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 pr-10 text-sm text-sv-text placeholder:text-sv-text-muted disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
            data-testid="sv-combobox"
            @input="onInput"
            @keydown="onKeydown"
            @click="openList"
          >
          <SvIconChevronDown
            aria-hidden="true"
            class="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-sv-text-muted"
          />
        </div>

        <ul
          v-if="open"
          :id="listboxId"
          role="listbox"
          :aria-label="label"
          class="absolute z-sv-popover mt-1 max-h-60 w-full overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised py-1 shadow-overlay"
          data-testid="sv-combobox-listbox"
        >
          <!-- Loading and empty are DISTINCT: "no matches" while still fetching would be a lie. -->
          <li
            v-if="loading"
            class="px-3 py-2 text-sm text-sv-text-muted"
            data-testid="sv-combobox-loading"
          >
            Loading options…
          </li>
          <li
            v-else-if="filtered.length === 0"
            class="px-3 py-2 text-sm text-sv-text-muted"
            data-testid="sv-combobox-empty"
          >
            {{ noResultsLabel }}
          </li>
          <li
            v-for="(option, index) in filtered"
            v-else
            :id="optionId(option.value)"
            :key="option.value"
            role="option"
            :aria-selected="option.value === modelValue"
            :aria-disabled="option.disabled"
            class="cursor-pointer px-3 py-2 text-sm"
            :class="[
              index === activeIndex ? 'bg-sv-selected-bg text-sv-selected-fg' : 'text-sv-text',
              option.disabled === true ? 'cursor-not-allowed text-sv-disabled-fg' : '',
            ]"
            @click="commit(index)"
            @mousemove="activeIndex = index"
          >
            {{ option.label }}
          </li>
        </ul>

        <p
          aria-live="polite"
          class="sr-only"
          data-testid="sv-combobox-announcement"
        >
          {{ announcement }}
        </p>
      </div>
    </template>
  </SvFormField>
</template>
