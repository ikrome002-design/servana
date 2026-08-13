<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePayoutRunStore, type PayoutRun } from '@/stores/payoutRunStore';
import { formatMoney } from '@/utils/money';
import { PAYOUT_RUN_STATUS_FILTER, payoutRunStatusLabel } from '@/content/payout';

/**
 * HR Payout Runs (Plan §62, §10.2, §19.3; Phase 20H). Branch-scoped: HR prepares payout DRAFTS —
 * list/create/edit/submit(freeze)/cancel — and NEVER verifies, approves, or marks paid. Every control is
 * UX only; the API (EnsureBranchScope + EnsurePermission + policy + state machine) is authoritative. The
 * eligible items are SNAPSHOTTED by the server from the compensation ledgers — the browser never computes
 * a total or an item. Servana moves no money.
 */
const store = usePayoutRunStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canCreate = computed(() => can('payout_run.create'));
const canUpdate = computed(() => can('payout_run.update_draft'));
const canSubmit = computed(() => can('payout_run.submit'));
const canCancel = computed(() => can('payout_run.cancel_draft'));

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

async function load(): Promise<void> {
  await store.fetchRuns('hr', 1);
}

onMounted(() => {
  if (canCreate.value) void load();
});

watch(
  () => auth.branchIds,
  () => {
    store.$reset();
    if (canCreate.value) void load();
  },
);

/* ---------------------------------------------------------------- filters */
async function applyFilters(): Promise<void> {
  await store.applyFilters('hr');
}
async function clearFilters(): Promise<void> {
  store.resetFilters();
  await store.applyFilters('hr');
}
function goPage(page: number): void {
  if (page >= 1 && page <= store.meta.last_page) void store.fetchRuns('hr', page);
}

/* ---------------------------------------------------------------- create draft */
const createOpen = ref(false);
const createError = ref<string | null>(null);
const createErrors = reactive<Record<string, string[]>>({});
const createForm = reactive({ branch_ulid: '', period_start: '', period_end: '', currency: 'KES' });

function openCreate(): void {
  rememberFocus();
  createForm.branch_ulid = auth.branchIds[0] ?? '';
  createForm.period_start = '';
  createForm.period_end = '';
  createForm.currency = 'KES';
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  createError.value = null;
  createOpen.value = true;
}
function closeCreate(): void {
  createOpen.value = false;
  restoreFocus();
}
function validateCreate(): boolean {
  Object.keys(createErrors).forEach((k) => delete createErrors[k]);
  if (createForm.branch_ulid.trim().length !== 26) createErrors.branch_ulid = ['Enter the 26-character branch reference.'];
  if (createForm.period_start === '') createErrors.period_start = ['Choose a period start date.'];
  if (createForm.period_end === '') createErrors.period_end = ['Choose a period end date.'];
  else if (createForm.period_start !== '' && createForm.period_end < createForm.period_start) createErrors.period_end = ['The period end must be on or after the start.'];
  if (!/^[A-Za-z]{3}$/.test(createForm.currency.trim())) createErrors.currency = ['Enter a 3-letter currency code, for example KES.'];
  return Object.keys(createErrors).length === 0;
}
async function submitCreate(): Promise<void> {
  if (store.mutating || !validateCreate()) return;
  createError.value = null;
  try {
    const run = await store.createDraft({
      branch_ulid: createForm.branch_ulid.trim(),
      period_start: createForm.period_start,
      period_end: createForm.period_end,
      currency: createForm.currency.trim().toUpperCase(),
    });
    createOpen.value = false;
    restoreFocus();
    await announce('Draft payout run created. Servana snapshotted the eligible items.');
    notifications.addToast({ type: 'success', message: 'Draft payout run created.' });
    await store.fetchRuns('hr', 1);
    await openDetail(run);
  } catch (err) {
    mapMutationError(err, (msg) => { createError.value = msg; }, createErrors);
  }
}

/* ---------------------------------------------------------------- detail + transitions */
const detailOpen = ref(false);
const actionError = ref<string | null>(null);
const editing = ref(false);
const editForm = reactive({ period_start: '', period_end: '', currency: 'KES' });
const editErrors = reactive<Record<string, string[]>>({});

const detailRun = computed<PayoutRun | null>(() => store.currentRun);
const isDraft = computed(() => detailRun.value?.status === 'draft');

async function openDetail(run: PayoutRun): Promise<void> {
  rememberFocus();
  actionError.value = null;
  editing.value = false;
  detailOpen.value = true;
  await store.fetchRun('hr', run.id);
}
function closeDetail(): void {
  detailOpen.value = false;
  editing.value = false;
  restoreFocus();
}

function startEdit(): void {
  const run = detailRun.value;
  if (!run) return;
  editForm.period_start = run.period_start;
  editForm.period_end = run.period_end;
  editForm.currency = run.currency;
  Object.keys(editErrors).forEach((k) => delete editErrors[k]);
  actionError.value = null;
  editing.value = true;
}
async function saveEdit(): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating) return;
  Object.keys(editErrors).forEach((k) => delete editErrors[k]);
  if (editForm.period_end !== '' && editForm.period_start !== '' && editForm.period_end < editForm.period_start) {
    editErrors.period_end = ['The period end must be on or after the start.'];
    return;
  }
  try {
    await store.updateDraft(run.id, { period_start: editForm.period_start, period_end: editForm.period_end, currency: editForm.currency.trim().toUpperCase() });
    editing.value = false;
    await announce('Draft updated and items re-snapshotted.');
  } catch (err) {
    mapMutationError(err, (msg) => { actionError.value = msg; }, editErrors);
  }
}

async function doSubmit(): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating) return;
  actionError.value = null;
  try {
    await store.submitDraft(run.id);
    await announce('Payout run submitted and frozen. It now goes to Finance for verification.');
    notifications.addToast({ type: 'success', message: 'Payout run submitted.' });
    await store.fetchRuns('hr', store.meta.current_page);
  } catch (err) {
    mapMutationError(err, (msg) => { actionError.value = msg; });
  }
}
async function doCancel(): Promise<void> {
  const run = detailRun.value;
  if (!run || store.mutating) return;
  actionError.value = null;
  try {
    await store.cancelDraft(run.id);
    await announce('Draft payout run cancelled.');
    notifications.addToast({ type: 'success', message: 'Draft cancelled.' });
    await store.fetchRuns('hr', store.meta.current_page);
  } catch (err) {
    mapMutationError(err, (msg) => { actionError.value = msg; });
  }
}

/** Map the safe server envelope to screen copy — never SQLSTATE, constraint, class or internal ids. */
function mapMutationError(err: unknown, setError: (msg: string) => void, fieldErrors?: Record<string, string[]>): void {
  if (!axios.isAxiosError(err) || !err.apiError) {
    setError('Something went wrong. Please try again.');
    return;
  }
  const { code, message, fields } = err.apiError;
  switch (code) {
    case 'invalid_state_transition':
      setError('This payout run can no longer be changed in its current state. Reload to see the latest status.');
      return;
    case 'billing_read_only':
    case 'financial_period_locked':
    case 'period_locked':
      setError('This action is not available while billing is read-only or the period is locked.');
      return;
    case 'validation_failed':
      if (fieldErrors && Object.keys(fields).length > 0) { Object.assign(fieldErrors, fields); setError(''); return; }
      setError(message ?? 'Please correct the highlighted fields.');
      return;
    default:
      setError(message ?? 'The action could not be completed.');
  }
}
</script>

<template>
  <section
    class="mx-auto max-w-5xl"
    data-testid="hr-payouts"
  >
    <SvPageHeader
      title="Payout run preparation"
      eyebrow="Compensation"
      description="Prepare and submit server-snapshotted branch payout runs to Finance. Human Resource never verifies, approves, marks paid or moves money."
    />

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
      v-if="store.forbidden || !canCreate"
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
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <SvButton
          data-testid="open-create"
          @click="openCreate()"
        >
          New payout run
        </SvButton>
      </div>

      <!-- filters -->
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
        <SvTextInput
          id="filter-currency"
          label="Currency"
          help="3-letter code, e.g. KES"
          :model-value="store.filters.currency"
          @update:model-value="store.filters.currency = $event"
        />
        <SvTextInput
          id="filter-branch"
          label="Branch reference"
          help="26-character branch reference"
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

      <!-- list -->
      <SvStateBoundary
        class="mt-6"
        :state="listState"
        :error-message="store.listError ?? undefined"
        empty-message="No payout runs match these filters yet."
        @retry="store.fetchRuns('hr')"
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

    <!-- create modal -->
    <SvDialog
      :open="createOpen"
      title="New payout run"
      description="Choose a branch, pay period and currency. Servana snapshots the eligible earned amounts — you do not enter any totals. A run is single-currency."
      @close="closeCreate"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCreate"
      >
        <p
          v-if="createError"
          data-testid="create-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ createError }}
        </p>
        <SvTextInput
          id="create-branch"
          label="Branch reference"
          help="The 26-character reference of the branch this run is for."
          :model-value="createForm.branch_ulid"
          :errors="createErrors.branch_ulid"
          required
          @update:model-value="createForm.branch_ulid = $event"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <SvTextInput
            id="create-start"
            label="Period start"
            type="date"
            :model-value="createForm.period_start"
            :errors="createErrors.period_start"
            required
            @update:model-value="createForm.period_start = $event"
          />
          <SvTextInput
            id="create-end"
            label="Period end"
            type="date"
            :model-value="createForm.period_end"
            :errors="createErrors.period_end"
            required
            @update:model-value="createForm.period_end = $event"
          />
        </div>
        <SvTextInput
          id="create-currency"
          label="Currency"
          :model-value="createForm.currency"
          :errors="createErrors.currency"
          required
          @update:model-value="createForm.currency = $event"
        />
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
            data-testid="create-submit"
            :loading="store.mutating"
          >
            Create draft
          </SvButton>
        </div>
      </form>
    </SvDialog>

    <!-- detail modal -->
    <SvDialog
      :open="detailOpen"
      title="Payout run"
      description="A server-snapshotted payout run. Amounts are exact integer minor units that Servana calculated; you never edit them."
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
          <template v-if="detailRun.rejection_reason">
            <dt class="text-text-muted">
              Rejection reason
            </dt>
            <dd class="break-words text-text">
              {{ detailRun.rejection_reason }}
            </dd>
          </template>
          <dt class="text-text-muted">
            Reference
          </dt>
          <dd class="break-all text-text">
            {{ detailRun.id }}
          </dd>
        </dl>

        <!-- inline edit (draft only) -->
        <div
          v-if="editing"
          class="rounded-control border border-border p-3"
        >
          <p class="text-sm font-semibold text-text">
            Edit draft period
          </p>
          <div class="mt-2 grid gap-3 sm:grid-cols-3">
            <SvTextInput
              id="edit-start"
              label="Period start"
              type="date"
              :model-value="editForm.period_start"
              :errors="editErrors.period_start"
              @update:model-value="editForm.period_start = $event"
            />
            <SvTextInput
              id="edit-end"
              label="Period end"
              type="date"
              :model-value="editForm.period_end"
              :errors="editErrors.period_end"
              @update:model-value="editForm.period_end = $event"
            />
            <SvTextInput
              id="edit-currency"
              label="Currency"
              :model-value="editForm.currency"
              :errors="editErrors.currency"
              @update:model-value="editForm.currency = $event"
            />
          </div>
          <div class="mt-2 flex justify-end gap-2">
            <SvButton
              type="button"
              variant="secondary"
              @click="editing = false"
            >
              Cancel
            </SvButton>
            <SvButton
              type="button"
              data-testid="save-edit"
              :loading="store.mutating"
              @click="saveEdit"
            >
              Save and re-snapshot
            </SvButton>
          </div>
        </div>

        <!-- items -->
        <section aria-labelledby="items-heading">
          <h3
            id="items-heading"
            class="text-sm font-semibold text-text"
          >
            Snapshotted items
          </h3>
          <ul class="mt-2 flex flex-col gap-2">
            <li
              v-for="item in detailRun.items ?? []"
              :key="item.id"
              data-testid="payout-item-row"
            >
              <SvCard padding="sm">
                <p class="font-semibold text-text">
                  {{ item.staff_display_name ?? 'Staff member' }} — {{ money(item.gross_amount_minor, item.currency) }}
                </p>
                <dl class="mt-1 grid grid-cols-3 gap-2 text-xs text-text-muted">
                  <div>
                    <dt>Salary</dt>
                    <dd class="text-text">
                      {{ money(item.salary_amount_minor, item.currency) }}
                    </dd>
                  </div>
                  <div>
                    <dt>Commission</dt>
                    <dd class="text-text">
                      {{ money(item.commission_amount_minor, item.currency) }}
                    </dd>
                  </div>
                  <div>
                    <dt>Adjustments</dt>
                    <dd class="text-text">
                      {{ money(item.adjustment_amount_minor, item.currency) }}
                    </dd>
                  </div>
                </dl>
              </SvCard>
            </li>
            <li
              v-if="(detailRun.items ?? []).length === 0"
              class="text-sm text-text-muted"
            >
              No eligible items were snapshotted for this period.
            </li>
          </ul>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
          <SvButton
            v-if="isDraft && canUpdate && !editing"
            type="button"
            variant="secondary"
            data-testid="edit-draft"
            @click="startEdit"
          >
            Edit period
          </SvButton>
          <SvButton
            v-if="isDraft && canCancel"
            type="button"
            variant="secondary"
            data-testid="cancel-draft"
            :loading="store.mutating"
            @click="doCancel"
          >
            Cancel draft
          </SvButton>
          <SvButton
            v-if="isDraft && canSubmit"
            type="button"
            data-testid="submit-draft"
            :loading="store.mutating"
            @click="doSubmit"
          >
            Submit to Finance
          </SvButton>
          <SvButton
            variant="secondary"
            @click="closeDetail"
          >
            Close
          </SvButton>
        </div>
      </div>
    </SvDialog>
  </section>
</template>
