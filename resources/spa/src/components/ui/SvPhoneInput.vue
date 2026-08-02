<script setup lang="ts">
/**
 * SvPhoneInput — a phone-number field (Phase UI-04; Plan §35).
 *
 * The design decision that matters: this component does NOT rewrite what the user typed. Inputs
 * that reformat mid-keystroke fight the user's cursor, break paste, and — worse here — change
 * what will be submitted without the user agreeing to it.
 *
 * Instead the raw entry is preserved and the canonical form is shown BELOW the field as a
 * preview, so the user can see `+254712345678` while still holding `0712 345 678`. The preview
 * mirrors the server's own normaliser (parity-tested), but it is not authoritative: the backend
 * normalises and validates, and its answer is the one that is stored.
 *
 * It never fabricates a number. Input with no digits produces no preview at all.
 */
import { computed } from 'vue';
import SvFormField, { type SvFieldStatus } from '@/components/ui/SvFormField.vue';
import { previewNormalizedPhone } from '@/utils/phone';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    help?: string;
    errors?: string[];
    message?: string;
    status?: SvFieldStatus;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    placeholder?: string;
    /** Show the canonical-form preview. On by default; off where space is tight. */
    showPreview?: boolean;
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
    placeholder: '07XX XXX XXX',
    showPreview: true,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string]; blur: [] }>();

/** Null when there is nothing to preview — never a fabricated number. */
const preview = computed(() => previewNormalizedPhone(props.modelValue));

/** Only worth showing once it differs from what the user can already see. */
const showsPreview = computed(
  () => props.showPreview && preview.value !== null && preview.value !== props.modelValue.trim(),
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
  >
    <template #default="field">
      <div>
        <input
          v-bind="field"
          type="tel"
          inputmode="tel"
          autocomplete="tel"
          :value="modelValue"
          :placeholder="placeholder"
          class="sv-focus-ring min-h-sv-control w-full rounded-control border border-sv-border-input bg-sv-surface-raised px-3 py-2 text-sm text-sv-text placeholder:text-sv-text-muted read-only:bg-sv-surface-subtle disabled:border-sv-disabled-border disabled:bg-sv-disabled-bg disabled:text-sv-disabled-fg aria-[invalid=true]:border-sv-error-border"
          data-testid="sv-phone-input"
          @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
          @blur="emit('blur')"
        >

        <!--
          A PREVIEW, announced politely. The field's own value is untouched; the server performs
          the authoritative normalisation and validation.
        -->
        <p
          v-if="showsPreview"
          aria-live="polite"
          class="mt-1 text-xs text-sv-text-muted"
          data-testid="sv-phone-preview"
        >
          Will be saved as <span class="sv-numeric font-medium text-sv-text">{{ preview }}</span>
        </p>
      </div>
    </template>
  </SvFormField>
</template>
