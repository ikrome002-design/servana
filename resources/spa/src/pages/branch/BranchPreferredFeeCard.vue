<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useBranchPreferredFeeStore } from '@/stores/branchPreferredFeeStore';

// Phase 20A — Branch Manager READ-ONLY view of the effective preferred-personnel fee rule
// (Plan §13.10, §39). Integrated into the branch service surface rather than a new top-level
// screen. Branch-scoped, `preferred_personnel_fee.view_branch_rule`; NO mutation controls,
// NO draft/scheduled data, NO platform MFA/step-up. The parent gates rendering by permission.
const store = useBranchPreferredFeeStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.loaded && store.rule === null) return 'empty';
  return 'success';
});

function money(minor: number | null, currency: string | null): string {
  if (minor === null) return '—';
  return `${currency ?? ''} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

const summary = computed<string>(() => {
  const r = store.rule;
  if (r === null) return '';
  if (r.calculation_type === 'fixed_amount') return money(r.fixed_amount_minor, r.currency);
  return `${((r.percentage_basis_points ?? 0) / 100).toFixed(2)}%`;
});

onMounted(() => {
  if (!store.loaded) void store.fetchEffective();
});
</script>

<template>
  <SvCard
    class="mt-6"
    data-testid="branch-preferred-fee"
  >
    <h2 class="font-display text-base font-semibold text-brand-deep">
      Preferred-personnel fee (effective)
    </h2>
    <p class="mt-1 text-sm text-text-muted">
      The platform-set fee applied when a client requests a preferred personnel member.
      Read-only — managed by the platform.
    </p>

    <SvStateBoundary
      class="mt-3"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No preferred-personnel fee currently applies."
      @retry="store.fetchEffective()"
    >
      <dl
        v-if="store.rule"
        class="mt-2 grid gap-2 text-sm sm:grid-cols-2"
      >
        <div>
          <dt class="text-text-muted">
            Fee
          </dt>
          <dd class="font-semibold text-text">
            {{ summary }}
          </dd>
        </div>
        <div>
          <dt class="text-text-muted">
            Basis
          </dt>
          <dd class="text-text">
            {{ store.rule.calculation_basis === 'service_item_net_amount' ? 'Service item net amount' : 'Service item gross amount' }}
          </dd>
        </div>
        <div>
          <dt class="text-text-muted">
            Effective from
          </dt>
          <dd class="text-text">
            {{ store.rule.effective_from }}
          </dd>
        </div>
        <div>
          <dt class="text-text-muted">
            Effective to
          </dt>
          <dd class="text-text">
            {{ store.rule.effective_to ?? 'Open-ended' }}
          </dd>
        </div>
      </dl>
    </SvStateBoundary>
  </SvCard>
</template>
