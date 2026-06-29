<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'destructive';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
  }>(),
  { variant: 'primary', type: 'button', loading: false, disabled: false },
);
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-disabled="disabled || loading"
    :aria-busy="loading"
    class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center gap-2 rounded-control px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50"
    :class="{
      'bg-primary text-brand-deep hover:bg-orange-400': variant === 'primary',
      'border border-border bg-transparent text-text hover:bg-surface-alt': variant === 'secondary',
      'bg-transparent text-text hover:bg-surface-alt': variant === 'ghost',
      'bg-red-700 text-white hover:bg-red-800': variant === 'destructive',
    }"
  >
    <span
      v-if="loading"
      aria-hidden="true"
      class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
    />
    <slot />
  </button>
</template>
