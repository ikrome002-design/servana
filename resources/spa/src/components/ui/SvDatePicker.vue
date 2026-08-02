<script setup lang="ts">
/**
 * SvDatePicker — a business-date field (Phase UI-04; Plan §2 AS-3).
 *
 * Wraps the NATIVE `<input type="date">`. That is a deliberate decision, not a shortcut:
 *
 *  - the repository already uses native date inputs in 32 places, so this matches what ships;
 *  - the native control is fully keyboard operable, localised, and screen-reader supported by the
 *    platform, which a hand-rolled calendar would have to re-earn in full;
 *  - no date library is added, so the bundle and the audit surface do not grow.
 *
 * A partial custom calendar would be worse than the native one, and a complete accessible
 * calendar is a large component with no proven requirement behind it. If a future phase proves a
 * requirement the native input cannot meet, it should build a complete one then.
 *
 * The value is a DATE-ONLY `YYYY-MM-DD` string, passed through untouched. It is never parsed into
 * a `Date`, because doing so interprets it in the browser's timezone and can shift a Kenyan
 * business date by a day.
 */
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';

withDefaults(
  defineProps<{
    id: string;
    label: string;
    /** `YYYY-MM-DD`, or empty. Never converted through a Date object. */
    modelValue?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    /** `YYYY-MM-DD` bounds. A native constraint only — the server still validates. */
    min?: string;
    max?: string;
  }>(),
  {
    modelValue: '',
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    readonly: false,
    min: undefined,
    max: undefined,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
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
    :readonly="readonly"
  >
    <template #default="field">
      <input
        v-bind="field"
        type="date"
        :value="modelValue"
        :min="min"
        :max="max"
        class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-sm text-sv-text read-only:bg-sv-surface-subtle disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
        data-testid="sv-date-picker"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      >
    </template>
  </SvFormField>
</template>
