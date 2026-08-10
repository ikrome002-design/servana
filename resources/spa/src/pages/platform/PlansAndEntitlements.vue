<script setup lang="ts">
/**
 * Plans and Entitlements — Super Administrator contract page §5.4.4 (Phase UI-08).
 *
 * Plan metadata and the SERVER-ENFORCED entitlements attached to each plan.
 *
 * Entitlements are enforced by the backend; nothing selected here creates a frontend-only
 * restriction. Choosing a plan reveals its entitlements on the same page, so the relationship the
 * contract describes ("plan detail with included capabilities and limits") stays visible rather
 * than being split across two navigations.
 *
 * Composes the shipped section components and their stores unchanged.
 */
import { ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import PlanEntitlementsSection from '@/pages/platform/billing/PlanEntitlementsSection.vue';
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
    data-testid="platform-plans-screen"
  >
    <SvPageHeader
      title="Plans and entitlements"
      eyebrow="Billing & commercial"
      description="Plan metadata and the entitlements the server enforces for merchants on each plan."
    />

    <SvPermissionState v-if="!can('platform.plan.view')" />

    <template v-else>
      <section
        aria-label="Subscription plans"
        class="mb-10"
      >
        <SubscriptionPlansSection @select="onSelect" />
      </section>

      <section aria-label="Plan entitlements">
        <SvAlert
          v-if="selectedPlan === null"
          severity="info"
          data-testid="plans-select-prompt"
        >
          <p>Select a plan above to review and edit the entitlements it grants.</p>
        </SvAlert>

        <PlanEntitlementsSection
          v-else
          :plan="selectedPlan"
        />
      </section>

      <p class="mt-8 text-xs text-sv-text-muted">
        Removing an entitlement takes effect at the next applicable boundary for merchants already
        using the capability. Retiring a plan preserves existing subscription history.
      </p>
    </template>
  </div>
</template>
