<script setup lang="ts">
/**
 * Platform Billing Settings — Super Administrator contract page §5.4.3 (Phase UI-08).
 *
 * The versioned, effective-dated platform billing rules every merchant subscription is charged
 * against: billing mode, currency, default interval, and the trial / read-only-grace / overdue /
 * suspension thresholds.
 *
 * ## Why this is a NEW component rather than a narrowed `BillingSettings.vue`
 *
 * The shipped `BillingSettings.vue` delivers FIVE contract pages as tabs (§5.4.3-§5.4.5, §5.4.8),
 * which is the `UI01-NAV-001` consolidation UI-08 exists to undo. Narrowing it in place would
 * break the shipped Phase 20A/20E E2E specs that drive its tabs at `/platform/billing-settings`
 * before those specs are re-pointed at canonical paths — and that re-pointing belongs to Increment
 * 7B, where the legacy path becomes a redirect. So the canonical pages are built alongside, and
 * the legacy screen is retired in 7B with its specs. Recorded in the route-activation matrix.
 *
 * The underlying section components, stores and forms are REUSED unchanged: this page composes,
 * it does not reimplement. No business rule is restated in Vue.
 */
import { computed } from 'vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import BillingSettingsSection from '@/pages/platform/billing/BillingSettingsSection.vue';
import GeneralSettingsSection from '@/pages/platform/billing/GeneralSettingsSection.vue';
import PlatformFeeConfigSection from '@/pages/platform/billing/PlatformFeeConfigSection.vue';
import { useCan } from '@/composables/useCan';

const { can } = useCan();

const canViewBilling = computed(() => can('platform.billing_settings.view'));
const canViewGeneral = computed(() => can('platform.settings.view'));
const canConfigurePlatformFees = computed(() => can('platform.platform_fee.configure'));

/** Nothing on this page is visible without at least one of its section permissions. */
const canViewAnything = computed(
  () => canViewBilling.value || canViewGeneral.value || canConfigurePlatformFees.value,
);
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="platform-billing-settings-screen"
  >
    <SvPageHeader
      title="Platform billing settings"
      eyebrow="Billing & commercial"
      description="The effective-dated commercial rules every merchant subscription is charged against. A change creates the next version; it never rewrites an issued invoice, a trial snapshot or the current cycle."
    />

    <SvPermissionState v-if="!canViewAnything" />

    <template v-else>
      <!--
        A denied section is ABSENT, never disabled: a disabled control still advertises a capability
        the user does not have. The API remains the boundary either way.
      -->
      <section
        v-if="canViewBilling"
        aria-labelledby="billing-settings-section"
        class="mb-10"
      >
        <BillingSettingsSection id="billing-settings-section" />
      </section>

      <section
        v-if="canViewGeneral"
        aria-labelledby="general-settings-section"
        class="mb-10"
      >
        <GeneralSettingsSection id="general-settings-section" />
      </section>

      <section
        v-if="canConfigurePlatformFees"
        aria-labelledby="platform-fee-section"
      >
        <PlatformFeeConfigSection id="platform-fee-section" />
      </section>

      <p class="mt-8 text-xs text-sv-text-muted">
        Plan changes and price changes do not prorate the current cycle. A new effective version
        applies from its own effective date onward.
      </p>
    </template>
  </div>
</template>
