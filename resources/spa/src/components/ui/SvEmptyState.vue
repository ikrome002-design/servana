<script setup lang="ts">
defineProps<{
  title: string;
  description?: string;
  actionLabel?: string;
  icon?: string;
}>();

const emit = defineEmits<{ action: [] }>();
</script>

<template>
  <div class="flex flex-col items-center py-12 text-center">
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-cream text-3xl"
    >
      {{ icon ?? '📋' }}
    </div>
    <h3 class="font-display text-base font-bold text-heading">
      {{ title }}
    </h3>
    <p
      v-if="description"
      class="mt-1 max-w-xs text-sm text-text-muted"
    >
      {{ description }}
    </p>
    <button
      v-if="actionLabel"
      type="button"
      class="mt-6 inline-flex min-h-[44px] items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      @click="emit('action')"
    >
      {{ actionLabel }}
    </button>
  </div>
</template>
