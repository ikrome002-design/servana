<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePlatformFeeStore, type PlatformFeeLedgerEntry } from '@/stores/platformFeeStore';
import { usePlatformFeeDisputeStore, type PlatformFeeDispute } from '@/stores/platformFeeDisputeStore';
import { formatMoney } from '@/utils/money';
import {
  PLATFORM_FEE_DISPUTE_STATUS_FILTER,
  PLATFORM_FEE_DISPUTE_STATUS_LABELS,
  PLATFORM_FEE_ENTRY_TYPE_FILTER,
  PLATFORM_FEE_ENTRY_TYPE_LABELS,
  PLATFORM_FEE_LEDGER_STATUS_LABELS,
  tierLabel,
} from '@/content/platformFee';

// Phase 20E — the merchant/Finance/Branch/Audit platform-fee surface (Plan §51, §19.3). ONE component,
// server-side scoped by the backend (`platform_fee.view`): Merchant Admin/Finance see the whole merchant,
// Branch Manager/Audit only branch-attributable entries. Controls are UX-gated by permission — the API is
// the security boundary. The browser never recomputes fee money; it formats the server's integer minor
// units. Merchant Admin/Finance may raise disputes; Finance reviews/resolves/rejects (fresh step-up on
// resolve/reject). No Wallet/settlement terminology.
const fees = usePlatformFeeStore();
const disputes = usePlatformFeeDisputeStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canDispute = computed(() => can('platform_fee.dispute'));
const canReview = computed(() => can('platform_fee.dispute.review'));

const detailEntry = ref<PlatformFeeLedgerEntry | null>(null);

const entriesState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (fees.loading) return 'loading';
  if (fees.error) return 'error';
  if (fees.entries.length === 0) return 'empty';
  return 'success';
});
const disputesState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (disputes.loading) return 'loading';
  if (disputes.error) return 'error';
  if (disputes.disputes.length === 0) return 'empty';
  return 'success';
});

function money(minor: number, currency: string): string {
  return formatMoney(minor, currency);
}
function entryTypeLabel(t: string): string {
  return PLATFORM_FEE_ENTRY_TYPE_LABELS[t] ?? t;
}
function ledgerStatusLabel(s: string): string {
  return PLATFORM_FEE_LEDGER_STATUS_LABELS[s] ?? s;
}
function disputeStatusLabel(s: string): string {
  return PLATFORM_FEE_DISPUTE_STATUS_LABELS[s] ?? s;
}

onMounted(() => {
  void fees.fetchSummary();
  void fees.fetchEntries();
  void disputes.fetchDisputes();
});

// --- Dispute creation ---------------------------------------------------------------------------------
const createOpen = ref(false);
const createSubmitting = ref(false);
const createError = ref<string | null>(null);
const createForm = reactive({ platform_fee_ledger_entry: '', subscription_invoice: '', reason: '', evidence_file: '' });
const createErrors = reactive<Record<string, string[]>>({});

function openCreate(entry?: PlatformFeeLedgerEntry): void {
  createForm.platform_fee_ledger_entry = entry?.id ?? '';
  createForm.subscription_invoice = '';
  createForm.reason = '';
  createForm.evidence_file = '';
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  createError.value = null;
  createOpen.value = true;
}

async function submitCreate(): Promise<void> {
  if (createSubmitting.value) return;
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  if (createForm.reason.trim().length < 2) {
    createErrors.reason = ['A reason is required.'];
    return;
  }
  if (createForm.platform_fee_ledger_entry.trim() === '' && createForm.subscription_invoice.trim() === '') {
    createError.value = 'Provide a fee entry or a subscription invoice to dispute.';
    return;
  }
  createSubmitting.value = true;
  createError.value = null;
  try {
    await disputes.createDispute({
      platform_fee_ledger_entry: createForm.platform_fee_ledger_entry.trim() === '' ? null : createForm.platform_fee_ledger_entry.trim(),
      subscription_invoice: createForm.subscription_invoice.trim() === '' ? null : createForm.subscription_invoice.trim(),
      reason: createForm.reason.trim(),
      evidence_file: createForm.evidence_file.trim() === '' ? null : createForm.evidence_file.trim(),
    });
    notifications.addToast({ type: 'success', message: 'Dispute raised.' });
    createOpen.value = false;
    await disputes.fetchDisputes();
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      Object.assign(createErrors, err.apiError.fields);
      createError.value = err.apiError.message ?? 'The dispute could not be raised.';
    } else {
      createError.value = 'Something went wrong.';
    }
  } finally {
    createSubmitting.value = false;
  }
}

// --- Finance review / resolve / reject ----------------------------------------------------------------
const reviewTarget = ref<PlatformFeeDispute | null>(null);
const reviewMode = ref<'resolve' | 'reject'>('resolve');
const reviewNote = ref('');
const reviewMoneyMajor = ref('');
const reviewSubmitting = ref(false);
const reviewError = ref<string | null>(null);

async function startReview(dispute: PlatformFeeDispute): Promise<void> {
  try {
    await disputes.startReview(dispute.id);
    notifications.addToast({ type: 'success', message: 'Review started.' });
    await disputes.fetchDisputes();
  } catch (err) {
    notifications.addToast({
      type: 'error',
      message: axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Could not start the review.',
    });
  }
}

function openResolve(dispute: PlatformFeeDispute, m: 'resolve' | 'reject'): void {
  reviewTarget.value = dispute;
  reviewMode.value = m;
  reviewNote.value = '';
  reviewMoneyMajor.value = '';
  reviewError.value = null;
}

async function submitReview(): Promise<void> {
  if (reviewTarget.value === null || reviewSubmitting.value) return;
  if (reviewNote.value.trim().length < 2) {
    reviewError.value = 'A note is required.';
    return;
  }
  reviewSubmitting.value = true;
  reviewError.value = null;
  try {
    if (reviewMode.value === 'reject') {
      await disputes.reject(reviewTarget.value.id, reviewNote.value.trim());
      notifications.addToast({ type: 'success', message: 'Dispute rejected.' });
    } else {
      const major = reviewMoneyMajor.value.trim();
      const money = major === '' ? null : Math.round(Number(major) * 100);
      await disputes.resolve(reviewTarget.value.id, reviewNote.value.trim(), money);
      notifications.addToast({ type: 'success', message: 'Dispute resolved.' });
    }
    reviewTarget.value = null;
    await disputes.fetchDisputes();
  } catch (err) {
    reviewError.value = axios.isAxiosError(err) && err.apiError
      ? (err.apiError.code === 'financial_period_locked'
          ? 'The financial period is locked; a controlled reopen is required before resolving.'
          : err.apiError.message ?? 'The action failed (a stale step-up or invalid transition may be the cause).')
      : 'Something went wrong.';
  } finally {
    reviewSubmitting.value = false;
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Platform fees
    </h1>
    <p class="mt-1 max-w-2xl text-sm text-text-muted">
      The percentage fee Servana charges on validated merchant-client activity. Amounts are shown exactly as
      Servana calculated them. Pending fees are not yet on a subscription invoice; invoiced fees appear on an
      issued invoice; adjustments are later additive corrections that never rewrite issued history.
    </p>

    <!-- Summary cards (server-authoritative earned totals per currency) -->
    <section
      aria-labelledby="pf-summary-heading"
      class="mt-6"
    >
      <h2
        id="pf-summary-heading"
        class="text-sm font-semibold text-text"
      >
        Earned fee summary
      </h2>
      <SvStateBoundary
        class="mt-2"
        :state="fees.summaryLoading ? 'loading' : fees.summary.length === 0 ? 'empty' : 'success'"
        empty-message="No earned platform fees yet."
      >
        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <SvCard
            v-for="row in fees.summary"
            :key="row.currency"
            padding="sm"
          >
            <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
              {{ row.currency }} · {{ row.entry_count }} entries
            </p>
            <p class="mt-1 text-lg font-bold text-text">
              {{ money(row.gross_platform_fee_minor, row.currency) }}
            </p>
            <dl class="mt-2 space-y-0.5 text-xs text-text-muted">
              <div class="flex justify-between gap-2">
                <dt>Client-shifted</dt>
                <dd>{{ money(row.client_shifted_amount_minor, row.currency) }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt>Merchant-absorbed</dt>
                <dd>{{ money(row.merchant_absorbed_amount_minor, row.currency) }}</dd>
              </div>
            </dl>
          </SvCard>
        </div>
      </SvStateBoundary>
    </section>

    <!-- Fee entries -->
    <section
      aria-labelledby="pf-entries-heading"
      class="mt-8"
    >
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2
          id="pf-entries-heading"
          class="text-sm font-semibold text-text"
        >
          Fee entries
        </h2>
        <div class="w-48">
          <SvSelect
            id="pf-entry-type-filter"
            label="Entry type"
            :model-value="fees.filterEntryType"
            :options="PLATFORM_FEE_ENTRY_TYPE_FILTER"
            @update:model-value="(fees.filterEntryType = $event), fees.fetchEntries()"
          />
        </div>
      </div>
      <SvStateBoundary
        class="mt-2"
        :state="entriesState"
        :error-message="fees.error ?? undefined"
        empty-message="No platform-fee entries match this filter."
        @retry="fees.fetchEntries()"
      >
        <ul class="mt-2 flex flex-col gap-3">
          <li
            v-for="entry in fees.entries"
            :key="entry.id"
            data-testid="platform-fee-entry-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-text">
                    {{ money(entry.merchant_liability_minor, entry.currency) }}
                    <span class="ml-2 rounded-control bg-surface-alt px-2 py-0.5 text-xs text-text-muted">
                      {{ entryTypeLabel(entry.entry_type) }}
                    </span>
                    <span class="ml-2 rounded-control bg-surface-alt px-2 py-0.5 text-xs text-text-muted">
                      {{ ledgerStatusLabel(entry.status) }}
                    </span>
                  </p>
                  <p class="mt-1 break-words text-xs text-text-muted">
                    {{ tierLabel(entry.service_fee_tier) }} · {{ (entry.percentage_rate_basis_points / 100).toFixed(2) }}%
                    · billable {{ entry.billable_at }}
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <SvButton
                    variant="secondary"
                    @click="detailEntry = entry"
                  >
                    Details
                  </SvButton>
                  <SvButton
                    v-if="canDispute"
                    variant="ghost"
                    @click="openCreate(entry)"
                  >
                    Dispute
                  </SvButton>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>
    </section>

    <!-- Disputes -->
    <section
      aria-labelledby="pf-disputes-heading"
      class="mt-8"
    >
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2
          id="pf-disputes-heading"
          class="text-sm font-semibold text-text"
        >
          Disputes
        </h2>
        <div class="flex items-end gap-3">
          <div class="w-44">
            <SvSelect
              id="pf-dispute-status-filter"
              label="Status"
              :model-value="disputes.filterStatus"
              :options="PLATFORM_FEE_DISPUTE_STATUS_FILTER"
              @update:model-value="(disputes.filterStatus = $event), disputes.fetchDisputes()"
            />
          </div>
          <SvButton
            v-if="canDispute"
            @click="openCreate()"
          >
            Raise a dispute
          </SvButton>
        </div>
      </div>
      <SvStateBoundary
        class="mt-2"
        :state="disputesState"
        :error-message="disputes.error ?? undefined"
        empty-message="No disputes match this filter."
        @retry="disputes.fetchDisputes()"
      >
        <ul class="mt-2 flex flex-col gap-3">
          <li
            v-for="dispute in disputes.disputes"
            :key="dispute.id"
            data-testid="platform-fee-dispute-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-text">
                    <span class="rounded-control bg-surface-alt px-2 py-0.5 text-xs text-text-muted">
                      {{ disputeStatusLabel(dispute.status) }}
                    </span>
                    <span
                      v-if="dispute.has_evidence"
                      class="ml-2 text-xs text-text-muted"
                    >📎 evidence attached</span>
                  </p>
                  <p class="mt-1 break-words text-sm text-text">
                    {{ dispute.reason }}
                  </p>
                  <p
                    v-if="dispute.resolution_note"
                    class="mt-1 break-words text-xs text-text-muted"
                  >
                    Resolution: {{ dispute.resolution_note }}
                  </p>
                </div>
                <div
                  v-if="canReview"
                  class="flex flex-wrap gap-2"
                >
                  <SvButton
                    v-if="dispute.capabilities.reviewable"
                    variant="secondary"
                    @click="startReview(dispute)"
                  >
                    Start review
                  </SvButton>
                  <SvButton
                    v-if="dispute.capabilities.resolvable"
                    @click="openResolve(dispute, 'resolve')"
                  >
                    Resolve
                  </SvButton>
                  <SvButton
                    v-if="dispute.capabilities.rejectable"
                    variant="destructive"
                    @click="openResolve(dispute, 'reject')"
                  >
                    Reject
                  </SvButton>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>
    </section>

    <!-- Entry detail modal -->
    <SvModal
      :open="detailEntry !== null"
      title="Fee entry detail"
      @close="detailEntry = null"
    >
      <dl
        v-if="detailEntry"
        class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
      >
        <dt class="text-text-muted">
          Entry type
        </dt>
        <dd class="text-text">
          {{ entryTypeLabel(detailEntry.entry_type) }}
        </dd>
        <dt class="text-text-muted">
          Status
        </dt>
        <dd class="text-text">
          {{ ledgerStatusLabel(detailEntry.status) }}
        </dd>
        <dt class="text-text-muted">
          Tier
        </dt>
        <dd class="text-text">
          {{ tierLabel(detailEntry.service_fee_tier) }}
        </dd>
        <dt class="text-text-muted">
          Gross fee
        </dt>
        <dd class="text-text">
          {{ money(detailEntry.gross_platform_fee_minor, detailEntry.currency) }}
        </dd>
        <dt class="text-text-muted">
          Client-shifted
        </dt>
        <dd class="text-text">
          {{ money(detailEntry.client_shifted_amount_minor, detailEntry.currency) }}
        </dd>
        <dt class="text-text-muted">
          Merchant-absorbed
        </dt>
        <dd class="text-text">
          {{ money(detailEntry.merchant_absorbed_amount_minor, detailEntry.currency) }}
        </dd>
        <dt class="text-text-muted">
          Merchant liability
        </dt>
        <dd class="text-text">
          {{ money(detailEntry.merchant_liability_minor, detailEntry.currency) }}
        </dd>
        <dt class="text-text-muted">
          Source invoice
        </dt>
        <dd class="break-all text-text">
          {{ detailEntry.source_invoice_id }}
        </dd>
      </dl>
    </SvModal>

    <!-- Dispute creation modal -->
    <SvModal
      :open="createOpen"
      title="Raise a dispute"
      @close="createOpen = false"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="submitCreate"
      >
        <p
          v-if="createError"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ createError }}
        </p>
        <SvInput
          id="pf-dispute-entry"
          label="Fee entry reference (optional if an invoice is given)"
          :model-value="createForm.platform_fee_ledger_entry"
          :errors="createErrors.platform_fee_ledger_entry"
          @update:model-value="createForm.platform_fee_ledger_entry = $event"
        />
        <SvInput
          id="pf-dispute-invoice"
          label="Subscription invoice reference (optional if an entry is given)"
          :model-value="createForm.subscription_invoice"
          :errors="createErrors.subscription_invoice"
          @update:model-value="createForm.subscription_invoice = $event"
        />
        <SvTextarea
          id="pf-dispute-reason"
          label="Reason"
          required
          :model-value="createForm.reason"
          :errors="createErrors.reason"
          @update:model-value="createForm.reason = $event"
        />
        <SvInput
          id="pf-dispute-evidence"
          label="Evidence file reference (optional)"
          :model-value="createForm.evidence_file"
          :errors="createErrors.evidence_file"
          @update:model-value="createForm.evidence_file = $event"
        />
        <div class="flex justify-end gap-3">
          <SvButton
            variant="secondary"
            type="button"
            @click="createOpen = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            :loading="createSubmitting"
          >
            Raise dispute
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- Resolve / reject modal (Finance) -->
    <SvModal
      :open="reviewTarget !== null"
      :title="reviewMode === 'reject' ? 'Reject dispute' : 'Resolve dispute'"
      @close="reviewTarget = null"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="submitReview"
      >
        <p
          v-if="reviewError"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ reviewError }}
        </p>
        <SvTextarea
          id="pf-review-note"
          :label="reviewMode === 'reject' ? 'Rejection reason' : 'Resolution note'"
          required
          :model-value="reviewNote"
          @update:model-value="reviewNote = $event"
        />
        <SvInput
          v-if="reviewMode === 'resolve'"
          id="pf-review-money"
          label="Money change (optional; negative credits the merchant)"
          type="number"
          :model-value="reviewMoneyMajor"
          @update:model-value="reviewMoneyMajor = $event"
        />
        <p
          v-if="reviewMode === 'resolve'"
          class="text-xs text-text-muted"
        >
          A money change creates an additive adjustment on a future invoice — it never rewrites the original
          fee. Resolving requires a fresh step-up.
        </p>
        <div class="flex justify-end gap-3">
          <SvButton
            variant="secondary"
            type="button"
            @click="reviewTarget = null"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            :variant="reviewMode === 'reject' ? 'destructive' : 'primary'"
            :loading="reviewSubmitting"
          >
            {{ reviewMode === 'reject' ? 'Reject' : 'Resolve' }}
          </SvButton>
        </div>
      </form>
    </SvModal>
  </div>
</template>
