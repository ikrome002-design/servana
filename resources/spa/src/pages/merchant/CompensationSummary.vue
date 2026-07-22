<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useMerchantCompensationSummaryStore } from '@/stores/merchantCompensationSummaryStore';
import { usePayoutRunStore, type PayoutRun } from '@/stores/payoutRunStore';
import { formatMoney } from '@/utils/money';
import { payoutRunStatusLabel } from '@/content/payout';

/**
 * Merchant Administrator — Compensation summary + high-value payout approvals (Plan §62/§63, §10.2,
 * §19.3; Phase 20H). The Merchant Administrator holds ONLY the compensation-summary READ + high-value
 * approval — never create/verify/standard-approve/mark-paid. The summary is merchant-wide, masked, and
 * currency-grouped (never combined); the browser computes no authoritative money. High-value approval is
 * a financial mutation — fresh step-up + Idempotency-Key are server-enforced. Servana moves no money.
 */
const summaryStore = useMerchantCompensationSummaryStore();
const payoutStore = usePayoutRunStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const router = useRouter();
const { can } = useCan();

const canView = computed(() => can('merchant.compensation_summary.view'));
const canApproveHighValue = computed(() => can('merchant.payout.approve_high_value'));

/* ---------------------------------------------------------------- a11y */
const statusRegion = ref<HTMLElement | null>(null);
const statusMessage = ref('');
const lastFocused = ref<HTMLElement | null>(null);
function rememberFocus(): void {
  lastFocused.value = document.activeElement instanceof HTMLElement ? document.activeElement : null;
}
function restoreFocus(): void {
  void nextTick(() => lastFocused.value?.focus());
}
async function announce(message: string): Promise<void> {
  statusMessage.value = message;
  await nextTick();
  statusRegion.value?.focus();
}
function money(minor: number, currency: string): string {
  return formatMoney(minor, currency);
}

/* ---------------------------------------------------------------- load */
const summaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (summaryStore.loading) return 'loading';
  if (summaryStore.error) return 'error';
  if (!summaryStore.loaded) return 'empty';
  return 'success';
});
const queueState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (payoutStore.listLoading) return 'loading';
  if (payoutStore.listError) return 'error';
  if (payoutStore.runs.length === 0) return 'empty';
  return 'success';
});
const statusRows = computed(() => Object.entries(summaryStore.summary.payout_runs_by_status));

async function loadAll(): Promise<void> {
  await Promise.all([summaryStore.fetchSummary(), payoutStore.fetchRuns('merchant', 1)]);
}
onMounted(() => {
  if (canView.value) void loadAll();
});
watch(
  () => auth.branchIds,
  () => {
    summaryStore.$reset();
    payoutStore.$reset();
    if (canView.value) void loadAll();
  },
);

/* ---------------------------------------------------------------- high-value approval */
const approveOpen = ref(false);
const approveError = ref<string | null>(null);
const approveStepUp = ref(false);
const targetRun = ref<PayoutRun | null>(null);

function openApprove(run: PayoutRun): void {
  rememberFocus();
  targetRun.value = run;
  approveError.value = null;
  approveStepUp.value = false;
  approveOpen.value = true;
}
function closeApprove(): void {
  approveOpen.value = false;
  targetRun.value = null;
  restoreFocus();
}
async function verifyStepUp(): Promise<void> {
  await router.push({ name: 'auth.mfa.challenge' });
}
async function submitApprove(): Promise<void> {
  const run = targetRun.value;
  if (!run || payoutStore.mutating) return;
  approveError.value = null;
  approveStepUp.value = false;
  try {
    await payoutStore.approveHighValue(run.id);
    approveOpen.value = false;
    restoreFocus();
    await announce('High-value payout run approved. Finance can now record the external payment.');
    notifications.addToast({ type: 'success', message: 'High-value payout approved.' });
    await Promise.all([summaryStore.fetchSummary(), payoutStore.fetchRuns('merchant', 1)]);
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message } = err.apiError;
      if (code === 'step_up_required' || code === 'privileged_mfa_required') { approveStepUp.value = true; return; }
      if (code === 'invalid_state_transition') { approveError.value = 'This run is no longer awaiting your approval. Reload to see its latest status.'; return; }
      if (code === 'idempotency_conflict') { approveError.value = 'This approval is already being processed. Reload before trying again.'; return; }
      approveError.value = message ?? 'The approval could not be completed.';
      return;
    }
    approveError.value = 'Something went wrong. Please try again.';
  }
}
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Compensation summary
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      What your business owes staff and what has been paid, grouped by currency, plus payout runs that need
      your high-value approval. Amounts are shown exactly as Servana calculated them and are never combined
      across currencies. This is an overview — it does not move any money.
    </p>

    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="summary-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="summaryStore.forbidden || !canView"
      data-testid="summary-forbidden"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to the compensation summary.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <!-- summary -->
      <SvStateBoundary
        class="mt-6"
        :state="summaryState"
        :error-message="summaryStore.error ?? undefined"
        empty-message="No compensation activity yet."
        @retry="summaryStore.fetchSummary()"
      >
        <div class="grid gap-6 lg:grid-cols-2">
          <section aria-labelledby="outstanding-heading">
            <h2
              id="outstanding-heading"
              class="text-sm font-semibold text-text"
            >
              Outstanding liability by currency
            </h2>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
              <SvCard
                v-for="row in summaryStore.summary.outstanding_liability_by_currency"
                :key="row.currency"
                padding="sm"
                data-testid="outstanding-card"
              >
                <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
                  {{ row.currency }}
                </p>
                <p class="mt-1 text-lg font-bold text-text">
                  {{ money(row.combined_net_liability_minor, row.currency) }}
                </p>
                <p class="text-xs text-text-muted">
                  Combined net owed
                </p>
              </SvCard>
              <p
                v-if="summaryStore.summary.outstanding_liability_by_currency.length === 0"
                class="text-sm text-text-muted"
              >
                Nothing outstanding.
              </p>
            </div>
          </section>

          <section aria-labelledby="paid-heading">
            <h2
              id="paid-heading"
              class="text-sm font-semibold text-text"
            >
              Paid by currency
            </h2>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
              <SvCard
                v-for="row in summaryStore.summary.paid_by_currency"
                :key="row.currency"
                padding="sm"
                data-testid="paid-card"
              >
                <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
                  {{ row.currency }}
                </p>
                <p class="mt-1 text-lg font-bold text-text">
                  {{ money(row.paid_gross_minor, row.currency) }}
                </p>
                <p class="text-xs text-text-muted">
                  {{ row.run_count }} paid run(s)
                </p>
              </SvCard>
              <p
                v-if="summaryStore.summary.paid_by_currency.length === 0"
                class="text-sm text-text-muted"
              >
                Nothing paid yet.
              </p>
            </div>
          </section>
        </div>

        <section
          aria-labelledby="status-heading"
          class="mt-6"
        >
          <h2
            id="status-heading"
            class="text-sm font-semibold text-text"
          >
            Payout runs by status
          </h2>
          <ul class="mt-2 flex flex-wrap gap-2">
            <li
              v-for="[status, count] in statusRows"
              :key="status"
              data-testid="status-count"
              class="rounded-control bg-surface-alt px-3 py-1 text-sm text-text"
            >
              {{ payoutRunStatusLabel(status) }}: <span class="font-semibold">{{ count }}</span>
            </li>
          </ul>
          <p class="mt-2 text-sm text-text-muted">
            High-value approvals awaiting you:
            <span
              class="font-semibold text-text"
              data-testid="pending-high-value"
            >{{ summaryStore.summary.pending_high_value_approvals }}</span>
          </p>
        </section>
      </SvStateBoundary>

      <!-- high-value approval queue -->
      <section
        aria-labelledby="queue-heading"
        class="mt-8"
      >
        <h2
          id="queue-heading"
          class="text-sm font-semibold text-text"
        >
          High-value payout approvals
        </h2>
        <SvStateBoundary
          class="mt-2"
          :state="queueState"
          :error-message="payoutStore.listError ?? undefined"
          empty-message="No payout runs need your approval right now."
          @retry="payoutStore.fetchRuns('merchant')"
        >
          <ul class="mt-2 flex flex-col gap-3">
            <li
              v-for="run in payoutStore.runs"
              :key="run.id"
              data-testid="high-value-row"
            >
              <SvCard padding="sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-semibold text-text">
                      {{ money(run.gross_total_minor, run.currency) }}
                      <span class="ml-1 text-xs font-normal text-text-muted">gross · {{ run.item_count ?? 0 }} staff</span>
                    </p>
                    <p class="mt-1 text-xs text-text-muted">
                      {{ run.period_start }} → {{ run.period_end }} · {{ run.currency }} ·
                      threshold {{ run.high_value_threshold_snapshot_minor === null ? '—' : money(run.high_value_threshold_snapshot_minor, run.currency) }}
                    </p>
                  </div>
                  <SvButton
                    v-if="canApproveHighValue"
                    :data-testid="`approve-high-value-${run.id}`"
                    @click="openApprove(run)"
                  >
                    Approve
                  </SvButton>
                </div>
              </SvCard>
            </li>
          </ul>
        </SvStateBoundary>
      </section>
    </template>

    <!-- approve modal -->
    <SvModal
      :open="approveOpen"
      title="Approve high-value payout"
      description="Approve this payout run so Finance can record its external payment. This is a high-value approval and needs a fresh step-up. It does not move any money."
      @close="closeApprove"
    >
      <div
        v-if="targetRun"
        class="flex flex-col gap-4"
      >
        <div
          v-if="approveStepUp"
          data-testid="approve-step-up"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            A fresh identity check is required
          </p>
          <p class="mt-1">
            Verify your identity, then approve again. Nothing has changed yet.
          </p>
          <div class="mt-2">
            <SvButton
              type="button"
              variant="secondary"
              data-testid="approve-verify"
              @click="verifyStepUp"
            >
              Verify identity
            </SvButton>
          </div>
        </div>

        <p
          v-if="approveError"
          data-testid="approve-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ approveError }}
        </p>

        <p class="text-sm text-text">
          Approve the {{ money(targetRun.gross_total_minor, targetRun.currency) }} payout run for
          {{ targetRun.period_start }} → {{ targetRun.period_end }}?
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            type="button"
            variant="secondary"
            @click="closeApprove"
          >
            Cancel
          </SvButton>
          <SvButton
            type="button"
            data-testid="approve-submit"
            :loading="payoutStore.mutating"
            @click="submitApprove"
          >
            Approve high-value payout
          </SvButton>
        </div>
      </div>
    </SvModal>
  </section>
</template>
