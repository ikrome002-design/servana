<script setup lang="ts">
/**
 * SvSelect — a single choice from a known set (Phase UI-04; UI/UX plan §14.1).
 *
 * A NATIVE `<select>`. This is the default choice for Servana, not a fallback: the native control
 * is better on mobile (the platform picker), works without JavaScript, is keyboard and
 * screen-reader correct by construction, and cannot develop the half-implemented listbox bugs a
 * custom widget accumulates. `SvCombobox` exists for the genuinely different case — filtering a
 * long or server-loaded list — not for styling.
 *
 * Phase UI-04 moved its hand-rolled label/error wiring onto `SvFormField`, so all form controls
 * now share one association strategy, and added option groups and per-option disabling.
 */
import { computed } from 'vue';
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';

export interface SvSelectOption {
  value: string;
  label: string;
  disabled?: boolean;
  /** Optional `<optgroup>` label. Options sharing a group are rendered together. */
  group?: string;
}

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    options: SvSelectOption[];
    /** Shown as a disabled first option. Never a substitute for the label. */
    placeholder?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    labelHidden?: boolean;
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
    labelHidden: false,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const ungrouped = computed(() => props.options.filter((option) => option.group === undefined));

/** Groups in first-appearance order, so the rendered order is deterministic. */
const groups = computed(() => {
  const names: string[] = [];
  for (const option of props.options) {
    if (option.group !== undefined && !names.includes(option.group)) {
      names.push(option.group);
    }
  }

  return names.map((name) => ({
    name,
    options: props.options.filter((option) => option.group === name),
  }));
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
    :label-hidden="labelHidden"
  >
    <template #default="field">
      <select
        v-bind="field"
        :value="modelValue"
        class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-sm text-sv-text disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
        data-testid="sv-select"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <option
          v-if="placeholder"
          value=""
          disabled
        >
          {{ placeholder }}
        </option>

        <option
          v-for="option in ungrouped"
          :key="option.value"
          :value="option.value"
          :disabled="option.disabled"
        >
          {{ option.label }}
        </option>

        <optgroup
          v-for="group in groups"
          :key="group.name"
          :label="group.name"
        >
          <option
            v-for="option in group.options"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
          >
            {{ option.label }}
          </option>
        </optgroup>
      </select>
    </template>
  </SvFormField>
</template>
