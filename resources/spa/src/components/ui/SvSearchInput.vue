<script setup lang="ts">
/**
 * SvSearchInput — a search field (Phase UI-04; UI/UX plan §14.1).
 *
 * `type="search"` with a real label, a Heroicon affordance and an explicit clear control that has
 * its own accessible name.
 *
 * It performs NO network activity. Debouncing is opt-in and only affects when `search` is
 * emitted; deciding what to fetch, and when, belongs to the caller. A generic visual component
 * that quietly issued requests would be impossible to reason about from the call site.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { SvIconClose, SvIconSearch } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    placeholder?: string;
    disabled?: boolean;
    /** Milliseconds to wait before emitting `search`. 0 emits immediately. */
    debounceMs?: number;
    /** Visually hide the label. The field keeps its accessible name. */
    labelHidden?: boolean;
  }>(),
  {
    modelValue: '',
    placeholder: undefined,
    disabled: false,
    debounceMs: 0,
    labelHidden: true,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
  /** Emitted after the debounce. The CALLER decides what, if anything, to fetch. */
  search: [value: string];
  clear: [];
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const timer = ref<ReturnType<typeof setTimeout> | null>(null);

const hasValue = computed(() => props.modelValue !== '');

function clearTimer(): void {
  if (timer.value !== null) {
    clearTimeout(timer.value);
    timer.value = null;
  }
}

function onInput(event: Event): void {
  const value = (event.target as HTMLInputElement).value;
  emit('update:modelValue', value);

  clearTimer();
  if (props.debounceMs === 0) {
    emit('search', value);

    return;
  }
  timer.value = setTimeout(() => emit('search', value), props.debounceMs);
}

function clear(): void {
  clearTimer();
  emit('update:modelValue', '');
  emit('clear');
  emit('search', '');
  // Focus returns to the field: clearing is a step in searching, not the end of it.
  inputRef.value?.focus();
}

onBeforeUnmount(clearTimer);
</script>

<template>
  <div>
    <label
      :for="id"
      class="text-sm font-medium text-sv-text"
      :class="labelHidden ? 'sr-only' : 'mb-1 block'"
    >{{ label }}</label>

    <div class="relative flex items-center">
      <SvIconSearch
        aria-hidden="true"
        class="pointer-events-none absolute left-3 h-5 w-5 text-sv-text-muted"
      />
      <input
        :id="id"
        ref="inputRef"
        type="search"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised py-2 pl-10 pr-12 text-sm text-sv-text placeholder:text-sv-text-muted disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg"
        data-testid="sv-search-input"
        @input="onInput"
      >
      <SvIconButton
        v-if="hasValue"
        :icon="SvIconClose"
        :label="`Clear ${label}`"
        size="sm"
        class="absolute right-1"
        data-testid="sv-search-clear"
        @click="clear"
      />
    </div>
  </div>
</template>
