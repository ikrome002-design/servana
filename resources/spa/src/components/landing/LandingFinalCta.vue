<script setup lang="ts">
/**
 * LandingFinalCta — region 15 (Phase UI-06; UI/UX plan §8.3).
 *
 * The closing action. It reuses the SAME resolved CTAs as the header and the hero — one resolution
 * per page, three placements — so the bottom of the page can never offer an action the top does
 * not, which is how a landing page ends up exposing registration on a host that forbids it.
 */
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import type { ResolvedCta } from '@/content/landing/ctaResolver';
import type { LandingBlock } from '@/content/landing/landingSection';

defineProps<{
  headline: string;
  blocks: readonly LandingBlock[];
  ctas: readonly ResolvedCta[];
}>();
</script>

<template>
  <section
    id="section-final-cta"
    data-landing-region="final_cta"
    aria-labelledby="landing-final-cta-heading"
    class="bg-sv-surface-warm px-4 py-12 md:px-6 md:py-16 lg:px-8"
    data-testid="landing-final-cta"
  >
    <div class="mx-auto max-w-sv-content">
      <h2
        id="landing-final-cta-heading"
        class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
      >
        {{ headline }}
      </h2>

      <div class="mt-4 max-w-sv-readable">
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
          :data-testid="`landing-final-cta-${cta.key}`"
          :data-cta-kind="cta.kind"
        >{{ cta.label }}</a>
      </div>
    </div>
  </section>
</template>
