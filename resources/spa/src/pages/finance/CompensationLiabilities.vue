<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import {
  useCompensationLiabilityStore,
  type CompensationAdjustment,
  type LiabilityEntry,
} from '@/stores/compensationLiabilityStore';
import { formatMoney } from '@/utils/money';
import {
  ENTRY_TYPE_FILTER,
  LEDGER_STATUS_FILTER,
  LIABILITY_TYPE_FILTER,
  adjustmentTypeLabel,
  entryTypeLabel,
  ledgerStatusLabel,
  liabilityTypeLabel,
} from '@/content/compensationLiability';

/**
 * Finance Compensation Liabilities (Plan §61/§80, §19.3; Phase 20G). ONE Finance surface, merchant-scoped
 * and masked by the backend (`compensation.liability.view`). Every control here is UX only: the API
 * (policies + EnsurePermission + RequireFreshMfa + EnsureIdempotentRequest) is the security boundary.
 *
 * The browser NEVER computes an authoritative salary, commission, reversal, adjustment or net-liability
 * amount — it formats the server's integer minor units and renders the server `/summary` per-currency
 * totals verbatim. Currencies are never combined. A liability is NEVER a payout, settlement, disbursement,
 * Wallet movement, earnings statement or "paid" event. Finance may record a STANDALONE manual adjustment
 * (positive or negative), which is a financial mutation: fresh step-up + idempotency are server-enforced.
 */
const store = useCompensationLiabilityStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const router = useRouter();
const { can } = useCan();

const canView = computed(() => can('compensation.liability.view'));
const canAdjust = computed(() => can('compensation.adjustment.create'));

/* ------------------------------------------------------------------ a11y: status + focus */

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

/* ------------------------------------------------------------------ display helpers */

function money(minor: number, currency: string): string {
  return formatMoney(minor, currency);
}
/** A signed amount is never conveyed by colour alone — the −/+ glyph and a word carry the direction. */
function amountDirection(minor: number): string {
  if (minor < 0) return 'reduces liability';
  if (minor > 0) return 'increases liability';
  return 'no change';
}

/* ------------------------------------------------------------------ summary state */

const summaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.summaryLoading) return 'loading';
  if (store.summaryError) return 'error';
  if (store.summary.length === 0) return 'empty';
  return 'success';
});
const entriesState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.entriesLoading) return 'loading';
  if (store.entriesError) return 'error';
  if (store.entries.length === 0) return 'empty';
  return 'success';
});
const adjustmentsState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.adjustmentsLoading) return 'loading';
  if (store.adjustmentsError) return 'error';
  if (store.adjustments.length === 0) return 'empty';
  return 'success';
});

/* ------------------------------------------------------------------ load + context */

async function loadAll(): Promise<void> {
  await Promise.all([store.fetchSummary(), store.fetchEntries(1), store.fetchAdjustments(1)]);
}

onMounted(() => {
  if (canView.value) void loadAll();
});

// A branch/tenant context change invalidates every liability fact on screen — drop and reload so another
// context's data can never linger.
watch(
  () => auth.branchIds,
  () => {
    store.$reset();
    if (canView.value) void loadAll();
  },
);

/* ------------------------------------------------------------------ filters */

const filterKeys = ['liability_type', 'entry_type', 'status', 'currency', 'staff_profile_ulid', 'branch_ulid', 'date_from', 'date_to'] as const;
const activeFilterCount = computed(() =>
  filterKeys.reduce((n, k) => (store.filters[k] !== '' ? n + 1 : n), 0),
);

async function applyFilters(): Promise<void> {
  // A blank/short currency is never sent as a partial token; the request validates 3-letter uppercase.
  if (store.filters.currency !== '') store.filters.currency = store.filters.currency.toUpperCase();
  await store.applyFilters();
}
async function clearFilters(): Promise<void> {
  store.resetFilters();
  await store.applyFilters();
}

/* ------------------------------------------------------------------ pagination */

function entriesPage(page: number): void {
  if (page >= 1 && page <= store.entriesMeta.last_page) void store.fetchEntries(page);
}
function adjustmentsPage(page: number): void {
  if (page >= 1 && page <= store.adjustmentsMeta.last_page) void store.fetchAdjustments(page);
}

/* ------------------------------------------------------------------ entry detail */

const detailEntry = ref<LiabilityEntry | null>(null);
function openEntry(entry: LiabilityEntry): void {
  rememberFocus();
  detailEntry.value = entry;
}
function closeEntry(): void {
  detailEntry.value = null;
  restoreFocus();
}

/* ------------------------------------------------------------------ adjustment detail */

const detailAdjustment = ref<CompensationAdjustment | null>(null);
async function openAdjustment(adjustment: CompensationAdjustment): Promise<void> {
  rememberFocus();
  detailAdjustment.value = adjustment;
  try {
    detailAdjustment.value = await store.fetchAdjustment(adjustment.id);
  } catch {
    // Keep the list row's masked data if the detail fetch fails; never blank the dialog.
  }
}
function closeAdjustment(): void {
  detailAdjustment.value = null;
  restoreFocus();
}

/* ------------------------------------------------------------------ create adjustment */

const createOpen = ref(false);
const createSubmitting = computed(() => store.creating);
const stepUpRequired = ref(false);
const createError = ref<string | null>(null);
const createErrors = reactive<Record<string, string[]>>({});
const form = reactive({
  staff_profile_ulid: '',
  direction: 'increase',
  amount_major: '',
  currency: 'KES',
  reason: '',
});

const DIRECTION_OPTIONS = [
  { value: 'increase', label: 'Increase liability (positive)' },
  { value: 'decrease', label: 'Reduce liability (negative)' },
];

/** Parse a non-negative major-unit amount to integer minor units WITHOUT floating-point arithmetic. */
function majorToMinor(input: string): number | null {
  const trimmed = input.trim();
  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;
  const [whole, frac = ''] = trimmed.split('.');
  const cents = `${frac}00`.slice(0, 2);
  return Number(whole) * 100 + Number(cents);
}

/** The signed integer minor units that will be sent, or null when the amount is not yet valid. */
const signedMinor = computed<number | null>(() => {
  const magnitude = majorToMinor(form.amount_major);
  if (magnitude === null || magnitude === 0) return null;
  return form.direction === 'decrease' ? -magnitude : magnitude;
});

const previewText = computed<string | null>(() => {
  if (signedMinor.value === null) return null;
  const cur = form.currency.trim().toUpperCase();
  // Only format once the currency is a valid 3-letter code — Intl throws on a partial token.
  if (!/^[A-Z]{3}$/.test(cur)) return null;
  return `${money(signedMinor.value, cur)} — ${amountDirection(signedMinor.value)}`;
});

function openCreate(staffUlid?: string): void {
  rememberFocus();
  form.staff_profile_ulid = staffUlid ?? '';
  form.direction = 'increase';
  form.amount_major = '';
  form.currency = 'KES';
  form.reason = '';
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  createError.value = null;
  stepUpRequired.value = false;
  createOpen.value = true;
}
function closeCreate(): void {
  createOpen.value = false;
  restoreFocus();
}

/** Mirror the server rules so an obviously invalid draft is caught before the call (the API is final). */
function validateCreate(): boolean {
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  if (form.staff_profile_ulid.trim().length !== 26) {
    createErrors.staff_profile_ulid = ['Enter the 26-character staff reference this adjustment applies to.'];
  }
  if (signedMinor.value === null) {
    createErrors.amount_minor = ['Enter an amount greater than zero (a zero adjustment is not allowed).'];
  }
  if (!/^[A-Za-z]{3}$/.test(form.currency.trim())) {
    createErrors.currency = ['Enter a 3-letter currency code, for example KES.'];
  }
  if (form.reason.trim().length < 3) {
    createErrors.reason = ['A reason of at least 3 characters is required.'];
  }
  return Object.keys(createErrors).length === 0;
}

async function submitCreate(): Promise<void> {
  if (store.creating || !validateCreate() || signedMinor.value === null) return;
  createError.value = null;
  stepUpRequired.value = false;
  try {
    await store.createAdjustment({
      staff_profile_ulid: form.staff_profile_ulid.trim(),
      amount_minor: signedMinor.value,
      currency: form.currency.trim().toUpperCase(),
      reason: form.reason.trim(),
    });
    createOpen.value = false;
    restoreFocus();
    await announce('Compensation adjustment recorded.');
    notifications.addToast({ type: 'success', message: 'Compensation adjustment recorded.' });
    // The adjustment changed the liability totals — refresh the server-authoritative reads.
    await Promise.all([store.fetchSummary(), store.fetchAdjustments(1)]);
  } catch (err) {
    mapCreateError(err);
  }
}

/** Map the safe server envelope to screen copy — never SQLSTATE, constraint, class or internal ids. */
function mapCreateError(err: unknown): void {
  if (!axios.isAxiosError(err) || !err.apiError) {
    createError.value = 'Something went wrong. Please try again.';
    return;
  }
  const { code, message, fields } = err.apiError;
  switch (code) {
    case 'step_up_required':
    case 'privileged_mfa_required':
      stepUpRequired.value = true;
      createError.value = null;
      return;
    case 'financial_period_locked':
    case 'period_locked':
      createError.value = 'The financial period is locked. A controlled reopen is required before recording an adjustment for it.';
      return;
    case 'idempotency_conflict':
      createError.value = 'This adjustment is already being processed. Reload before trying again to avoid a duplicate.';
      return;
    case 'validation_failed':
      Object.assign(createErrors, fields);
      createError.value = Object.keys(fields).length > 0 ? null : (message ?? 'Please correct the highlighted fields.');
      return;
    default:
      createError.value = message ?? 'The adjustment could not be recorded.';
  }
}

/** Route to the established per-session step-up challenge; the form stays open for retry on return. */
async function verifyStepUp(): Promise<void> {
  await router.push({ name: 'auth.mfa.challenge' });
}
</script>

<template>
  <section class="mx-auto max-w-5xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Compensation liabilities
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      Salary accrued and commission earned that your business owes staff, plus any manual adjustments.
      Amounts are shown exactly as Servana calculated them, grouped by currency. This is what is owed — it is
      not a payout, a settlement, or a record that anyone has been paid.
    </p>

    <!-- Async result announcement; focus lands here after a successful adjustment. -->
    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="liability-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <!-- Forbidden: the backend refused the read. Never a blank screen. -->
    <div
      v-if="store.forbidden"
      data-testid="liability-forbidden"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to compensation liabilities.
        </p>
      </SvCard>
    </div>
    <div
      v-else-if="!canView"
      data-testid="liability-no-permission"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to compensation liabilities.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <!-- Actions -->
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <SvButton
          v-if="canAdjust"
          data-testid="open-adjustment"
          @click="openCreate()"
        >
          Record adjustment
        </SvButton>
      </div>

      <!-- ------------------------------------------------------------------ summary -->
      <section
        aria-labelledby="liability-summary-heading"
        class="mt-6"
      >
        <h2
          id="liability-summary-heading"
          class="text-sm font-semibold text-text"
        >
          Liability summary by currency
        </h2>
        <SvStateBoundary
          class="mt-2"
          :state="summaryState"
          :error-message="store.summaryError ?? undefined"
          empty-message="No compensation liabilities recorded yet."
          @retry="store.fetchSummary()"
        >
          <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <SvCard
              v-for="row in store.summary"
              :key="row.currency"
              padding="sm"
              data-testid="summary-card"
            >
              <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
                {{ row.currency }}
              </p>
              <p class="mt-1 text-lg font-bold text-text">
                {{ money(row.combined_net_liability_minor, row.currency) }}
                <span class="block text-xs font-normal text-text-muted">Combined net liability</span>
              </p>
              <dl class="mt-2 space-y-1 text-xs text-text-muted">
                <div class="flex justify-between gap-2">
                  <dt>Net salary</dt>
                  <dd class="text-text">
                    {{ money(row.net_salary_liability_minor, row.currency) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Net commission</dt>
                  <dd class="text-text">
                    {{ money(row.net_commission_liability_minor, row.currency) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Adjustments</dt>
                  <dd class="text-text">
                    {{ money(row.compensation_adjustment_minor, row.currency) }}
                  </dd>
                </div>
                <div class="mt-1 flex justify-between gap-2 border-t border-border pt-1">
                  <dt>Gross salary accrual</dt>
                  <dd>{{ money(row.gross_salary_accrual_minor, row.currency) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Salary reversals</dt>
                  <dd>{{ money(row.salary_reversal_minor, row.currency) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Gross earned commission</dt>
                  <dd>{{ money(row.gross_earned_commission_minor, row.currency) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Commission reversals</dt>
                  <dd>{{ money(row.commission_reversal_minor, row.currency) }}</dd>
                </div>
              </dl>
            </SvCard>
          </div>
        </SvStateBoundary>
      </section>

      <!-- ------------------------------------------------------------------ filters -->
      <section
        aria-labelledby="liability-filters-heading"
        class="mt-8"
      >
        <h2
          id="liability-filters-heading"
          class="text-sm font-semibold text-text"
        >
          Filters
          <span
            v-if="activeFilterCount > 0"
            class="ml-1 rounded-control bg-surface-alt px-2 py-0.5 text-xs font-medium text-text-muted"
            data-testid="active-filter-count"
          >{{ activeFilterCount }} active</span>
        </h2>
        <form
          class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
          novalidate
          @submit.prevent="applyFilters"
        >
          <SvSelect
            id="filter-liability-type"
            label="Liability type"
            :model-value="store.filters.liability_type"
            :options="LIABILITY_TYPE_FILTER"
            @update:model-value="store.filters.liability_type = $event"
          />
          <SvSelect
            id="filter-entry-type"
            label="Entry type"
            :model-value="store.filters.entry_type"
            :options="ENTRY_TYPE_FILTER"
            @update:model-value="store.filters.entry_type = $event"
          />
          <SvSelect
            id="filter-status"
            label="Status"
            :model-value="store.filters.status"
            :options="LEDGER_STATUS_FILTER"
            @update:model-value="store.filters.status = $event"
          />
          <SvTextInput
            id="filter-currency"
            label="Currency"
            help="3-letter code, e.g. KES"
            :model-value="store.filters.currency"
            @update:model-value="store.filters.currency = $event"
          />
          <SvTextInput
            id="filter-staff"
            label="Staff reference"
            help="26-character staff reference"
            :model-value="store.filters.staff_profile_ulid"
            @update:model-value="store.filters.staff_profile_ulid = $event"
          />
          <SvTextInput
            id="filter-branch"
            label="Branch reference"
            help="26-character branch reference"
            :model-value="store.filters.branch_ulid"
            @update:model-value="store.filters.branch_ulid = $event"
          />
          <SvTextInput
            id="filter-date-from"
            label="Date from"
            type="date"
            :model-value="store.filters.date_from"
            @update:model-value="store.filters.date_from = $event"
          />
          <SvTextInput
            id="filter-date-to"
            label="Date to"
            type="date"
            :model-value="store.filters.date_to"
            @update:model-value="store.filters.date_to = $event"
          />
          <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
            <SvButton
              type="submit"
              data-testid="apply-filters"
            >
              Apply filters
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
      </section>

      <!-- ------------------------------------------------------------------ entries -->
      <section
        aria-labelledby="liability-entries-heading"
        class="mt-8"
      >
        <h2
          id="liability-entries-heading"
          class="text-sm font-semibold text-text"
        >
          Liability entries
        </h2>
        <SvStateBoundary
          class="mt-2"
          :state="entriesState"
          :error-message="store.entriesError ?? undefined"
          empty-message="No liability entries match these filters."
          @retry="store.fetchEntries()"
        >
          <ul class="mt-2 flex flex-col gap-3">
            <li
              v-for="entry in store.entries"
              :key="entry.id"
              data-testid="liability-entry-row"
            >
              <SvCard padding="sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-semibold text-text">
                      {{ money(entry.amount_minor, entry.currency) }}
                      <span class="ml-1 text-xs font-normal text-text-muted">({{ amountDirection(entry.amount_minor) }})</span>
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                      <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">
                        {{ liabilityTypeLabel(entry.liability_type) }}
                      </span>
                      <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">
                        {{ entryTypeLabel(entry.entry_type) }}
                      </span>
                      <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">
                        {{ ledgerStatusLabel(entry.status) }}
                      </span>
                    </p>
                    <p class="mt-1 break-words text-xs text-text-muted">
                      {{ entry.staff_display_name ?? 'Staff member' }}
                      <span v-if="entry.business_date"> · {{ entry.business_date }}</span>
                      <span v-if="entry.invoice_reference"> · invoice {{ entry.invoice_reference }}</span>
                    </p>
                  </div>
                  <SvButton
                    variant="secondary"
                    :data-testid="`entry-details-${entry.id}`"
                    @click="openEntry(entry)"
                  >
                    Details
                  </SvButton>
                </div>
              </SvCard>
            </li>
          </ul>

          <nav
            v-if="store.entriesMeta.last_page > 1"
            class="mt-4 flex items-center justify-between"
            aria-label="Liability entries pagination"
          >
            <SvButton
              variant="secondary"
              :disabled="store.entriesMeta.current_page <= 1"
              @click="entriesPage(store.entriesMeta.current_page - 1)"
            >
              Previous
            </SvButton>
            <span class="text-sm text-text-muted">
              Page {{ store.entriesMeta.current_page }} of {{ store.entriesMeta.last_page }}
            </span>
            <SvButton
              variant="secondary"
              :disabled="store.entriesMeta.current_page >= store.entriesMeta.last_page"
              @click="entriesPage(store.entriesMeta.current_page + 1)"
            >
              Next
            </SvButton>
          </nav>
        </SvStateBoundary>
      </section>

      <!-- ------------------------------------------------------------------ adjustments -->
      <section
        aria-labelledby="liability-adjustments-heading"
        class="mt-8"
      >
        <h2
          id="liability-adjustments-heading"
          class="text-sm font-semibold text-text"
        >
          Compensation adjustments
        </h2>
        <SvStateBoundary
          class="mt-2"
          :state="adjustmentsState"
          :error-message="store.adjustmentsError ?? undefined"
          empty-message="No compensation adjustments recorded yet."
          @retry="store.fetchAdjustments()"
        >
          <ul class="mt-2 flex flex-col gap-3">
            <li
              v-for="adjustment in store.adjustments"
              :key="adjustment.id"
              data-testid="adjustment-row"
            >
              <SvCard padding="sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-semibold text-text">
                      {{ money(adjustment.amount_minor, adjustment.currency) }}
                      <span class="ml-1 text-xs font-normal text-text-muted">({{ amountDirection(adjustment.amount_minor) }})</span>
                    </p>
                    <p class="mt-1 text-xs">
                      <span class="rounded-control bg-surface-alt px-2 py-0.5 text-text-muted">
                        {{ adjustmentTypeLabel(adjustment.adjustment_type) }}
                      </span>
                    </p>
                    <p class="mt-1 break-words text-xs text-text-muted">
                      {{ adjustment.staff_display_name ?? 'Staff member' }}
                      <span v-if="adjustment.created_at"> · {{ adjustment.created_at }}</span>
                    </p>
                    <p class="mt-1 break-words text-sm text-text">
                      {{ adjustment.reason }}
                    </p>
                  </div>
                  <SvButton
                    variant="secondary"
                    :data-testid="`adjustment-details-${adjustment.id}`"
                    @click="openAdjustment(adjustment)"
                  >
                    Details
                  </SvButton>
                </div>
              </SvCard>
            </li>
          </ul>

          <nav
            v-if="store.adjustmentsMeta.last_page > 1"
            class="mt-4 flex items-center justify-between"
            aria-label="Adjustments pagination"
          >
            <SvButton
              variant="secondary"
              :disabled="store.adjustmentsMeta.current_page <= 1"
              @click="adjustmentsPage(store.adjustmentsMeta.current_page - 1)"
            >
              Previous
            </SvButton>
            <span class="text-sm text-text-muted">
              Page {{ store.adjustmentsMeta.current_page }} of {{ store.adjustmentsMeta.last_page }}
            </span>
            <SvButton
              variant="secondary"
              :disabled="store.adjustmentsMeta.current_page >= store.adjustmentsMeta.last_page"
              @click="adjustmentsPage(store.adjustmentsMeta.current_page + 1)"
            >
              Next
            </SvButton>
          </nav>
        </SvStateBoundary>
      </section>
    </template>

    <!-- ------------------------------------------------------------------ entry detail modal -->
    <SvDialog
      :open="detailEntry !== null"
      title="Liability entry"
      description="A single server-authoritative salary or commission ledger fact. Amounts are exact integer minor units."
      @close="closeEntry"
    >
      <dl
        v-if="detailEntry"
        class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
      >
        <dt class="text-text-muted">
          Amount
        </dt>
        <dd class="text-text">
          {{ money(detailEntry.amount_minor, detailEntry.currency) }} ({{ amountDirection(detailEntry.amount_minor) }})
        </dd>
        <dt class="text-text-muted">
          Liability type
        </dt>
        <dd class="text-text">
          {{ liabilityTypeLabel(detailEntry.liability_type) }}
        </dd>
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
          Staff member
        </dt>
        <dd class="text-text">
          {{ detailEntry.staff_display_name ?? '—' }}
        </dd>
        <template v-if="detailEntry.business_date">
          <dt class="text-text-muted">
            Business date
          </dt>
          <dd class="text-text">
            {{ detailEntry.business_date }}
          </dd>
        </template>
        <template v-if="detailEntry.pay_period_start">
          <dt class="text-text-muted">
            Pay period
          </dt>
          <dd class="text-text">
            {{ detailEntry.pay_period_start }} → {{ detailEntry.pay_period_end }}
          </dd>
        </template>
        <template v-if="detailEntry.invoice_reference">
          <dt class="text-text-muted">
            Invoice
          </dt>
          <dd class="break-all text-text">
            {{ detailEntry.invoice_reference }}
          </dd>
        </template>
        <template v-if="detailEntry.source_entry_id">
          <dt class="text-text-muted">
            Reverses entry
          </dt>
          <dd class="break-all text-text">
            {{ detailEntry.source_entry_id }}
          </dd>
        </template>
        <dt class="text-text-muted">
          Reference
        </dt>
        <dd class="break-all text-text">
          {{ detailEntry.id }}
        </dd>
      </dl>
      <div class="mt-4 flex justify-end">
        <SvButton
          variant="secondary"
          @click="closeEntry"
        >
          Close
        </SvButton>
      </div>
    </SvDialog>

    <!-- ------------------------------------------------------------------ adjustment detail modal -->
    <SvDialog
      :open="detailAdjustment !== null"
      title="Compensation adjustment"
      description="An additive, append-only adjustment to a staff member's compensation liability."
      @close="closeAdjustment"
    >
      <dl
        v-if="detailAdjustment"
        class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
      >
        <dt class="text-text-muted">
          Amount
        </dt>
        <dd class="text-text">
          {{ money(detailAdjustment.amount_minor, detailAdjustment.currency) }} ({{ amountDirection(detailAdjustment.amount_minor) }})
        </dd>
        <dt class="text-text-muted">
          Type
        </dt>
        <dd class="text-text">
          {{ adjustmentTypeLabel(detailAdjustment.adjustment_type) }}
        </dd>
        <dt class="text-text-muted">
          Staff member
        </dt>
        <dd class="text-text">
          {{ detailAdjustment.staff_display_name ?? '—' }}
        </dd>
        <dt class="text-text-muted">
          Reason
        </dt>
        <dd class="break-words text-text">
          {{ detailAdjustment.reason }}
        </dd>
        <dt class="text-text-muted">
          Reference
        </dt>
        <dd class="break-all text-text">
          {{ detailAdjustment.id }}
        </dd>
      </dl>
      <div class="mt-4 flex justify-end">
        <SvButton
          variant="secondary"
          @click="closeAdjustment"
        >
          Close
        </SvButton>
      </div>
    </SvDialog>

    <!-- ------------------------------------------------------------------ create adjustment modal -->
    <SvDialog
      :open="createOpen"
      title="Record a compensation adjustment"
      description="An additive, append-only correction to a staff member's compensation liability. A positive amount increases the liability; a negative amount reduces it. This never rewrites an accrued or earned fact. Recording requires a fresh step-up."
      @close="closeCreate"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCreate"
      >
        <!-- Step-up-required safe state -->
        <div
          v-if="stepUpRequired"
          data-testid="adjustment-step-up"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            A fresh identity check is required
          </p>
          <p class="mt-1">
            Recording a compensation adjustment needs a recent step-up verification. Verify your identity,
            then record the adjustment again. Your entries below are kept.
          </p>
          <div class="mt-2">
            <SvButton
              type="button"
              variant="secondary"
              data-testid="adjustment-verify"
              @click="verifyStepUp"
            >
              Verify identity
            </SvButton>
          </div>
        </div>

        <p
          v-if="createError"
          data-testid="adjustment-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ createError }}
        </p>

        <SvTextInput
          id="adjustment-staff"
          label="Staff reference"
          help="The 26-character staff reference this adjustment applies to. The branch is derived from the staff member."
          :model-value="form.staff_profile_ulid"
          :errors="createErrors.staff_profile_ulid"
          required
          @update:model-value="form.staff_profile_ulid = $event"
        />

        <div class="grid gap-4 sm:grid-cols-2">
          <SvSelect
            id="adjustment-direction"
            label="Direction"
            :model-value="form.direction"
            :options="DIRECTION_OPTIONS"
            required
            @update:model-value="form.direction = $event"
          />
          <SvTextInput
            id="adjustment-amount"
            label="Amount (major units)"
            type="number"
            help="A positive number; the direction sets the sign."
            :model-value="form.amount_major"
            :errors="createErrors.amount_minor"
            required
            @update:model-value="form.amount_major = $event"
          />
        </div>

        <SvTextInput
          id="adjustment-currency"
          label="Currency"
          :model-value="form.currency"
          :errors="createErrors.currency"
          required
          @update:model-value="form.currency = $event"
        />

        <SvTextArea
          id="adjustment-reason"
          label="Reason"
          :model-value="form.reason"
          :errors="createErrors.reason"
          required
          @update:model-value="form.reason = $event"
        />

        <!-- Live confirmation preview (§14.2): the signed amount and its effect, before submission. -->
        <p
          v-if="previewText"
          data-testid="adjustment-preview"
          class="rounded-control bg-surface-alt px-3 py-2 text-sm text-text"
          role="status"
        >
          This adjustment will record <span class="font-semibold">{{ previewText }}</span> for the selected
          staff member. A negative adjustment reduces liability; it is not a payment.
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            type="button"
            variant="secondary"
            @click="closeCreate"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            data-testid="adjustment-submit"
            :loading="createSubmitting"
          >
            Record adjustment
          </SvButton>
        </div>
      </form>
    </SvDialog>
  </section>
</template>
