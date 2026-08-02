<script setup lang="ts">
/**
 * SvLandingSection — a public landing-page section shell (Phase UI-04; UI/UX plan §8.3).
 *
 * STRUCTURE ONLY. UI-04 builds the container, the heading association, the responsive
 * content/media split and the image alt contract. It contains no copy, no imagery and no product
 * claim: compiling role content is UI-05 and building the eight landing pages is UI-06.
 *
 * The heading is associated with the section by `aria-labelledby`, so each section is a named
 * region a screen-reader user can navigate between — a landing page of anonymous `<section>`
 * elements is a wall with no doors.
 *
 * `imageAlt` is REQUIRED whenever an image is supplied, and an empty string is an explicit,
 * deliberate "this is decorative" rather than an omission.
 */
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    /** Section heading. Supplied by the content layer in UI-05/UI-06, never authored here. */
    heading: string;
    /** `h2` under the page `h1`. Adjustable so a nested section does not break the outline. */
    headingLevel?: 'h2' | 'h3';
    eyebrow?: string;
    /** Media URL. Selection and optimisation of landing imagery belongs to UI-05. */
    imageSrc?: string | null;
    /** REQUIRED with an image. `''` is an explicit decorative declaration. */
    imageAlt?: string;
    /** Which side the media sits on from tablet up. Mobile always stacks. */
    mediaPosition?: 'start' | 'end';
    /** Alternate surface for visual rhythm between adjacent sections. */
    tone?: 'page' | 'subtle' | 'warm';
  }>(),
  {
    headingLevel: 'h2',
    eyebrow: undefined,
    imageSrc: null,
    imageAlt: undefined,
    mediaPosition: 'end',
    tone: 'page',
  },
);

/** Deterministic, so the heading association is stable across renders and testable. */
const headingId = computed(
  () => `sv-landing-${props.heading.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')}`,
);

const hasImage = computed(() => typeof props.imageSrc === 'string' && props.imageSrc !== '');
</script>

<template>
  <section
    :aria-labelledby="headingId"
    class="px-4 py-12 md:px-6 md:py-16 lg:px-8"
    :class="{
      'bg-sv-surface-page': tone === 'page',
      'bg-sv-surface-subtle': tone === 'subtle',
      'bg-sv-surface-warm': tone === 'warm',
    }"
    data-testid="sv-landing-section"
  >
    <div
      class="mx-auto flex max-w-sv-content flex-col gap-8 lg:flex-row lg:items-center"
      :class="hasImage && mediaPosition === 'start' ? 'lg:flex-row-reverse' : ''"
    >
      <div class="min-w-0 flex-1">
        <p
          v-if="eyebrow"
          class="text-xs font-semibold uppercase tracking-wide text-sv-brand-secondary"
        >
          {{ eyebrow }}
        </p>

        <component
          :is="headingLevel"
          :id="headingId"
          class="mt-2 font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
        >
          {{ heading }}
        </component>

        <div class="mt-4 max-w-sv-readable text-sv-text-secondary">
          <slot />
        </div>

        <div
          v-if="$slots.actions"
          class="mt-6 flex flex-col gap-3 md:flex-row md:items-center"
        >
          <slot name="actions" />
        </div>
      </div>

      <div
        v-if="hasImage"
        class="min-w-0 flex-1"
      >
        <!--
          `imageAlt` is required with an image. An empty string is a deliberate decorative
          declaration; a missing one is a defect the caller must fix, not something to guess at.
        -->
        <img
          :src="imageSrc ?? ''"
          :alt="imageAlt ?? ''"
          class="h-auto w-full max-w-full rounded-card object-cover"
          loading="lazy"
          decoding="async"
        >
      </div>
    </div>
  </section>
</template>
