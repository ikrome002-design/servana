<script setup lang="ts">
/**
 * SvTextArea — a multi-line text control (Phase UI-04; UI/UX plan §14.1).
 *
 * Same contract as `SvTextInput`: association is owned by `SvFormField`, the value is never
 * silently reformatted, and the server remains the validation authority.
 *
 * Phase UI-04 renamed this from `SvTextarea` to the canonical contract name and moved its
 * hand-rolled label/error wiring onto `SvFormField`, so the three association strategies that
 * previously existed across the input components became one.
 *
 * The character counter appears only when a `maxlength` is set AND the user is close to it. A
 * counter that is always visible is noise; one that appears near the limit is information. It is
 * a polite live region so it does not interrupt typing.
 */
import { computed } from 'vue';
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    placeholder?: string;
    rows?: number;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    maxlength?: number;
    labelHidden?: boolean;
  }>(),
  {
    modelValue: '',
    placeholder: undefined,
    rows: 3,
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    readonly: false,
    maxlength: undefined,
    labelHidden: false,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>();

/** Shown only in the last 10% of the allowance — informative, not constant noise. */
const showCounter = computed(
  () => props.maxlength !== undefined && props.modelValue.length >= props.maxlength * 0.9,
);
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
    :label-hidden="labelHidden"
  >
    <template #default="field">
      <div>
        <textarea
          v-bind="field"
          :value="modelValue"
          :rows="rows"
          :placeholder="placeholder"
          :maxlength="maxlength"
          class="sv-focus-ring w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-sm text-sv-text placeholder:text-sv-text-muted read-only:bg-sv-surface-subtle disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
          data-testid="sv-text-area"
          @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
          @blur="emit('blur')"
        />
        <p
          v-if="showCounter"
          aria-live="polite"
          class="mt-1 text-right text-xs text-sv-text-muted"
          data-testid="sv-text-area-counter"
        >
          {{ modelValue.length }} of {{ maxlength }} characters
        </p>
      </div>
    </template>
  </SvFormField>
</template>
