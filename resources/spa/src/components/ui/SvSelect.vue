<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue?: string;
    options: { value: string; label: string }[];
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    errors?: string[];
  }>(),
  { required: false, disabled: false, errors: () => [], modelValue: '' },
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
    <select
      :id="id"
      :value="modelValue"
      :required="required"
      :disabled="disabled"
      :aria-required="required"
      :aria-invalid="hasError"
      :aria-describedby="hasError ? errorId : undefined"
      class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50 aria-[invalid=true]:border-error"
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
        v-for="opt in options"
        :key="opt.value"
        :value="opt.value"
      >
        {{ opt.label }}
      </option>
    </select>
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
