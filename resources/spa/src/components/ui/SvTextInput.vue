<script setup lang="ts">
/**
 * SvTextInput — a single-line text control (Phase UI-04; UI/UX plan §14.1, §14.2).
 *
 * A thin, honest wrapper over `<input>`: all association is delegated to `SvFormField`, so this
 * component owns only the control itself and its states.
 *
 * It never reformats the value as the user types. A control that rewrites input mid-keystroke
 * changes what will be submitted without the user agreeing to it; normalisation belongs to an
 * explicit, visible step (see `SvPhoneInput`) and validation authority belongs to the server.
 */
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';

withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    /**
     * The native input type. A CLOSED vocabulary — the legacy component accepted any string,
     * which is how `type="type"` reached production in three templates.
     *
     * `date`, `time` and `datetime-local` are included because the product genuinely uses the
     * native pickers (32 date and 14 time inputs today), and `SvDatePicker` wraps `date` rather
     * than reimplementing a calendar. `search` is included for `SvSearchInput`.
     *
     * `checkbox`, `radio` and `file` are deliberately ABSENT: those have their own components
     * with their own semantics, and letting a text field render them would bypass those.
     */
    type?:
      | 'text'
      | 'email'
      | 'tel'
      | 'url'
      | 'number'
      | 'password'
      | 'date'
      | 'time'
      | 'datetime-local'
      | 'search';
    /** Hint text only. NEVER a replacement for the label (UI/UX plan §14.2). */
    placeholder?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    /** Native autocomplete token, e.g. `email`, `tel`, `name`. */
    autocomplete?: string;
    inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'url' | 'search';
    maxlength?: number;
    labelHidden?: boolean;
  }>(),
  {
    modelValue: '',
    type: 'text',
    placeholder: undefined,
    help: undefined,
    errors: () => [],
    message: undefined,
    status: 'default',
    required: false,
    disabled: false,
    readonly: false,
    autocomplete: undefined,
    inputmode: undefined,
    maxlength: undefined,
    labelHidden: false,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>();
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
      <input
        v-bind="field"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :maxlength="maxlength"
        class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-sm text-sv-text placeholder:text-sv-text-muted read-only:bg-sv-surface-subtle disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
        data-testid="sv-text-input"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        @blur="emit('blur')"
      >
    </template>
  </SvFormField>
</template>
