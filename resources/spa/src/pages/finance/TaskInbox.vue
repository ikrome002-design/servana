<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePaymentStore } from '@/stores/paymentStore';
import { useRefundStore } from '@/stores/refundStore';
import { useFinanceDisputeStore } from '@/stores/financeDisputeStore';
import { useCashUpStore } from '@/stores/cashUpStore';
import { usePeriodLockStore } from '@/stores/periodLockStore';
import { useFinanceExportStore } from '@/stores/financeExportStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Finance task inbox (Plan §42–§46, §65; Phase 18B). One capability-gated home that
 * surfaces everything awaiting Finance: payment groups pending validation / requiring
 * correction, refunds awaiting approval / finalization, disputes open or under review,
 * cash-ups awaiting review, period reopen requests, and exports queued/processing/
 * failed. Visibility is UX only — the server is the authoritative boundary. Each tile
 * links to its full surface; tiles the role can’t see are hidden.
 */
const router = useRouter();
const permissions = usePermissionStore();
const payments = usePaymentStore();
const refunds = useRefundStore();
const disputes = useFinanceDisputeStore();
const cashUps = useCashUpStore();
const periods = usePeriodLockStore();
const exportsStore = useFinanceExportStore();

const loaded = ref(false);
const loadError = ref(false);

const canValidate = computed(() => permissions.can('customer_payment.validate'));
const canApproveRefund = computed(() => permissions.can('refund.approve'));
const canFinalizeRefund = computed(() => permissions.can('refund.finalize'));
const canDispute = computed(() => permissions.can('finance_dispute.manage'));
const canReviewCashUp = computed(() => permissions.can('cash_up.approve'));
const canReopen = computed(() => permissions.can('period_lock.reopen'));
const canExport = computed(() => permissions.can('finance_export.create'));

const pendingValidations = computed(() =>
  payments.groups.filter((g) => g.status === 'pending_validation' || g.status === 'correction_required').length);
const refundsAwaitingApproval = computed(() => refunds.refunds.filter((r) => r.status === 'requested').length);
const refundsAwaitingFinalization = computed(() => refunds.refunds.filter((r) => r.status === 'approved').length);
const openDisputes = computed(() => disputes.disputes.filter((d) => d.status === 'open' || d.status === 'under_review').length);
const cashUpsAwaiting = computed(() => cashUps.cashUps.filter((c) => c.status === 'submitted').length);
const reopenRequests = computed(() =>
  periods.locks.filter((l) => l.status === 'locked' && l.reopen_requested_at !== null).length);
const activeExports = computed(() =>
  exportsStore.exports.filter((e) => ['queued', 'processing', 'failed'].includes(e.status)).length);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (!loaded.value && !loadError.value) return 'loading';
  if (loadError.value) return 'error';
  return 'success';
});

interface Tile {
  key: string;
  label: string;
  count: number;
  route: string;
  visible: boolean;
}

const tiles = computed<Tile[]>(() =>
  [
    { key: 'validations', label: 'Payments pending validation', count: pendingValidations.value, route: 'finance.pending-validations', visible: canValidate.value },
    { key: 'refund-approval', label: 'Refunds awaiting approval', count: refundsAwaitingApproval.value, route: 'finance.refunds', visible: canApproveRefund.value },
    { key: 'refund-finalization', label: 'Refunds awaiting finalization', count: refundsAwaitingFinalization.value, route: 'finance.refunds', visible: canFinalizeRefund.value },
    { key: 'disputes', label: 'Disputes open or under review', count: openDisputes.value, route: 'finance.disputes', visible: canDispute.value },
    { key: 'cash-ups', label: 'Cash-ups awaiting review', count: cashUpsAwaiting.value, route: 'finance.cash-up', visible: canReviewCashUp.value },
    { key: 'reopens', label: 'Period reopen requests', count: reopenRequests.value, route: 'finance.periods', visible: canReopen.value },
    { key: 'exports', label: 'Exports queued / processing / failed', count: activeExports.value, route: 'finance.exports', visible: canExport.value },
  ].filter((t) => t.visible),
);

async function load(): Promise<void> {
  try {
    await Promise.all([
      canValidate.value ? payments.fetchGroups() : Promise.resolve(),
      canApproveRefund.value || canFinalizeRefund.value ? refunds.fetchRefunds() : Promise.resolve(),
      canDispute.value ? disputes.fetchDisputes() : Promise.resolve(),
      canReviewCashUp.value ? cashUps.fetchCashUps() : Promise.resolve(),
      canReopen.value ? periods.fetchLocks() : Promise.resolve(),
      canExport.value ? exportsStore.fetchExports() : Promise.resolve(),
    ]);
    loaded.value = true;
  } catch {
    loadError.value = true;
  }
}

onMounted(load);
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Finance task inbox
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load your task inbox."
      empty-message="You have no Finance tasks."
    >
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <SvCard
          v-for="tile in tiles"
          :key="tile.key"
          as="section"
          padding="md"
          data-testid="inbox-tile"
        >
          <p class="text-sm text-text-muted">
            {{ tile.label }}
          </p>
          <p class="font-display text-3xl font-bold text-heading">
            {{ tile.count }}
          </p>
          <SvButton
            variant="ghost"
            class="mt-2"
            :data-testid="`inbox-open-${tile.key}`"
            @click="() => router.push({ name: tile.route })"
          >
            View
          </SvButton>
        </SvCard>
      </div>
    </SvStateBoundary>
  </section>
</template>
