<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePayoutRunStore, type PayoutRun } from '@/stores/payoutRunStore';
import { formatMoney } from '@/utils/money';
import { PAYOUT_RUN_STATUS_FILTER, payoutRunStatusLabel } from '@/content/payout';

/**
 * Finance Payout Runs (Plan §62, §25.5, §19.3; Phase 20H). Merchant-scoped Finance worklist: verify a
 * submitted run (Servana routes high-value runs to the Merchant Administrator), approve an ordinary run,
 * reject (releasing the claimed ledgers), and mark an approved run PAID after an EXTERNAL settlement.
 * Verify/approve/mark-paid are financial mutations — fresh step-up + Idempotency-Key are server-enforced.
 * **Servana moves no money** — mark-paid records that an external payment already happened; there is no
 * provider/Wallet call and no settlement here. Every control is UX only; the API is authoritative.
 */
const store = usePayoutRunStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const router = useRouter();
const { can } = useCan();

const canView = computed(() => can('payout_run.verify'));
const canApprove = computed(() => can('payout_run.approve_standard'));
const canReject = computed(() => can('payout_run.reject'));
const canMarkPaid = computed(() => can('payout_run.mark_paid'));

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
const listState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.listLoading) return 'loading';
  if (store.listError) return 'error';
  if (store.runs.length === 0) return 'empty';
  return 'success';
});
onMounted(() => {
  if (canView.value) void store.fetchRuns('finance', 1);
});
watch(
  () => auth.branchIds,
  () => {
    store.$reset();
    if (canView.value) void store.fetchRuns('finance', 1);
  },
);
async function applyFilters(): Promise<void> {
  await store.applyFilters('finance');
}
async function clearFilters(): Promise<void> {
  store.resetFilters();
  await store.applyFilters('finance');
}
function goPage(page: number): void {
  if (page >= 1 && page <= store.meta.last_page) void store.fetchRuns('finance', page);
}

/* ---------------------------------------------------------------- detail */
const detailOpen = ref(false);
const actionError = ref<string | null>(null);
const stepUpRequired = ref(false);
const detailRun = computed<PayoutRun | null>(() => store.currentRun);

async function openDetail(run: PayoutRun): Promise<void> {
  rememberFocus();
  actionError.value = null;
  stepUpRequired.value = false;
  detailOpen.value = true;
  await store.fetchRun('finance', run.id);
}
function closeDetail(): void {
  detailOpen.value = false;
  restoreFocus();
}
async function verifyStepUp(): Promise<void> {
  await router.push({ name: 'auth.mfa.challenge' });
}

const canVerifyNow = computed(() => detailRun.value?.status === 'submitted' && canView.value);
const canApproveNow = computed(() => detailRun.value?.status === 'finance_verified' && canApprove.value);
const canRejectNow = computed(() => ['submitted', 'finance_verified', 'pending_merchant_admin_approval'].includes(detailRun.value?.status ?? '') && canReject.value);
const canMarkPaidNow = computed(() => detailRun.value?.status === 'approved' && canMarkPaid.value);

/* ---------------------------------------------------------------- verify / approve */
async function runAction(fn: (id: string) => Promise<PayoutRun>, successMessage: string): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating) return;
  actionError.value = null;
  stepUpRequired.value = false;
  try {
    await fn(run.id);
    await announce(successMessage);
    notifications.addToast({ type: 'success', message: successMessage });
    await store.fetchRuns('finance', store.meta.current_page);
  } catch (err) {
    mapMutationError(err);
  }
}
function doVerify(): void {
  void runAction((id) => store.verify(id), 'Payout run verified.');
}
function doApprove(): void {
  void runAction((id) => store.approve(id), 'Payout run approved.');
}

/* ---------------------------------------------------------------- reject */
const rejectOpen = ref(false);
const rejectReason = ref('');
const rejectErrors = reactive<Record<string, string[]>>({});
function openReject(): void {
  rejectReason.value = '';
  Object.keys(rejectErrors).forEach((k) => delete rejectErrors[k]);
  actionError.value = null;
  rejectOpen.value = true;
}
async function submitReject(): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating) return;
  Object.keys(rejectErrors).forEach((k) => delete rejectErrors[k]);
  if (rejectReason.value.trim().length < 3) { rejectErrors.reason = ['A reason of at least 3 characters is required.']; return; }
  try {
    await store.reject(run.id, rejectReason.value.trim());
    rejectOpen.value = false;
    await announce('Payout run rejected; the claimed liabilities were released.');
    notifications.addToast({ type: 'success', message: 'Payout run rejected.' });
    await store.fetchRuns('finance', store.meta.current_page);
  } catch (err) {
    mapMutationError(err, rejectErrors);
  }
}

/* ---------------------------------------------------------------- mark paid */
const markPaidOpen = ref(false);
const markPaidError = ref<string | null>(null);
const markPaidStepUp = ref(false);
const markPaidForm = reactive({ external_payment_reference: '', paid_date: '' });
const markPaidErrors = reactive<Record<string, string[]>>({});
function openMarkPaid(): void {
  markPaidForm.external_payment_reference = '';
  markPaidForm.paid_date = '';
  Object.keys(markPaidErrors).forEach((k) => delete markPaidErrors[k]);
  markPaidError.value = null;
  markPaidStepUp.value = false;
  markPaidOpen.value = true;
}
function validateMarkPaid(): boolean {
  Object.keys(markPaidErrors).forEach((k) => delete markPaidErrors[k]);
  if (markPaidForm.external_payment_reference.trim().length < 3) markPaidErrors.external_payment_reference = ['Enter the external payment reference (at least 3 characters).'];
  if (markPaidForm.paid_date === '') markPaidErrors.paid_date = ['Enter the date the external payment was made.'];
  else if (markPaidForm.paid_date > new Date().toISOString().slice(0, 10)) markPaidErrors.paid_date = ['The paid date cannot be in the future.'];
  return Object.keys(markPaidErrors).length === 0;
}
async function submitMarkPaid(): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating || !validateMarkPaid()) return;
  markPaidError.value = null;
  markPaidStepUp.value = false;
  try {
    await store.markPaid(run.id, {
      external_payment_reference: markPaidForm.external_payment_reference.trim(),
      paid_date: markPaidForm.paid_date,
    });
    markPaidOpen.value = false;
    await announce('Payout run marked paid. Servana recorded the external settlement — no money was moved.');
    notifications.addToast({ type: 'success', message: 'Payout run marked paid.' });
    await store.fetchRuns('finance', store.meta.current_page);
  } catch (err) {
    if (mapStepUp(err, (v) => { markPaidStepUp.value = v; })) return;
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message, fields } = err.apiError;
      if (code === 'validation_failed' && Object.keys(fields).length > 0) { Object.assign(markPaidErrors, fields); return; }
      if (code === 'invalid_state_transition') { markPaidError.value = 'This run is no longer approved. Reload to see its latest status.'; return; }
      if (code === 'idempotency_conflict') { markPaidError.value = 'This payout is already being recorded. Reload before trying again to avoid a duplicate.'; return; }
      markPaidError.value = message ?? 'The payout could not be marked paid.';
      return;
    }
    markPaidError.value = 'Something went wrong. Please try again.';
  }
}

/* ---------------------------------------------------------------- error mapping */
function mapStepUp(err: unknown, set: (v: boolean) => void): boolean {
  if (axios.isAxiosError(err) && err.apiError && (err.apiError.code === 'step_up_required' || err.apiError.code === 'privileged_mfa_required')) {
    set(true);
    return true;
  }
  return false;
}
function mapMutationError(err: unknown, fieldErrors?: Record<string, string[]>): void {
  if (mapStepUp(err, (v) => { stepUpRequired.value = v; })) return;
  if (!axios.isAxiosError(err) || !err.apiError) { actionError.value = 'Something went wrong. Please try again.'; return; }
  const { code, message, fields } = err.apiError;
  switch (code) {
    case 'invalid_state_transition':
      actionError.value = 'This payout run can no longer take that action in its current state. Reload to see the latest status.';
      return;
    case 'idempotency_conflict':
      actionError.value = 'This action is already being processed. Reload before trying again.';
      return;
    case 'validation_failed':
      if (fieldErrors && Object.keys(fields).length > 0) { Object.assign(fieldErrors, fields); return; }
      actionError.value = message ?? 'Please correct the highlighted fields.';
      return;
    default:
      actionError.value = message ?? 'The action could not be completed.';
  }
}
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Payout runs
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      Verify submitted payout runs, approve ordinary runs, reject, and mark an approved run paid after an
      external payment has been made. Marking a run paid records that a payment already happened outside
      Servana — Servana does not move any money.
    </p>

    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="payout-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="store.forbidden || !canView"
      data-testid="payout-forbidden"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to payout runs.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <form
        class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
        novalidate
        aria-label="Payout run filters"
        @submit.prevent="applyFilters"
      >
        <SvSelect
          id="filter-status"
          label="Status"
          :model-value="store.filters.status"
          :options="PAYOUT_RUN_STATUS_FILTER"
          @update:model-value="store.filters.status = $event"
        />
        <SvInput
          id="filter-currency"
          label="Currency"
          hint="3-letter code, e.g. KES"
          :model-value="store.filters.currency"
          @update:model-value="store.filters.currency = $event"
        />
        <SvInput
          id="filter-branch"
          label="Branch reference"
          hint="26-character branch reference"
          :model-value="store.filters.branch_ulid"
          @update:model-value="store.filters.branch_ulid = $event"
        />
        <div class="flex items-end gap-2">
          <SvButton
            type="submit"
            data-testid="apply-filters"
          >
            Apply
          </SvButton>
          <SvButton
            type="button"
            variant="secondary"
            data-testid="clear-filters"
            @click="clearFilters"
          >
            Clear
          </SvButton>
        </div>
      </form>

      <SvStateBoundary
        class="mt-6"
        :state="listState"
        :error-message="store.listError ?? undefined"
        empty-message="No payout runs match these filters."
        @retry="store.fetchRuns('finance')"
      >
        <ul class="mt-2 flex flex-col gap-3">
          <li
            v-for="run in store.runs"
            :key="run.id"
            data-testid="payout-run-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-text">
                    {{ money(run.gross_total_minor, run.currency) }}
                    <span class="ml-1 text-xs font-normal text-text-muted">gross · {{ run.item_count ?? 0 }} staff</span>
                  </p>
                  <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">{{ payoutRunStatusLabel(run.status) }}</span>
                    <span
                      v-if="run.is_high_value"
                      class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted"
                    >High value</span>
                  </p>
                  <p class="mt-1 text-xs text-text-muted">
                    {{ run.period_start }} → {{ run.period_end }} · {{ run.currency }}
                  </p>
                </div>
                <SvButton
                  variant="secondary"
                  :data-testid="`run-details-${run.id}`"
                  @click="openDetail(run)"
                >
                  Open
                </SvButton>
              </div>
            </SvCard>
          </li>
        </ul>

        <nav
          v-if="store.meta.last_page > 1"
          class="mt-4 flex items-center justify-between"
          aria-label="Payout runs pagination"
        >
          <SvButton
            variant="secondary"
            :disabled="store.meta.current_page <= 1"
            @click="goPage(store.meta.current_page - 1)"
          >
            Previous
          </SvButton>
          <span class="text-sm text-text-muted">Page {{ store.meta.current_page }} of {{ store.meta.last_page }}</span>
          <SvButton
            variant="secondary"
            :disabled="store.meta.current_page >= store.meta.last_page"
            @click="goPage(store.meta.current_page + 1)"
          >
            Next
          </SvButton>
        </nav>
      </SvStateBoundary>
    </template>

    <!-- detail modal -->
    <SvModal
      :open="detailOpen"
      title="Payout run"
      description="A server-authoritative payout run. Amounts are exact integer minor units that Servana calculated."
      @close="closeDetail"
    >
      <div v-if="store.detailLoading">
        <p class="text-sm text-text-muted">
          Loading…
        </p>
      </div>
      <div
        v-else-if="detailRun"
        class="flex flex-col gap-4"
      >
        <!-- step-up-required safe state -->
        <div
          v-if="stepUpRequired"
          data-testid="payout-step-up"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            A fresh identity check is required
          </p>
          <p class="mt-1">
            This action needs a recent step-up verification. Verify your identity, then try the action
            again. Nothing has changed yet.
          </p>
          <div class="mt-2">
            <SvButton
              type="button"
              variant="secondary"
              data-testid="payout-verify-identity"
              @click="verifyStepUp"
            >
              Verify identity
            </SvButton>
          </div>
        </div>

        <p
          v-if="actionError"
          data-testid="action-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ actionError }}
        </p>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <dt class="text-text-muted">
            Status
          </dt>
          <dd class="text-text">
            {{ payoutRunStatusLabel(detailRun.status) }}
          </dd>
          <dt class="text-text-muted">
            Period
          </dt>
          <dd class="text-text">
            {{ detailRun.period_start }} → {{ detailRun.period_end }}
          </dd>
          <dt class="text-text-muted">
            Gross total
          </dt>
          <dd class="text-text">
            {{ money(detailRun.gross_total_minor, detailRun.currency) }}
          </dd>
          <dt class="text-text-muted">
            High value
          </dt>
          <dd class="text-text">
            {{ detailRun.is_high_value ? 'Yes — needs Merchant Administrator approval' : 'No' }}
          </dd>
          <template v-if="detailRun.has_external_payment_reference">
            <dt class="text-text-muted">
              External payment
            </dt>
            <dd class="text-text">
              Recorded<span v-if="detailRun.paid_at"> on {{ detailRun.paid_at.slice(0, 10) }}</span>
            </dd>
          </template>
          <template v-if="detailRun.rejection_reason">
            <dt class="text-text-muted">
              Rejection reason
            </dt>
            <dd class="break-words text-text">
              {{ detailRun.rejection_reason }}
            </dd>
          </template>
        </dl>

        <section aria-labelledby="fin-items-heading">
          <h3
            id="fin-items-heading"
            class="text-sm font-semibold text-text"
          >
            Items
          </h3>
          <ul class="mt-2 flex flex-col gap-2">
            <li
              v-for="item in detailRun.items ?? []"
              :key="item.id"
              data-testid="payout-item-row"
            >
              <SvCard padding="sm">
                <p class="text-sm font-semibold text-text">
                  {{ item.staff_display_name ?? 'Staff member' }} — {{ money(item.gross_amount_minor, item.currency) }}
                </p>
              </SvCard>
            </li>
          </ul>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
          <SvButton
            v-if="canVerifyNow"
            type="button"
            data-testid="verify-run"
            :loading="store.mutating"
            @click="doVerify"
          >
            Verify
          </SvButton>
          <SvButton
            v-if="canApproveNow"
            type="button"
            data-testid="approve-run"
            :loading="store.mutating"
            @click="doApprove"
          >
            Approve
          </SvButton>
          <SvButton
            v-if="canRejectNow"
            type="button"
            variant="secondary"
            data-testid="reject-run"
            @click="openReject"
          >
            Reject
          </SvButton>
          <SvButton
            v-if="canMarkPaidNow"
            type="button"
            data-testid="mark-paid"
            @click="openMarkPaid"
          >
            Mark paid
          </SvButton>
          <SvButton
            variant="secondary"
            @click="closeDetail"
          >
            Close
          </SvButton>
        </div>
      </div>
    </SvModal>

    <!-- reject modal -->
    <SvModal
      :open="rejectOpen"
      title="Reject payout run"
      description="Rejecting releases the claimed salary, commission and adjustments back to the eligible pool. A new draft can be prepared. A reason is required."
      @close="rejectOpen = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitReject"
      >
        <SvTextarea
          id="reject-reason"
          label="Reason"
          :model-value="rejectReason"
          :errors="rejectErrors.reason"
          required
          @update:model-value="rejectReason = $event"
        />
        <div class="flex justify-end gap-2">
          <SvButton
            type="button"
            variant="secondary"
            @click="rejectOpen = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            data-testid="reject-submit"
            :loading="store.mutating"
          >
            Reject run
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- mark-paid modal -->
    <SvModal
      :open="markPaidOpen"
      title="Mark payout run paid"
      description="Record that an external payment has already been made for this run. Servana does not move money — it only records the settlement. This needs a fresh step-up."
      @close="markPaidOpen = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitMarkPaid"
      >
        <div
          data-testid="mark-paid-warning"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            This records an external payment
          </p>
          <p class="mt-1">
            Only mark a run paid once the payment has actually been made outside Servana. Servana does not
            transfer any funds.
          </p>
        </div>

        <div
          v-if="markPaidStepUp"
          data-testid="mark-paid-step-up"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            A fresh identity check is required
          </p>
          <p class="mt-1">
            Verify your identity, then mark the run paid again. Your entries below are kept.
          </p>
          <div class="mt-2">
            <SvButton
              type="button"
              variant="secondary"
              data-testid="mark-paid-verify"
              @click="verifyStepUp"
            >
              Verify identity
            </SvButton>
          </div>
        </div>

        <p
          v-if="markPaidError"
          data-testid="mark-paid-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ markPaidError }}
        </p>

        <SvInput
          id="mark-paid-reference"
          label="External payment reference"
          hint="The reference for the payment made outside Servana."
          :model-value="markPaidForm.external_payment_reference"
          :errors="markPaidErrors.external_payment_reference"
          required
          @update:model-value="markPaidForm.external_payment_reference = $event"
        />
        <SvInput
          id="mark-paid-date"
          label="Paid date"
          type="date"
          hint="The date the external payment was made (not in the future)."
          :model-value="markPaidForm.paid_date"
          :errors="markPaidErrors.paid_date"
          required
          @update:model-value="markPaidForm.paid_date = $event"
        />
        <div class="flex justify-end gap-2">
          <SvButton
            type="button"
            variant="secondary"
            @click="markPaidOpen = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            data-testid="mark-paid-submit"
            :loading="store.mutating"
          >
            Record external payment
          </SvButton>
        </div>
      </form>
    </SvModal>
  </section>
</template>
