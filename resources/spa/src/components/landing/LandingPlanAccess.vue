<script setup lang="ts">
/**
 * LandingPlanAccess — region 12 (Phase UI-06; UI/UX plan §8.5; binding decisions §2.3/§2.4).
 *
 * Pricing on a public page is the easiest place to publish something untrue. The canonical price
 * authority is the plan catalogue the platform operator maintains at runtime; no repository fixture
 * seeds it and no public endpoint exposes it, so a public page cannot prove any amount is current.
 * §8.5 forbids showing a stale one, and the binding decision resolves it: render the role-appropriate
 * plan-access explanation and no amount.
 *
 * The component enforces that structurally rather than by convention:
 *
 *  - `PlanAccessBlock.showsAmount` and `purchaseCta` are typed as the literal `false`, so a block
 *    carrying either cannot be constructed;
 *  - there is no slot, prop or branch here that could render a price or a buy action;
 *  - content the page deliberately does NOT publish is displayed as an honest note with its reason,
 *    rather than vanishing — a silently omitted section is indistinguishable from a bug.
 */
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import type { PlanAccessBlock } from '@/content/landing/landingContract';
import type { LandingBlock } from '@/content/landing/landingSection';

defineProps<{
  planAccess: PlanAccessBlock;
  /** The compiled pricing section, rendered only when it is present and states no amount. */
  sourceBlocks: readonly LandingBlock[];
}>();
</script>

<template>
  <section
    id="section-pricing"
    data-landing-region="pricing"
    aria-labelledby="landing-plan-access-heading"
    class="px-4 py-12 md:px-6 md:py-16 lg:px-8"
    data-testid="landing-plan-access"
    :data-plan-access-mode="planAccess.mode"
    :data-shows-amount="String(planAccess.showsAmount)"
    :data-purchase-cta="String(planAccess.purchaseCta)"
  >
    <div class="mx-auto max-w-sv-content">
      <h2
        id="landing-plan-access-heading"
        class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
      >
        {{ planAccess.heading }}
      </h2>

      <div
        v-if="planAccess.renderCompiledSource && sourceBlocks.length > 0"
        class="mt-4 max-w-sv-readable"
        data-testid="landing-plan-access-source"
      >
        <LandingBlocks :blocks="sourceBlocks" />
      </div>

      <ul class="mt-6 grid max-w-sv-content gap-3 md:grid-cols-2">
        <li
          v-for="point in planAccess.points"
          :key="point"
          class="rounded-card border border-sv-border bg-sv-surface-raised p-4 text-sm text-sv-text-secondary"
          data-testid="landing-plan-access-point"
        >
          {{ point }}
        </li>
      </ul>

      <div
        v-if="planAccess.withheld.length > 0"
        class="mt-6 max-w-sv-readable rounded-card border border-sv-border bg-sv-surface-subtle p-4"
        data-testid="landing-plan-access-withheld"
      >
        <h3 class="text-sm font-semibold text-sv-text-heading">
          About plan prices
        </h3>
        <!--
          The visitor is told plainly that no amount appears here and where the current one lives.
          The engineering reason for each withheld item is recorded in
          docs/frontend/audits/ui-06/pricing-plan-access-matrix.json rather than printed at them.
        -->
        <p class="mt-2 text-sm text-sv-text-muted">
          Current plan prices come from the live plan catalogue and are confirmed when you create
          your account, so no amount is quoted on this page.
        </p>
      </div>
    </div>
  </section>
</template>
