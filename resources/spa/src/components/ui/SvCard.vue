<script setup lang="ts">
withDefaults(
  defineProps<{
    as?: string;
    padding?: 'none' | 'sm' | 'md' | 'lg';
  }>(),
  { as: 'div', padding: 'md' },
);
</script>

<template>
  <component
    :is="as"
    class="rounded-card border border-border bg-surface shadow-card"
    :class="{
      'p-0': padding === 'none',
      'p-4': padding === 'sm',
      'p-6': padding === 'md',
      'p-8': padding === 'lg',
    }"
  >
    <slot />
  </component>
</template>
