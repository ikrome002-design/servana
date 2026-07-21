<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
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
import { useEarningsQueryStore, type EarningsQuery } from '@/stores/earningsQueryStore';
import { formatMoney } from '@/utils/money';
import {
  EARNINGS_QUERY_STATUS_FILTER,
  assignedRoleLabel,
  earningsQueryStatusLabel,
  earningsQuerySubjectLabel,
  earningsQueryTypeLabel,
} from '@/content/payout';

/**
 * Finance earnings-query responder (Plan §63, §19.3; Phase 20H; D-H12-1). Finance is the sole
 * authoritative responder: read the merchant-scoped query queue and resolve/reject a query. A monetary
 * correction is created ONLY as an additive compensation adjustment (never a ledger edit) and is optional
 * on a resolve. Respond is a financial mutation — Idempotency-Key is server-enforced (a replay never
 * duplicates the adjustment). Every control is UX only; the API is authoritative.
 */
const store = useEarningsQueryStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canRespond = computed(() => can('earnings_query.respond'));

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
  if (store.queries.length === 0) return 'empty';
  return 'success';
});
onMounted(() => {
  if (canRespond.value) void store.fetchQueries('finance', 1);
});
watch(
  () => auth.branchIds,
  () => {
    store.$reset();
    if (canRespond.value) void store.fetchQueries('finance', 1);
  },
);
async function applyStatus(): Promise<void> {
  store.meta.current_page = 1;
  await store.fetchQueries('finance', 1);
}
function goPage(page: number): void {
  if (page >= 1 && page <= store.meta.last_page) void store.fetchQueries('finance', page);
}

/* ---------------------------------------------------------------- respond */
const detailOpen = ref(false);
const responseError = ref<string | null>(null);
const responseErrors = reactive<Record<string, string[]>>({});
const form = reactive({
  decision: 'resolved' as 'resolved' | 'rejected',
  resolution_note: '',
  withCorrection: false,
  direction: 'increase',
  amount_major: '',
  currency: 'KES',
  reason: '',
});
const detailQuery = computed<EarningsQuery | null>(() => store.currentQuery);
const isTerminal = computed(() => ['resolved', 'rejected'].includes(detailQuery.value?.status ?? ''));

const DECISION_OPTIONS = [
  { value: 'resolved', label: 'Resolve' },
  { value: 'rejected', label: 'Reject' },
];
const DIRECTION_OPTIONS = [
  { value: 'increase', label: 'Increase earnings (positive)' },
  { value: 'decrease', label: 'Reduce earnings (negative)' },
];

/** Parse a non-negative major-unit amount to integer minor units WITHOUT floating-point arithmetic. */
function majorToMinor(input: string): number | null {
  const trimmed = input.trim();
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;
  const [whole, frac = ''] = trimmed.split('.');
  const cents = `${frac}00`.slice(0, 2);
  return Number(whole) * 100 + Number(cents);
}
const signedMinor = computed<number | null>(() => {
  const magnitude = majorToMinor(form.amount_major);
  if (magnitude === null || magnitude === 0) return null;
  return form.direction === 'decrease' ? -magnitude : magnitude;
});
const correctionPreview = computed<string | null>(() => {
  if (!form.withCorrection || form.decision !== 'resolved' || signedMinor.value === null) return null;
  const cur = form.currency.trim().toUpperCase();
  if (!/^[A-Z]{3}$/.test(cur)) return null;
  return money(signedMinor.value, cur);
});

async function openDetail(query: EarningsQuery): Promise<void> {
  rememberFocus();
  responseError.value = null;
  Object.keys(responseErrors).forEach((k) => delete responseErrors[k]);
  form.decision = 'resolved';
  form.resolution_note = '';
  form.withCorrection = false;
  form.direction = 'increase';
  form.amount_major = '';
  form.currency = 'KES';
  form.reason = '';
  detailOpen.value = true;
  const fresh = await store.fetchQuery('finance', query.id);
  if (!fresh) store.currentQuery = query;
}
function closeDetail(): void {
  detailOpen.value = false;
  restoreFocus();
}
function validate(): boolean {
  Object.keys(responseErrors).forEach((k) => delete responseErrors[k]);
  if (form.resolution_note.trim().length < 3) responseErrors.resolution_note = ['A response of at least 3 characters is required.'];
  if (form.withCorrection && form.decision === 'resolved') {
    if (signedMinor.value === null) responseErrors['correction.amount_minor'] = ['Enter a correction amount greater than zero.'];
    if (!/^[A-Za-z]{3}$/.test(form.currency.trim())) responseErrors['correction.currency'] = ['Enter a 3-letter currency code.'];
    if (form.reason.trim().length < 3) responseErrors['correction.reason'] = ['A correction reason of at least 3 characters is required.'];
  }
  return Object.keys(responseErrors).length === 0;
}
async function submitRespond(): Promise<void> {
  const query = detailQuery.value;
  if (!query || store.mutating || !validate()) return;
  responseError.value = null;
  const payload = {
    decision: form.decision,
    resolution_note: form.resolution_note.trim(),
    ...(form.withCorrection && form.decision === 'resolved' && signedMinor.value !== null
      ? { correction: { amount_minor: signedMinor.value, currency: form.currency.trim().toUpperCase(), reason: form.reason.trim() } }
      : {}),
  };
  try {
    await store.respond(query.id, payload);
    detailOpen.value = false;
    restoreFocus();
    await announce(form.decision === 'resolved' ? 'Query resolved.' : 'Query rejected.');
    notifications.addToast({ type: 'success', message: form.decision === 'resolved' ? 'Query resolved.' : 'Query rejected.' });
    await store.fetchQueries('finance', store.meta.current_page);
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message, fields } = err.apiError;
      if (code === 'invalid_state_transition') { responseError.value = 'This query has already been resolved or rejected. Reload to see the latest.'; return; }
      if (code === 'idempotency_conflict') { responseError.value = 'This response is already being processed. Reload before trying again.'; return; }
      if (code === 'validation_failed' && Object.keys(fields).length > 0) { Object.assign(responseErrors, fields); return; }
      responseError.value = message ?? 'The response could not be saved.';
      return;
    }
    responseError.value = 'Something went wrong. Please try again.';
  }
}
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Earnings queries
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      Questions staff have raised about their own earnings. Resolve or reject each one. If a correction is
      needed, it is recorded as a separate additive adjustment — the original earnings are never rewritten.
    </p>

    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="query-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="store.forbidden || !canRespond"
      data-testid="query-forbidden"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to earnings queries.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <form
        class="mt-6 flex flex-wrap items-end gap-3"
        novalidate
        aria-label="Earnings query filters"
        @submit.prevent="applyStatus"
      >
        <SvSelect
          id="filter-status"
          label="Status"
          :model-value="store.statusFilter"
          :options="EARNINGS_QUERY_STATUS_FILTER"
          @update:model-value="store.statusFilter = $event"
        />
        <SvButton
          type="submit"
          data-testid="apply-filters"
        >
          Apply
        </SvButton>
      </form>

      <SvStateBoundary
        class="mt-6"
        :state="listState"
        :error-message="store.listError ?? undefined"
        empty-message="No earnings queries match this filter."
        @retry="store.fetchQueries('finance')"
      >
        <ul class="mt-2 flex flex-col gap-3">
          <li
            v-for="query in store.queries"
            :key="query.id"
            data-testid="query-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-semibold text-text">
                    {{ earningsQueryTypeLabel(query.query_type) }}
                  </p>
                  <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">{{ earningsQueryStatusLabel(query.status) }}</span>
                    <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">{{ earningsQuerySubjectLabel(query.subject_type) }}</span>
                    <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">Routed to {{ assignedRoleLabel(query.assigned_role) }}</span>
                  </p>
                  <p class="mt-1 break-words text-sm text-text">
                    {{ query.body }}
                  </p>
                </div>
                <SvButton
                  variant="secondary"
                  :data-testid="`query-open-${query.id}`"
                  @click="openDetail(query)"
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
          aria-label="Earnings queries pagination"
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

    <!-- detail + respond modal -->
    <SvModal
      :open="detailOpen"
      title="Respond to earnings query"
      description="Resolve or reject the query. An optional correction is recorded as a separate additive compensation adjustment; the original earnings are never edited."
      @close="closeDetail"
    >
      <div
        v-if="detailQuery"
        class="flex flex-col gap-4"
      >
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
          <dt class="text-text-muted">
            Type
          </dt>
          <dd class="text-text">
            {{ earningsQueryTypeLabel(detailQuery.query_type) }}
          </dd>
          <dt class="text-text-muted">
            About
          </dt>
          <dd class="text-text">
            {{ earningsQuerySubjectLabel(detailQuery.subject_type) }}
          </dd>
          <dt class="text-text-muted">
            Status
          </dt>
          <dd class="text-text">
            {{ earningsQueryStatusLabel(detailQuery.status) }}
          </dd>
          <dt class="text-text-muted">
            Message
          </dt>
          <dd class="break-words text-text">
            {{ detailQuery.body }}
          </dd>
        </dl>

        <p
          v-if="isTerminal"
          data-testid="query-terminal"
          class="rounded-control bg-surface-alt px-3 py-2 text-sm text-text-muted"
        >
          This query has already been {{ earningsQueryStatusLabel(detailQuery.status).toLowerCase() }}.
          <span v-if="detailQuery.resolution_note">Response: {{ detailQuery.resolution_note }}</span>
        </p>

        <form
          v-else
          class="flex flex-col gap-4"
          novalidate
          @submit.prevent="submitRespond"
        >
          <p
            v-if="responseError"
            data-testid="respond-error"
            class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
            role="alert"
          >
            {{ responseError }}
          </p>

          <SvSelect
            id="respond-decision"
            label="Decision"
            :model-value="form.decision"
            :options="DECISION_OPTIONS"
            required
            @update:model-value="form.decision = ($event as 'resolved' | 'rejected')"
          />
          <SvTextarea
            id="respond-note"
            label="Response to the staff member"
            :model-value="form.resolution_note"
            :errors="responseErrors.resolution_note"
            required
            @update:model-value="form.resolution_note = $event"
          />

          <div
            v-if="form.decision === 'resolved'"
            class="rounded-control border border-border p-3"
          >
            <label class="flex items-center gap-2 text-sm text-text">
              <input
                type="checkbox"
                data-testid="with-correction"
                :checked="form.withCorrection"
                @change="form.withCorrection = ($event.target as HTMLInputElement).checked"
              >
              Also record a monetary correction (a separate additive adjustment)
            </label>
            <div
              v-if="form.withCorrection"
              class="mt-3 grid gap-3 sm:grid-cols-3"
            >
              <SvSelect
                id="correction-direction"
                label="Direction"
                :model-value="form.direction"
                :options="DIRECTION_OPTIONS"
                @update:model-value="form.direction = $event"
              />
              <SvInput
                id="correction-amount"
                label="Amount (major units)"
                type="number"
                :model-value="form.amount_major"
                :errors="responseErrors['correction.amount_minor']"
                @update:model-value="form.amount_major = $event"
              />
              <SvInput
                id="correction-currency"
                label="Currency"
                :model-value="form.currency"
                :errors="responseErrors['correction.currency']"
                @update:model-value="form.currency = $event"
              />
              <SvInput
                id="correction-reason"
                label="Correction reason"
                class="sm:col-span-3"
                :model-value="form.reason"
                :errors="responseErrors['correction.reason']"
                @update:model-value="form.reason = $event"
              />
              <p
                v-if="correctionPreview"
                data-testid="correction-preview"
                class="text-sm text-text sm:col-span-3"
                role="status"
              >
                This will record a separate adjustment of <span class="font-semibold">{{ correctionPreview }}</span>.
                It does not edit the original earnings.
              </p>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <SvButton
              type="button"
              variant="secondary"
              @click="closeDetail"
            >
              Cancel
            </SvButton>
            <SvButton
              type="submit"
              data-testid="respond-submit"
              :loading="store.mutating"
            >
              Save response
            </SvButton>
          </div>
        </form>

        <div
          v-if="isTerminal"
          class="flex justify-end"
        >
          <SvButton
            variant="secondary"
            @click="closeDetail"
          >
            Close
          </SvButton>
        </div>
      </div>
    </SvModal>
  </section>
</template>
