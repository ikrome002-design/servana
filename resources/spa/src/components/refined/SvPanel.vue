<script setup lang="ts">
/**
 * SvPanel — the refined section surface (COR-UI08-UI12-VIS-001).
 *
 * One consistent anatomy for every section of the five corrective accounts: an optional eyebrow,
 * a real heading (labelling the section landmark), a short description, an actions slot on the
 * same row, the body, and an optional footer. Pages previously hand-rolled this with ad-hoc
 * utility strings, which is how fifty slightly different cards accumulated.
 *
 * Presentation only: it renders what the page passes and decides nothing.
 */
import { useId } from 'vue';

withDefaults(
  defineProps<{
    title?: string;
    eyebrow?: string;
    description?: string;
    headingLevel?: 'h2' | 'h3';
    /** Decorative tone of the corner wash and eyebrow dot. Never carries meaning. */
    tone?: 'warm' | 'teal' | 'green' | 'sun' | 'neutral' | null;
    variant?: 'raised' | 'sunken' | 'dashed';
    flush?: boolean;
    as?: 'section' | 'div' | 'article' | 'aside';
  }>(),
  {
    title: undefined,
    eyebrow: undefined,
    description: undefined,
    headingLevel: 'h2',
    tone: null,
    variant: 'raised',
    flush: false,
    as: 'section',
  },
);

const headingId = `${useId()}-panel-title`;
</script>

<template>
  <component
    :is="as"
    class="sv-r-panel"
    :class="[
      tone ? `sv-r-panel--tonal sv-r-tone-${tone}` : '',
      variant === 'sunken' ? 'sv-r-panel--sunken' : '',
      variant === 'dashed' ? 'sv-r-panel--dashed' : '',
      flush ? 'sv-r-panel--flush' : '',
    ]"
    :aria-labelledby="title && (as === 'section' || as === 'aside') ? headingId : undefined"
    data-testid="sv-panel"
  >
    <header
      v-if="title || eyebrow || $slots.actions || $slots.heading"
      class="sv-r-panel__head"
    >
      <div class="min-w-0">
        <p
          v-if="eyebrow"
          class="sv-r-eyebrow"
          :class="tone ? 'sv-r-eyebrow--dot' : ''"
        >
          {{ eyebrow }}
        </p>
        <component
          :is="headingLevel"
          v-if="title"
          :id="headingId"
          class="sv-r-title"
          :class="eyebrow ? 'mt-1' : ''"
        >
          {{ title }}
        </component>
        <slot name="heading" />
        <p
          v-if="description || $slots.description"
          class="sv-r-panel__desc"
        >
          <slot name="description">
            {{ description }}
          </slot>
        </p>
      </div>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </header>

    <slot />

    <footer
      v-if="$slots.footer"
      class="sv-r-panel__foot"
    >
      <slot name="footer" />
    </footer>
  </component>
</template>
