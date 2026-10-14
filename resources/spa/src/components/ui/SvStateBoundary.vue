<script setup lang="ts">
import { SvIconDocument, SvIconWarning } from '@/design-system/icons';
defineProps<{
  state: 'loading' | 'empty' | 'error' | 'success';
  errorMessage?: string;
  emptyMessage?: string;
  emptyAction?: string;
}>();

const emit = defineEmits<{
  retry: [];
  'empty-action': [];
}>();
</script>

<template>
  <!-- Loading state: skeleton, not spinner-only (Plan §6.4). -->
  <div
    v-if="state === 'loading'"
    role="status"
    aria-busy="true"
    aria-label="Loading…"
    class="sv-state sv-state--loading space-y-3"
  >
    <div class="h-4 w-3/4 animate-pulse rounded-control bg-surface-alt" />
    <div class="h-4 w-1/2 animate-pulse rounded-control bg-surface-alt" />
    <div class="h-4 w-2/3 animate-pulse rounded-control bg-surface-alt" />
  </div>

  <!-- Empty state: warm, with primary action (Plan §6.4). -->
  <div
    v-else-if="state === 'empty'"
    class="sv-state sv-state--empty flex flex-col items-center py-12 text-center"
  >
    <div
      aria-hidden="true"
      class="sv-state__icon mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-cream text-3xl"
    >
      <SvIconDocument class="h-8 w-8" />
    </div>
    <p class="sv-state__message text-sm font-medium text-text">
      {{ emptyMessage ?? 'Nothing here yet.' }}
    </p>
    <button
      v-if="emptyAction"
      type="button"
      class="mt-4 inline-flex min-h-[44px] items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      @click="emit('empty-action')"
    >
      {{ emptyAction }}
    </button>
  </div>

  <!-- Error state: retry affordance (Plan §6.4). -->
  <div
    v-else-if="state === 'error'"
    class="sv-state sv-state--error flex flex-col items-center py-12 text-center"
    role="alert"
  >
    <div
      aria-hidden="true"
      class="sv-state__icon mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-red-50 text-3xl dark:bg-red-900/20"
    >
      <SvIconWarning class="h-8 w-8" />
    </div>
    <p class="text-sm font-medium text-error">
      {{ errorMessage ?? 'Something went wrong.' }}
    </p>
    <button
      type="button"
      class="mt-4 inline-flex min-h-[44px] items-center rounded-control border border-border px-4 py-2 text-sm font-medium text-text hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      @click="emit('retry')"
    >
      Try again
    </button>
  </div>

  <!--
    Success state: render slot content.

    UI16-LAYOUT-001: the slot used to render bare. A caller's `class` (56 call sites pass one, most
    a top margin) therefore applied in the loading, empty and error states — which render a single
    root — and silently vanished once the data arrived, because a multi-root slot cannot inherit
    attributes. Content then butted against whatever preceded it. One wrapper makes the success
    state honour the same spacing as the other three.
  -->
  <div
    v-else
    data-testid="sv-state-success"
  >
    <slot />
  </div>
</template>
