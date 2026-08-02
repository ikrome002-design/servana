<script setup lang="ts">
/**
 * SvRadioGroup — a single choice from a set (Phase UI-04; UI/UX plan §14.1).
 *
 * Native radios inside a `<fieldset>` with a `<legend>`. The fieldset/legend pairing is what gives
 * the GROUP an accessible name — without it a screen reader announces "Weekly, radio button, 2 of
 * 4" with no idea what the choice is about. A `div` with `role="radiogroup"` would work too, but
 * the native element already does it and participates in form submission correctly.
 *
 * Native radios also bring roving arrow-key navigation for free, which is why no keyboard handler
 * appears here: adding one would fight the browser.
 */
import { computed } from 'vue';

export interface SvRadioOption {
  value: string;
  label: string;
  help?: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{
    /** Base id. Each option derives a deterministic id from it. */
    id: string;
    /** The group's accessible name. */
    legend: string;
    options: SvRadioOption[];
    modelValue?: string;
    help?: string;
    errors?: string[];
    required?: boolean;
    disabled?: boolean;
    /** Lay options out horizontally from tablet up. Mobile always stacks. */
    inline?: boolean;
  }>(),
  {
    modelValue: '',
    help: undefined,
    errors: () => [],
    required: false,
    disabled: false,
    inline: false,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

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
</script>

<template>
  <fieldset
    :aria-describedby="describedBy"
    :aria-invalid="hasError ? true : undefined"
    :aria-required="required ? true : undefined"
    :disabled="disabled"
    class="flex flex-col gap-2"
    data-testid="sv-radio-group"
  >
    <legend class="text-sm font-medium text-sv-text">
      {{ legend }}
      <span
        v-if="required"
        aria-hidden="true"
        class="ml-0.5 text-sv-error-fg"
      >*</span>
    </legend>

    <p
      v-if="help"
      :id="helpId"
      class="text-xs text-sv-text-muted"
    >
      {{ help }}
    </p>

    <div
      class="flex flex-col gap-2"
      :class="inline ? 'md:flex-row md:flex-wrap md:gap-6' : ''"
    >
      <div
        v-for="option in options"
        :key="option.value"
        class="flex items-start gap-3"
      >
        <input
          :id="`${id}-${option.value}`"
          type="radio"
          :name="id"
          :value="option.value"
          :checked="modelValue === option.value"
          :disabled="option.disabled"
          :aria-describedby="option.help ? `${id}-${option.value}-help` : undefined"
          class="sv-focus-ring mt-0.5 h-5 w-5 shrink-0 border-sv-border-input text-sv-brand"
          @change="emit('update:modelValue', option.value)"
        >
        <div class="min-w-0">
          <label
            :for="`${id}-${option.value}`"
            class="text-sm text-sv-text"
          >{{ option.label }}</label>
          <p
            v-if="option.help"
            :id="`${id}-${option.value}-help`"
            class="text-xs text-sv-text-muted"
          >
            {{ option.help }}
          </p>
        </div>
      </div>
    </div>

    <p
      v-if="hasError"
      :id="errorId"
      role="alert"
      class="text-xs text-sv-error-fg"
    >
      {{ errors[0] }}
    </p>
  </fieldset>
</template>
