<script setup lang="ts">
/**
 * LandingPicture — one curated landing image (Phase UI-06; UI/UX plan §8.7, §22.2).
 *
 * Renders a `LandingImage` from the UI-05 manifest exactly as the manifest describes it. Every
 * property below exists because omitting it causes a specific, observable defect:
 *
 *  - **AVIF and WebP `<source>` candidates before the original.** The supplied artwork is a
 *    one-to-two-megabyte PNG. The browser picks the first format it supports at a width that suits
 *    the viewport; the untouched original stays as the `<img>` fallback, so nothing depends on
 *    format support.
 *  - **Intrinsic `width`/`height` plus an `aspect-ratio` box.** The browser reserves the right
 *    space before any bytes arrive. UI-05 found the previous surface declaring 800×600 for files
 *    that are 1672×941 — which mis-reserves the space and IS the layout shift, not a cosmetic slip.
 *  - **`loading` and `fetchpriority` straight from the manifest.** Exactly one image per page is
 *    eager and high priority: the hero. Marking a below-fold image high priority makes it compete
 *    with the one that decides the largest contentful paint.
 *  - **`object-position` from the recorded focal point.** The derivatives preserve the full frame,
 *    so nothing is cropped out of the file — the focal point only decides what stays centred when
 *    the container is a different shape.
 *
 * The alternative text is the manifest's curated description of what the illustration SHOWS. It is
 * never generated from the role name, which is what made the previous alt text meaningless.
 */
import { computed } from 'vue';
import type { LandingImage } from '@/content/generated/landingImages.generated';

const props = withDefaults(
  defineProps<{
    image: LandingImage;
    /** Rounded card treatment. Off for a full-bleed placement. */
    framed?: boolean;
  }>(),
  { framed: true },
);

/** One `<source>` per format, widest-first ordering left to the browser via `srcset` widths. */
const sources = computed(() =>
  (['avif', 'webp'] as const)
    .map((format) => ({
      format,
      type: format === 'avif' ? 'image/avif' : 'image/webp',
      srcset: props.image.derivatives
        .filter((derivative) => derivative.format === format)
        .sort((a, b) => a.width - b.width)
        .map((derivative) => `${derivative.publicPath} ${derivative.width}w`)
        .join(', '),
    }))
    .filter((source) => source.srcset !== ''),
);

const style = computed(() => ({
  objectPosition: `${props.image.focalX * 100}% ${props.image.focalY * 100}%`,
  aspectRatio: `${props.image.intrinsicWidth} / ${props.image.intrinsicHeight}`,
}));
</script>

<template>
  <picture
    class="block"
    :data-landing-image-account="image.accountKey"
    :data-landing-image-section="image.landingSection"
    data-testid="landing-picture"
  >
    <source
      v-for="source in sources"
      :key="source.format"
      :type="source.type"
      :srcset="source.srcset"
      :sizes="image.sizes"
    >
    <!--
      `decorative` images carry `alt=""` by the manifest's own declaration; every curated image in
      the current manifest is meaningful and carries real alternative text.
    -->
    <img
      :src="image.sourcePublicPath"
      :alt="image.decorative ? '' : image.alternativeText"
      :width="image.intrinsicWidth"
      :height="image.intrinsicHeight"
      :loading="image.loading"
      :fetchpriority="image.fetchPriority"
      :style="style"
      decoding="async"
      class="h-auto w-full max-w-full object-cover"
      :class="framed ? 'rounded-card shadow-card' : ''"
    >
  </picture>
</template>
