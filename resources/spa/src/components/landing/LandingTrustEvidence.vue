<script setup lang="ts">
/**
 * LandingTrustEvidence — region 11 (Phase UI-06; UI/UX plan §8.4; binding decision §2.1/§2.2).
 *
 * This region is where a landing page would normally carry testimonials. No supplied customer
 * quotation is approved for production, and three accounts supply no such section at all, so every
 * one of the eight pages renders an approved FACTUAL alternative here instead.
 *
 * Two rendering decisions follow directly from §8.4 and are the reason this is a component rather
 * than a styled block:
 *
 *  - **Nothing is quotation-styled.** No `<blockquote>`, no quotation marks, no attribution line,
 *    no avatar, no logo. A factual capability statement dressed as a quote still reads as a
 *    customer saying it, which is the appearance the plan forbids.
 *  - **Each item shows what backs it.** The evidence type is displayed, and the source reference
 *    travels with the item as data attributes so the browser proof can assert provenance rather
 *    than trusting the copy.
 *
 * Human Resource's own source section is already a factual trust statement with no customer claim,
 * so its `mode` is `compiled_source_section` and that section renders verbatim above the items —
 * the binding decision requires preserving that approach where it applies.
 */
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import { SvIconSecurity } from '@/design-system/icons';
import type { TrustEvidenceBlock } from '@/content/landing/landingContract';
import type { LandingBlock } from '@/content/landing/landingSection';

defineProps<{
  trust: TrustEvidenceBlock;
  /** The compiled source section, rendered only when `trust.mode` is `compiled_source_section`. */
  sourceBlocks: readonly LandingBlock[];
  sourceHeadline: string | null;
}>();
</script>

<template>
  <section
    id="section-testimonials"
    data-landing-region="testimonials"
    aria-labelledby="landing-trust-heading"
    class="bg-sv-surface-subtle px-4 py-12 md:px-6 md:py-16 lg:px-8"
    data-testid="landing-trust-evidence"
    :data-trust-mode="trust.mode"
  >
    <div class="mx-auto max-w-sv-content">
      <h2
        id="landing-trust-heading"
        class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
      >
        {{ trust.heading }}
      </h2>

      <!-- Human Resource only: its own approved, already-factual trust statement, verbatim. -->
      <div
        v-if="trust.mode === 'compiled_source_section' && sourceBlocks.length > 0"
        class="mt-4 max-w-sv-readable"
        data-testid="landing-trust-source"
      >
        <p
          v-if="sourceHeadline"
          class="font-display text-lg font-bold text-sv-text-heading"
        >
          {{ sourceHeadline }}
        </p>
        <div class="mt-2">
          <LandingBlocks :blocks="sourceBlocks" />
        </div>
      </div>

      <p class="mt-4 max-w-sv-readable text-sv-text-secondary">
        {{ trust.intro }}
      </p>

      <ul class="mt-8 grid gap-4 md:grid-cols-2">
        <li
          v-for="item in trust.items"
          :key="item.title"
          class="rounded-card border border-sv-border bg-sv-surface-raised p-5"
          data-testid="landing-trust-item"
          :data-evidence-type="item.evidenceType"
          :data-evidence-source="item.source"
          :data-customer-claim="String(item.customerClaim)"
          :data-metric-claim="String(item.metricClaim)"
        >
          <div class="flex items-start gap-3">
            <SvIconSecurity
              aria-hidden="true"
              class="mt-0.5 h-5 w-5 shrink-0 text-sv-brand-secondary"
            />
            <div class="min-w-0">
              <h3 class="font-display text-base font-bold text-sv-text-heading">
                {{ item.title }}
              </h3>
              <p class="mt-1 text-sm text-sv-text-secondary">
                {{ item.detail }}
              </p>
              <p class="mt-3 text-xs uppercase tracking-wide text-sv-text-muted">
                {{ item.evidenceType.replace(/_/g, ' ') }}
              </p>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>
