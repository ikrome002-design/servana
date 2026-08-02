<script setup lang="ts">
/**
 * SvCheckbox — a single boolean control (Phase UI-04; UI/UX plan §14.1).
 *
 * A NATIVE `<input type="checkbox">`, not a styled div with `role="checkbox"`. The native control
 * already carries the role, the state, keyboard activation and form participation; reimplementing
 * it with ARIA is more code and strictly worse.
 *
 * Not a switch. A switch means "this takes effect immediately"; a checkbox means "this is part of
 * what I will submit". Conflating them tells the user the wrong thing about when their change
 * lands. `SvThemeToggle` is the switch; this is not.
 *
 * `indeterminate` is supported because a parent "select all" genuinely has three states, and it
 * must be set as a DOM property — the attribute does not exist.
 */
import { computed, onMounted, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: boolean;
    help?: string;
    errors?: string[];
    disabled?: boolean;
    /** Partially-checked parent, e.g. "select all" with some rows chosen. */
    indeterminate?: boolean;
  }>(),
  {
    modelValue: false,
    help: undefined,
    errors: () => [],
    disabled: false,
    indeterminate: false,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

const inputRef = ref<HTMLInputElement | null>(null);

const helpId = computed(() => `${props.id}-help`);
const errorId = computed(() => `${props.id}-error`);
const hasError = computed(() => props.errors.length > 0);

const describedBy = computed(() => {
  const ids: string[] = [];
  if (props.help !== undefined && props.help !== '') {
    ids.push(helpId.value);
  }
  if (hasError.value) {
    ids.push(errorId.value);
  }

  return ids.length > 0 ? ids.join(' ') : undefined;
});

/**
 * `indeterminate` has no HTML attribute — it exists only as a DOM property.
 *
 * `onMounted` handles the initial value and `watch` handles later changes. An `immediate` watcher
 * would NOT work: the immediate call runs during setup, before the template ref is populated, so
 * a checkbox mounted already-indeterminate would silently render unchecked instead.
 */
function applyIndeterminate(): void {
  if (inputRef.value !== null) {
    inputRef.value.indeterminate = props.indeterminate;
  }
}

onMounted(applyIndeterminate);
watch(() => props.indeterminate, applyIndeterminate);
</script>

<template>
  <div class="flex flex-col gap-1">
    <div class="flex items-start gap-3">
      <input
        :id="id"
        ref="inputRef"
        type="checkbox"
        :checked="modelValue"
        :disabled="disabled"
        :aria-describedby="describedBy"
        :aria-invalid="hasError ? true : undefined"
        class="sv-focus-ring mt-0.5 h-5 w-5 shrink-0 rounded border-sv-border-input text-sv-brand disabled:border-sv-disabled-border"
        data-testid="sv-checkbox"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      >
      <label
        :for="id"
        class="text-sm text-sv-text"
        :class="disabled ? 'text-sv-disabled-fg' : ''"
      >{{ label }}</label>
    </div>

    <p
      v-if="help"
      :id="helpId"
      class="pl-8 text-xs text-sv-text-muted"
    >
      {{ help }}
    </p>
    <p
      v-if="hasError"
      :id="errorId"
      role="alert"
      class="pl-8 text-xs text-sv-error-fg"
    >
      {{ errors[0] }}
    </p>
  </div>
</template>
