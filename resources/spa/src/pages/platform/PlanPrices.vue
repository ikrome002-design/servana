<script setup lang="ts">
/**
 * Plan Prices and Billing Periods — Super Administrator contract page §5.4.5 (Phase UI-08).
 *
 * The SOLE source of truth for effective-dated subscription prices across the five canonical
 * billing intervals. Prices come only from `subscription_plan_prices`; no duplicated price field
 * is editable anywhere else, which is why this page exists separately from plan metadata.
 *
 * Two rules the page states rather than assumes:
 *  - there is NO mid-cycle proration — the issued invoice is unchanged;
 *  - there is NO automatic grandfathering — a new price applies from its effective date onward.
 *
 * Composes the shipped `PlanPricesSection` and `planPriceStore` unchanged.
 */
import { ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import PlanPricesSection from '@/pages/platform/billing/PlanPricesSection.vue';
import SubscriptionPlansSection from '@/pages/platform/billing/SubscriptionPlansSection.vue';
import { useCan } from '@/composables/useCan';
import type { SubscriptionPlan } from '@/stores/subscriptionPlanStore';

const { can } = useCan();
const selectedPlan = ref<SubscriptionPlan | null>(null);

function onSelect(plan: SubscriptionPlan): void {
  selectedPlan.value = plan;
}
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="platform-prices-screen"
  >
    <SvPageHeader
      title="Plan prices and billing periods"
      eyebrow="Billing & commercial"
      description="Effective-dated prices for the weekly, bi-weekly, monthly, quarterly and annual intervals. Scheduling a price never changes an invoice that has already been issued."
    />

    <SvPermissionState v-if="!can('platform.plan.view')" />

    <template v-else>
      <section
        aria-label="Choose a plan"
        class="mb-10"
      >
        <SubscriptionPlansSection @select="onSelect" />
      </section>

      <section aria-label="Plan prices">
        <SvAlert
          v-if="selectedPlan === null"
          severity="info"
          data-testid="prices-select-prompt"
        >
          <p>Select a plan above to review its current, scheduled and superseded prices.</p>
        </SvAlert>

        <PlanPricesSection
          v-else
          :plan="selectedPlan"
        />
      </section>

      <p
        class="mt-8 text-xs text-sv-text-muted"
        data-testid="prices-immutability-note"
      >
        A price change is not applied mid-cycle and is not back-applied. Issued invoices keep the
        price snapshot they were created with, and merchants are not automatically grandfathered
        onto an older price.
      </p>
    </template>
  </div>
</template>
