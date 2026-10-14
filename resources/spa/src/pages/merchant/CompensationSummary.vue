<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { useMerchantCompensationSummaryStore } from '@/stores/merchantCompensationSummaryStore';
import { usePayoutRunStore, type PayoutRun } from '@/stores/payoutRunStore';
import { formatMoney } from '@/utils/money';
import { payoutRunStatusLabel } from '@/content/payout';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

const props = withDefaults(defineProps<{ mode?: 'combined' | 'summary' | 'approvals' }>(), {
  mode: 'combined',
});

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
const showSummary = computed(() => props.mode !== 'approvals');
const showApprovals = computed(() => props.mode !== 'summary');
const denied = computed(() =>
  (showSummary.value && !canView.value) || (showApprovals.value && !canApproveHighValue.value),
);

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
  await Promise.all([
    ...(showSummary.value && canView.value ? [summaryStore.fetchSummary()] : []),
    ...(showApprovals.value && canApproveHighValue.value ? [payoutStore.fetchRuns('merchant', 1)] : []),
  ]);
}
onMounted(() => {
  if (!denied.value) void loadAll();
});
watch(
  () => auth.branchIds,
  () => {
    summaryStore.$reset();
    payoutStore.$reset();
    if (!denied.value) void loadAll();
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
  <!--
    COR-UI08-UI12-VIS-001 — two compositions on one page component.

    SUMMARY: what the business owes leads on the dark feature surface, one figure per currency
    (never combined); what Finance recorded as paid sits beside it in growth green; the payout
    pipeline reads as status steps with the server's counts, and the owner's pending high-value
    approvals link to their queue.

    APPROVALS: a bounded decision queue. The maker/checker chain this approval sits inside is
    stated first (HR prepares, Finance verifies, the owner approves only high-value runs, Finance
    records the external payment); each run is an approval card with the amount, period, staff count
    and the threshold snapshot it crossed, and one guarded action that requires a fresh step-up.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      :title="showApprovals && !showSummary ? 'High-value payout approvals' : 'Compensation summary'"
      eyebrow="Compensation & Approvals"
    >
      <template #description>
        <template v-if="showApprovals && !showSummary">
          Review only payout runs that crossed the server-configured high-value threshold. Approval
          needs a fresh identity step-up and allows Finance to record an external payment; Servana
          does not move money.
        </template>
        <template v-else>
          What your business owes staff and what has been paid, grouped by currency. Amounts are shown
          exactly as Servana calculated them and are never combined across currencies. This overview
          cannot create, verify, standard-approve or mark a payout as paid.
        </template>
      </template>
    </SvPageHeader>

    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="summary-status"
      class="text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="summaryStore.forbidden || denied"
      data-testid="summary-forbidden"
      class="mt-2"
    >
      <div class="sv-r-panel sv-r-panel--dashed">
        <p class="text-sm text-text-muted">
          You do not have access to this compensation surface.
        </p>
      </div>
    </div>

    <template v-else>
      <!-- summary -->
      <SvStateBoundary
        v-if="showSummary"
        :state="summaryState"
        :error-message="summaryStore.error ?? undefined"
        empty-message="No compensation activity yet."
        @retry="summaryStore.fetchSummary()"
      >
        <div class="sv-r-stack">
          <div class="sv-r-grid sv-r-split-even">
            <section
              aria-labelledby="outstanding-heading"
              class="sv-r-feature"
            >
              <p class="sv-r-feature__eyebrow">
                Owed to staff
              </p>
              <h2
                id="outstanding-heading"
                class="sv-r-feature__title"
              >
                Outstanding liability by currency
              </h2>
              <p class="sv-r-feature__hint mt-1">
                What your business owes staff, as Servana calculated it.
              </p>
              <div class="mt-5 grid gap-3">
                <div
                  v-for="row in summaryStore.summary.outstanding_liability_by_currency"
                  :key="row.currency"
                  class="sv-r-feature__cell"
                  data-testid="outstanding-card"
                >
                  <p class="sv-r-feature__eyebrow">
                    {{ row.currency }}
                  </p>
                  <p class="sv-r-feature__value sv-r-figure-xl mt-2">
                    {{ money(row.combined_net_liability_minor, row.currency) }}
                  </p>
                  <p class="sv-r-feature__hint mt-1">
                    Combined net owed
                  </p>
                </div>
                <p
                  v-if="(summaryStore.summary?.outstanding_liability_by_currency?.length ?? 0) === 0"
                  class="sv-r-feature__hint"
                >
                  Nothing outstanding.
                </p>
              </div>
            </section>

            <section
              aria-labelledby="paid-heading"
              class="sv-r-panel sv-r-panel--tonal sv-r-tone-green"
            >
              <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green">
                Recorded by Finance
              </p>
              <h2
                id="paid-heading"
                class="sv-r-title mt-1"
              >
                Paid by currency
              </h2>
              <p class="sv-r-panel__desc">
                Payout runs Finance recorded as paid outside Servana.
              </p>
              <div class="mt-5 grid gap-3">
                <div
                  v-for="row in summaryStore.summary.paid_by_currency"
                  :key="row.currency"
                  class="sv-r-record"
                  data-testid="paid-card"
                >
                  <p class="sv-r-eyebrow">
                    {{ row.currency }}
                  </p>
                  <p class="sv-r-figure mt-2">
                    {{ money(row.paid_gross_minor, row.currency) }}
                  </p>
                  <p class="sv-r-muted mt-1">
                    {{ row.run_count }} paid run(s)
                  </p>
                </div>
                <p
                  v-if="(summaryStore.summary?.paid_by_currency?.length ?? 0) === 0"
                  class="sv-r-lede"
                >
                  Nothing paid yet.
                </p>
              </div>
            </section>
          </div>

          <section
            aria-labelledby="status-heading"
            class="sv-r-panel"
          >
            <div class="sv-r-panel__head">
              <div>
                <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
                  Pipeline
                </p>
                <h2
                  id="status-heading"
                  class="sv-r-title mt-1"
                >
                  Payout runs by status
                </h2>
              </div>
              <p class="sv-r-chip sv-r-chip--tone sv-r-tone-sun">
                High-value approvals awaiting you:
                <span
                  class="font-extrabold text-text"
                  data-testid="pending-high-value"
                >{{ summaryStore.summary.pending_high_value_approvals }}</span>
              </p>
            </div>
            <ul class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
              <li
                v-for="[status, count] in statusRows"
                :key="status"
                data-testid="status-count"
                class="sv-r-queue__row"
              >
                <span class="sv-r-queue__count sv-r-tone-warm">{{ count }}</span>
                <span class="sv-r-queue__label">{{ payoutRunStatusLabel(status) }}<span class="sr-only">: {{ count }}</span></span>
              </li>
            </ul>
          </section>
        </div>
      </SvStateBoundary>

      <!-- high-value approval queue -->
      <section
        v-if="showApprovals"
        aria-labelledby="queue-heading"
        :class="showSummary ? 'mt-6' : ''"
        class="sv-r-grid sv-r-split"
      >
        <div class="min-w-0">
          <h2
            id="queue-heading"
            class="sv-r-title mb-3"
          >
            High-value payout approvals
          </h2>
          <SvStateBoundary
            :state="queueState"
            :error-message="payoutStore.listError ?? undefined"
            empty-message="No payout runs need your approval right now."
            @retry="payoutStore.fetchRuns('merchant')"
          >
            <ul class="flex flex-col gap-3">
              <li
                v-for="run in payoutStore.runs"
                :key="run.id"
                data-testid="high-value-row"
              >
                <article class="sv-r-record sv-r-record--spine sv-r-tone-sun pl-6">
                  <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                      <span class="sv-r-chip sv-r-chip--tone sv-r-tone-sun">Above the high-value threshold</span>
                      <p class="sv-r-figure mt-3">
                        {{ money(run.gross_total_minor, run.currency) }}
                        <span class="ml-1 text-sm font-medium text-text-muted">gross · {{ run.item_count ?? 0 }} staff</span>
                      </p>
                      <dl class="sv-r-strip mt-4">
                        <div>
                          <dt>Period</dt>
                          <dd>{{ run.period_start }} → {{ run.period_end }}</dd>
                        </div>
                        <div>
                          <dt>Currency</dt>
                          <dd>{{ run.currency }}</dd>
                        </div>
                        <div>
                          <dt>Threshold</dt>
                          <dd>{{ run.high_value_threshold_snapshot_minor === null ? '—' : money(run.high_value_threshold_snapshot_minor, run.currency) }}</dd>
                        </div>
                      </dl>
                    </div>
                    <SvButton
                      v-if="canApproveHighValue"
                      :data-testid="`approve-high-value-${run.id}`"
                      @click="openApprove(run)"
                    >
                      Approve
                    </SvButton>
                  </div>
                </article>
              </li>
            </ul>
          </SvStateBoundary>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Approval boundaries"
        >
          <div class="sv-r-note sv-r-tone-warm">
            <p class="sv-r-note__title">
              Maker and checker chain
            </p>
            <ol class="sv-r-note__body mt-2 list-decimal space-y-1 pl-5">
              <li>Human Resource prepares and submits the run.</li>
              <li>Finance verifies it.</li>
              <li>You approve only runs above the high-value threshold.</li>
              <li>Finance records the external payment. Servana moves no money.</li>
            </ol>
          </div>
          <div class="sv-r-note sv-r-tone-alert">
            <p class="sv-r-note__title">
              Fresh step-up
            </p>
            <p class="sv-r-note__body">
              Each approval asks for a fresh identity check before the server accepts it.
            </p>
          </div>
        </aside>
      </section>
    </template>
    <!-- approve modal -->
    <SvDialog
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
          class="rounded-control bg-sv-error-bg px-3 py-2 text-sm text-sv-error-fg"
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
    </SvDialog>
  </section>
</template>
