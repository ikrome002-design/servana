<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    placeholder?: string;
    rows?: number;
    required?: boolean;
    disabled?: boolean;
    errors?: string[];
  }>(),
  { rows: 3, required: false, disabled: false, errors: () => [], modelValue: '' },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const errorId = computed(() => `${props.id}-error`);
const hasError = computed(() => props.errors.length > 0);
</script>

<template>
  <div class="flex flex-col gap-1">
    <label
      :for="id"
      class="text-sm font-medium text-text"
    >
      {{ label }}
      <span
        v-if="required"
        aria-hidden="true"
        class="ml-0.5 text-error"
      >*</span>
    </label>
    <textarea
      :id="id"
      :value="modelValue"
      :rows="rows"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :aria-required="required"
      :aria-invalid="hasError"
      :aria-describedby="hasError ? errorId : undefined"
      class="w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-text placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50 aria-[invalid=true]:border-error"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />
    <p
      v-if="hasError"
      :id="errorId"
      role="alert"
      class="text-xs text-error"
    >
      {{ errors[0] }}
    </p>
  </div>
</template>
