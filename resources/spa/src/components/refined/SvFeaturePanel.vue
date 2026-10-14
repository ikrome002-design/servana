<script setup lang="ts">
/**
 * SvFeaturePanel — the one dark, layered surface on a refined command centre
 * (COR-UI08-UI12-VIS-001; the Dashboard reference's feature card, in Servana's Deep Earth).
 *
 * A page uses at most one. It frames the role's primary state — never decoration around invented
 * data: every figure inside comes from the page, which received it from the server.
 */
import { useId } from 'vue';

withDefaults(
  defineProps<{
    eyebrow?: string;
    title?: string;
    headingLevel?: 'h2' | 'h3';
    as?: 'section' | 'div';
  }>(),
  { eyebrow: undefined, title: undefined, headingLevel: 'h2', as: 'section' },
);

const headingId = `${useId()}-feature-title`;
</script>

<template>
  <component
    :is="as"
    class="sv-r-feature"
    :aria-labelledby="title && as === 'section' ? headingId : undefined"
    data-testid="sv-feature-panel"
  >
    <div
      v-if="eyebrow || title || $slots.actions"
      class="flex flex-wrap items-start justify-between gap-4"
    >
      <div class="min-w-0">
        <p
          v-if="eyebrow"
          class="sv-r-feature__eyebrow"
        >
          {{ eyebrow }}
        </p>
        <component
          :is="headingLevel"
          v-if="title"
          :id="headingId"
          class="sv-r-feature__title"
        >
          {{ title }}
        </component>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap gap-2"
      >
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </component>
</template>
