<script setup lang="ts">
/**
 * Free-Period Offers — Super Administrator contract page §5.4.7 (Phase UI-08).
 *
 * Targeted offers that extend trial entitlement. A free-period offer is modelled SEPARATELY from a
 * promotional discount in the backend, and this page keeps that separation: treating one as the
 * other would misreport which offer applied and what the resulting trial end actually is.
 *
 * At most one free-period offer applies at issuance, and an existing trial snapshot is never
 * recalculated when an offer is later edited.
 *
 * Composes the Phase 20C promotions section with `only="free-periods"`, so the shared form,
 * lifecycle actions and validation are reused rather than duplicated.
 */
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import PromotionsSection from '@/pages/platform/Promotions.vue';
import { useCan } from '@/composables/useCan';
import PlatformRuleWorkspace from '@/components/platform/PlatformRuleWorkspace.vue';

const { can } = useCan();
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001: offer-window cards in the working column; the page's own immutability
    statement (with its original test hook) in the guidance rail beside them, where it is read
    BEFORE an offer is changed rather than after.
  -->
  <div
    class="sv-r-page"
    data-testid="platform-free-periods-screen"
  >
    <SvPageHeader
      title="Free-period offers"
      eyebrow="Billing & commercial"
      description="Targeted offers that extend trial entitlement for eligible merchants."
    />

    <SvPermissionState v-if="!can('platform.free_period_offer.manage')" />

    <template v-else>
      <PlatformRuleWorkspace rule-title="How an offer applies">
        <PromotionsSection only="free-periods" />
        <template #rule>
          <p data-testid="free-periods-immutability-note">
            At most one free-period offer applies at issuance. Editing an offer never recalculates a
            trial that has already been granted — the merchant keeps the absolute trial-end date they
            were given.
          </p>
        </template>
      </PlatformRuleWorkspace>
    </template>
  </div>
</template>
