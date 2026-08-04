<script setup lang="ts">
/**
 * LandingItemGrid — the sub-headed entries of a compiled section (Phase UI-06).
 *
 * Features, benefits, use cases and how-it-works steps are all written the same way in the approved
 * sources: a section headline followed by repeated sub-headings, each with a sentence under it.
 * Rendering that as a wall of markdown would technically be faithful and would read as a document
 * rather than a page, so the same data is laid out as cards — or, for a numbered sequence, as
 * steps that keep their ordinal.
 *
 * The `steps` variant uses an ordered list, because the order carries meaning there. The source's
 * own numbering ("1. Create your merchant account") stays in the title: renumbering it in the
 * markup and printing it again would show the number twice.
 */
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import type { LandingSectionItem } from '@/content/landing/landingSection';

withDefaults(
  defineProps<{
    items: readonly LandingSectionItem[];
    variant?: 'cards' | 'steps';
  }>(),
  { variant: 'cards' },
);
</script>

<template>
  <component
    :is="variant === 'steps' ? 'ol' : 'ul'"
    class="grid gap-4 md:grid-cols-2"
    :class="variant === 'cards' ? 'lg:grid-cols-3' : ''"
    data-testid="landing-item-grid"
  >
    <li
      v-for="item in items"
      :id="`item-${item.id}`"
      :key="item.id"
      class="rounded-card border border-sv-border bg-sv-surface-raised p-5"
    >
      <h3 class="font-display text-base font-bold text-sv-text-heading">
        {{ item.title }}
      </h3>
      <div class="mt-2 text-sm">
        <LandingBlocks
          :blocks="item.blocks"
          label-level="h4"
        />
      </div>
    </li>
  </component>
</template>
