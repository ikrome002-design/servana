<script setup lang="ts">
/**
 * Promotional Discounts — Super Administrator contract page §5.4.6 (Phase UI-08).
 *
 * Percentage or fixed-amount promotions with deterministic targeting and immutable application
 * snapshots.
 *
 * At most ONE discount applies to a subscription issuance, resolved server-side by the settled
 * precedence order (merchant target beats plan, plan beats billing mode, billing mode beats
 * global). This page never re-implements that resolver; it shows what the server decided.
 *
 * The discount form, lifecycle actions and validation are shared with Free-Period Offers via the
 * `only` prop on the Phase 20C promotions section — the plan encourages shared form components and
 * forbids duplicating the same business logic per page.
 *
 * The shipped Phase 20C routes gate BOTH reads and mutations with `platform.promotion.manage`;
 * there is no separate view key, so that is the key cited here.
 */
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import PromotionsSection from '@/pages/platform/Promotions.vue';
import { useCan } from '@/composables/useCan';

const { can } = useCan();
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="platform-promotions-screen"
  >
    <SvPageHeader
      title="Promotional discounts"
      eyebrow="Billing & commercial"
      description="Percentage or fixed-amount promotions. Targeting is deterministic, and an applied discount is captured as an immutable snapshot on the invoice it affected."
    />

    <SvPermissionState v-if="!can('platform.promotion.manage')" />

    <template v-else>
      <PromotionsSection only="promotions" />

      <p
        class="mt-8 text-xs text-sv-text-muted"
        data-testid="promotions-precedence-note"
      >
        At most one discount applies to any subscription issuance. A merchant-targeted promotion
        wins over a plan-targeted one, a plan over a billing mode, and a billing mode over a global
        offer. Changing a promotion never rewrites an invoice that has already been issued.
      </p>
    </template>
  </div>
</template>
