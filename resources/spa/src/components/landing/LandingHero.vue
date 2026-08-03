<script setup lang="ts">
/**
 * LandingHero — region 2 (Phase UI-06; UI/UX plan §8.3, §22.2).
 *
 * The page `h1` lives here and nowhere else: one `h1` per document, carrying the account's own
 * headline from its compiled hero section.
 *
 * The hero image is the ONLY eager, high-priority image on the page. The manifest records that
 * per image, so the decision is data rather than a flag someone might copy onto a second image —
 * and `Ui06ImageRenderContractTest` proves exactly one image per account carries it.
 */
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import LandingPicture from '@/components/landing/LandingPicture.vue';
import type { LandingImage } from '@/content/generated/landingImages.generated';
import type { ResolvedCta } from '@/content/landing/ctaResolver';
import type { LandingBlock } from '@/content/landing/landingSection';

defineProps<{
  eyebrow: string;
  headline: string;
  blocks: readonly LandingBlock[];
  image: LandingImage | null;
  ctas: readonly ResolvedCta[];
}>();
</script>

<template>
  <section
    id="section-hero"
    data-landing-region="hero"
    aria-labelledby="landing-hero-heading"
    class="bg-sv-surface-warm px-4 py-12 md:px-6 md:py-16 lg:px-8 lg:py-20"
    data-testid="landing-hero"
  >
    <div class="mx-auto flex max-w-sv-content flex-col gap-8 lg:flex-row lg:items-center">
      <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-sv-brand-secondary">
          {{ eyebrow }}
        </p>
        <h1
          id="landing-hero-heading"
          class="mt-2 font-display text-3xl font-extrabold text-sv-text-heading md:text-4xl lg:text-5xl"
        >
          {{ headline }}
        </h1>

        <div class="mt-5 max-w-sv-readable text-base">
          <LandingBlocks :blocks="blocks" />
        </div>

        <div class="mt-8 flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
          <a
            v-for="cta in ctas"
            :key="cta.key"
            :href="cta.href"
            class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control px-6 py-3 text-base font-semibold"
            :class="cta.emphasis === 'primary'
              ? 'bg-sv-brand text-sv-text-on-brand hover:bg-sv-brand-hover'
              : 'border border-sv-border-input text-sv-text hover:bg-sv-surface-subtle'"
            :data-testid="`landing-hero-cta-${cta.key}`"
            :data-cta-kind="cta.kind"
          >{{ cta.label }}</a>
        </div>
      </div>

      <div
        v-if="image"
        class="min-w-0 flex-1"
      >
        <LandingPicture :image="image" />
      </div>
    </div>
  </section>
</template>
