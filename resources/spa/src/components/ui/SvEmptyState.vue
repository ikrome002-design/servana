<script setup lang="ts">
/**
 * SvEmptyState — "there is genuinely nothing here yet" (UI/UX plan §10; Plan §6.4).
 *
 * Distinct from SvErrorState (something failed), SvPermissionState (you may not see this) and
 * SvLockedState (this exists but is closed). Collapsing them would tell a user "no records" when
 * the truth is "you are not allowed to see the records", which is both wrong and a disclosure
 * question.
 *
 * Phase UI-04 (UI01-ASSET-001): the illustration was the emoji 📋. `icon` is now a Heroicons
 * component, not a string, so an emoji cannot be passed back in.
 */
import type { Component } from 'vue';
import { SvIconDocument } from '@/design-system/icons';

withDefaults(
  defineProps<{
    title: string;
    description?: string;
    actionLabel?: string;
    /** A Heroicons component. Typed as `Component` so a glyph string cannot be supplied. */
    icon?: Component;
  }>(),
  { description: undefined, actionLabel: undefined, icon: undefined },
);

const emit = defineEmits<{ action: [] }>();
</script>

<template>
  <div
    class="flex flex-col items-center py-12 text-center"
    data-testid="sv-empty-state"
  >
    <div
      aria-hidden="true"
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-pill bg-sv-surface-warm text-sv-text-muted"
    >
      <component
        :is="icon ?? SvIconDocument"
        class="h-8 w-8"
      />
    </div>
    <h3 class="font-display text-base font-bold text-sv-text-heading">
      {{ title }}
    </h3>
    <p
      v-if="description"
      class="mt-1 max-w-xs text-sm text-sv-text-muted"
    >
      {{ description }}
    </p>
    <button
      v-if="actionLabel"
      type="button"
      class="sv-focus-ring mt-6 inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
      @click="emit('action')"
    >
      {{ actionLabel }}
    </button>
  </div>
</template>
