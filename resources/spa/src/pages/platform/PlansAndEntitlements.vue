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
import PlatformRuleWorkspace from '@/components/platform/PlatformRuleWorkspace.vue';

const { can } = useCan();
const selectedPlan = ref<SubscriptionPlan | null>(null);

function onSelect(plan: SubscriptionPlan): void {
  selectedPlan.value = plan;
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001: a plan CATALOGUE (cards with identity, state and the entitlement entry
    point) above the selected plan's capability matrix, with the binding rule and the enforcement
    boundary in the guidance rail.
  -->
  <div
    class="sv-r-page"
    data-testid="platform-plans-screen"
  >
    <SvPageHeader
      title="Plans and entitlements"
      eyebrow="Billing & commercial"
      description="Plan metadata and the entitlements the server enforces for merchants on each plan."
    />

    <SvPermissionState v-if="!can('platform.plan.view')" />

    <template v-else>
      <!-- UI16-VIS-016: the page's own rule statement moves into the guidance rail. -->
      <PlatformRuleWorkspace>
        <section aria-label="Subscription plans">
          <SubscriptionPlansSection
            layout="catalogue"
            :selected-id="selectedPlan?.id ?? null"
            @select="onSelect"
          />
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
        <template #rule>
          <p>
            Removing an entitlement takes effect at the next applicable boundary for merchants already using the capability. Retiring a plan preserves existing subscription history.
          </p>
        </template>
        <template #rail>
          <div class="sv-r-note sv-r-tone-warm">
            <p class="sv-r-note__title">
              Enforcement boundary
            </p>
            <p class="sv-r-note__body">
              Entitlements are enforced by the server. Nothing chosen on this page creates a restriction that exists only in the browser.
            </p>
          </div>
        </template>
      </PlatformRuleWorkspace>
    </template>
  </div>
</template>
