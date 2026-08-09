<script setup lang="ts">
/**
 * Preferred Personnel Fee Rules — Super Administrator contract page §5.4.8 (Phase UI-08).
 *
 * The launch-active fixed or percentage fee applied when a merchant client selects preferred
 * personnel. Effective-dated, non-overlapping, and immutable once active.
 *
 * The shipped Phase 20G routes gate BOTH the reads and the mutations with
 * `platform.preferred_personnel_fee.manage` — there is no separate view key, so this page cites
 * what the server actually enforces rather than inventing one.
 *
 * Composes the shipped `PreferredFeeRulesSection` and `preferredPersonnelFeeStore` unchanged; the
 * round-half-up calculation stays server-side and is only previewed here.
 */
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import PreferredFeeRulesSection from '@/pages/platform/billing/PreferredFeeRulesSection.vue';
import { useCan } from '@/composables/useCan';

const { can } = useCan();
</script>

<template>
  <div
    class="mx-auto w-full max-w-4xl"
    data-testid="platform-preferred-fees-screen"
  >
    <SvPageHeader
      title="Preferred personnel fee rules"
      eyebrow="Billing & commercial"
      description="The fee applied when a client chooses a specific staff member. Rules are effective-dated and cannot overlap; a change supersedes rather than edits."
    />

    <SvPermissionState v-if="!can('platform.preferred_personnel_fee.manage')" />

    <template v-else>
      <PreferredFeeRulesSection />

      <p
        class="mt-8 text-xs text-sv-text-muted"
        data-testid="preferred-fees-immutability-note"
      >
        Existing invoices never recalculate when a rule changes — each invoice keeps the fee terms
        that were in force when it was issued. Branch users can see the effective rule but cannot
        edit it.
      </p>
    </template>
  </div>
</template>
