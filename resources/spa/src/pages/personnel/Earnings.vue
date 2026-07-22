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
import { usePersonnelEarningsStore, type SignedDownload } from '@/stores/personnelEarningsStore';
import { useEarningsQueryStore, type EarningsQuery } from '@/stores/earningsQueryStore';
import { formatMoney } from '@/utils/money';
import {
  EARNINGS_QUERY_SUBJECT_OPTIONS,
  EARNINGS_QUERY_TYPE_OPTIONS,
  earningsQueryStatusLabel,
  earningsQuerySubjectLabel,
  earningsQueryTypeLabel,
} from '@/content/payout';

/**
 * My Earnings — Personnel own-scope (Plan §63, §10.2, §19.3; Phase 20H; §H10–H12). Everything here is the
 * ACTING personnel's own data, derived server-side from their membership — there is no staff selector and
 * the browser never sends a staff reference. Money is server-authoritative integer minor units, grouped
 * by currency and never combined. Statements are generated on demand for a paid payout item and
 * downloaded through Servana's authorised file link (own-scope). Personnel can raise an earnings query
 * about one of their own facts; Finance responds. No other staff data, no payout controls, no money
 * movement.
 */
const earnings = usePersonnelEarningsStore();
const queries = useEarningsQueryStore();
const auth = useAuthStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canView = computed(() => can('personnel.my_earnings.view'));
const canViewTerms = computed(() => can('personnel.my_compensation.view'));
const canViewPayouts = computed(() => can('personnel.my_payouts.view'));
const canDownload = computed(() => can('personnel.my_statements.download'));
const canQuery = computed(() => can('personnel.my_earnings_query.create'));

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
const overviewState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (earnings.overviewLoading) return 'loading';
  if (earnings.overviewError) return 'error';
  if (earnings.overview.currencies.length === 0) return 'empty';
  return 'success';
});
const payoutsState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (earnings.payoutsLoading) return 'loading';
  if (earnings.payoutsError) return 'error';
  if (earnings.payouts.length === 0) return 'empty';
  return 'success';
});
const queriesState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (queries.listLoading) return 'loading';
  if (queries.listError) return 'error';
  if (queries.queries.length === 0) return 'empty';
  return 'success';
});

const showSalary = computed(() => earnings.overview.tab_visibility.salary_tab);
const showCommission = computed(() => earnings.overview.tab_visibility.commission_tab);

async function loadAll(): Promise<void> {
  const jobs: Array<Promise<unknown>> = [earnings.fetchOverview()];
  if (canViewTerms.value) jobs.push(earnings.fetchTerms());
  if (canViewPayouts.value) jobs.push(earnings.fetchPayouts(1));
  if (canQuery.value) jobs.push(queries.fetchQueries('personnel', 1));
  await Promise.all(jobs);
}
onMounted(() => {
  if (canView.value) void loadAll();
});
watch(
  () => auth.branchIds,
  () => {
    earnings.$reset();
    queries.$reset();
    if (canView.value) void loadAll();
  },
);
function payoutsPage(page: number): void {
  if (page >= 1 && page <= earnings.payoutsMeta.last_page) void earnings.fetchPayouts(page);
}

/* ---------------------------------------------------------------- statement download */
const lastStatement = ref<{ itemId: string; download: SignedDownload; filename: string } | null>(null);
const statementError = ref<string | null>(null);

async function downloadStatement(itemId: string): Promise<void> {
  if (earnings.generating) return;
  statementError.value = null;
  try {
    const result = await earnings.generateStatement(itemId);
    lastStatement.value = { itemId, download: result.download, filename: result.statement.filename };
    await announce('Your earnings statement is ready to download.');
    // Open the authorised, short-lived signed link. The browser never sees a storage path.
    if (typeof window !== 'undefined') window.open(result.download.url, '_blank', 'noopener');
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message } = err.apiError;
      if (code === 'billing_read_only') { statementError.value = 'Statements cannot be generated while billing is read-only. An existing statement can still be downloaded.'; return; }
      if (code === 'invalid_state_transition') { statementError.value = 'A statement is only available once this payout has been paid.'; return; }
      statementError.value = message ?? 'The statement could not be generated.';
      return;
    }
    statementError.value = 'Something went wrong. Please try again.';
  }
}

/* ---------------------------------------------------------------- earnings query create */
const createOpen = ref(false);
const createError = ref<string | null>(null);
const createErrors = reactive<Record<string, string[]>>({});
const createForm = reactive({ subject_type: 'commission_ledger', subject_ulid: '', query_type: 'commission_disagreement', body: '' });

function openCreate(subjectUlid?: string, subjectType?: string): void {
  rememberFocus();
  createForm.subject_type = subjectType ?? 'commission_ledger';
  createForm.subject_ulid = subjectUlid ?? '';
  createForm.query_type = 'commission_disagreement';
  createForm.body = '';
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
  if (createForm.subject_ulid.trim().length !== 26) createErrors.subject_ulid = ['Enter the 26-character reference of your own commission, salary or payout item.'];
  if (createForm.body.trim().length < 3) createErrors.body = ['Describe the issue in at least 3 characters.'];
  return Object.keys(createErrors).length === 0;
}
async function submitCreate(): Promise<void> {
  if (queries.mutating || !validateCreate()) return;
  createError.value = null;
  try {
    await queries.createQuery({
      subject_type: createForm.subject_type,
      subject_ulid: createForm.subject_ulid.trim(),
      query_type: createForm.query_type,
      body: createForm.body.trim(),
    });
    createOpen.value = false;
    restoreFocus();
    await announce('Your earnings query was submitted. Finance will respond.');
    notifications.addToast({ type: 'success', message: 'Earnings query submitted.' });
    await queries.fetchQueries('personnel', 1);
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message, fields } = err.apiError;
      if (code === 'not_found' || (axios.isAxiosError(err) && err.response?.status === 404)) { createError.value = 'That reference could not be found among your own facts.'; return; }
      if (code === 'validation_failed' && Object.keys(fields).length > 0) { Object.assign(createErrors, fields); return; }
      createError.value = message ?? 'The query could not be submitted.';
      return;
    }
    if (axios.isAxiosError(err) && err.response?.status === 404) { createError.value = 'That reference could not be found among your own facts.'; return; }
    createError.value = 'Something went wrong. Please try again.';
  }
}

/* ---------------------------------------------------------------- query detail */
const detailQuery = ref<EarningsQuery | null>(null);
async function openQuery(query: EarningsQuery): Promise<void> {
  rememberFocus();
  detailQuery.value = query;
  const fresh = await queries.fetchQuery('personnel', query.id);
  if (fresh) detailQuery.value = fresh;
}
function closeQuery(): void {
  detailQuery.value = null;
  restoreFocus();
}
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 py-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      My earnings
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      Your own salary and commission, what has been paid, your payout history and statements, and any
      questions you have raised. Amounts are shown exactly as Servana calculated them, grouped by currency.
    </p>

    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="earnings-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="earnings.forbidden || !canView"
      data-testid="earnings-forbidden"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to earnings.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <!-- overview -->
      <section
        aria-labelledby="overview-heading"
        class="mt-6"
      >
        <h2
          id="overview-heading"
          class="text-sm font-semibold text-text"
        >
          Earnings by currency
        </h2>
        <SvStateBoundary
          class="mt-2"
          :state="overviewState"
          :error-message="earnings.overviewError ?? undefined"
          empty-message="No earnings recorded yet."
          @retry="earnings.fetchOverview()"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <SvCard
              v-for="row in earnings.overview.currencies"
              :key="row.currency"
              padding="sm"
              data-testid="earnings-currency-card"
            >
              <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
                {{ row.currency }}
              </p>
              <p class="mt-1 text-lg font-bold text-text">
                {{ money(row.net_minor, row.currency) }}
                <span class="block text-xs font-normal text-text-muted">Net earnings</span>
              </p>
              <dl class="mt-2 space-y-1 text-xs text-text-muted">
                <div class="flex justify-between gap-2">
                  <dt>Unpaid</dt>
                  <dd class="text-text">
                    {{ money(row.unpaid_minor, row.currency) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt>Paid</dt>
                  <dd class="text-text">
                    {{ money(row.paid_minor, row.currency) }}
                  </dd>
                </div>
                <template v-if="showSalary">
                  <div class="flex justify-between gap-2 border-t border-border pt-1">
                    <dt>Salary (unpaid / paid)</dt>
                    <dd class="text-text">
                      {{ money(row.salary_unpaid_minor, row.currency) }} / {{ money(row.salary_paid_minor, row.currency) }}
                    </dd>
                  </div>
                </template>
                <template v-if="showCommission">
                  <div class="flex justify-between gap-2">
                    <dt>Commission (unpaid / paid)</dt>
                    <dd class="text-text">
                      {{ money(row.commission_unpaid_minor, row.currency) }} / {{ money(row.commission_paid_minor, row.currency) }}
                    </dd>
                  </div>
                </template>
              </dl>
            </SvCard>
          </div>
          <p
            v-if="earnings.overview.tab_visibility.conflicting"
            class="mt-2 text-xs text-text-muted"
            data-testid="tab-conflict"
          >
            Your compensation plan needs review, so the salary and commission breakdowns are hidden for now.
          </p>
        </SvStateBoundary>
      </section>

      <!-- compensation terms -->
      <section
        v-if="canViewTerms"
        aria-labelledby="terms-heading"
        class="mt-8"
      >
        <h2
          id="terms-heading"
          class="text-sm font-semibold text-text"
        >
          My compensation terms
        </h2>
        <SvCard
          padding="sm"
          class="mt-2"
        >
          <div v-if="earnings.terms && earnings.terms.has_current_plan && !earnings.terms.conflicting">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
              <dt class="text-text-muted">
                Model
              </dt>
              <dd class="text-text">
                {{ earnings.terms.compensation_model }}
              </dd>
              <template v-if="earnings.terms.salary_amount_minor != null && earnings.terms.salary_currency">
                <dt class="text-text-muted">
                  Salary
                </dt>
                <dd class="text-text">
                  {{ money(earnings.terms.salary_amount_minor, earnings.terms.salary_currency) }}
                  <span v-if="earnings.terms.salary_period">/ {{ earnings.terms.salary_period }}</span>
                </dd>
              </template>
              <template v-if="earnings.terms.effective_from">
                <dt class="text-text-muted">
                  Effective from
                </dt>
                <dd class="text-text">
                  {{ earnings.terms.effective_from }}
                </dd>
              </template>
            </dl>
          </div>
          <p
            v-else
            class="text-sm text-text-muted"
          >
            No current compensation plan is on file. Your paid earnings above still reflect what you have earned.
          </p>
        </SvCard>
      </section>

      <!-- payout history -->
      <section
        v-if="canViewPayouts"
        aria-labelledby="payouts-heading"
        class="mt-8"
      >
        <h2
          id="payouts-heading"
          class="text-sm font-semibold text-text"
        >
          Payout history
        </h2>
        <p
          v-if="statementError"
          data-testid="statement-error"
          class="mt-1 rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ statementError }}
        </p>
        <SvStateBoundary
          class="mt-2"
          :state="payoutsState"
          :error-message="earnings.payoutsError ?? undefined"
          empty-message="You have no payout history yet."
          @retry="earnings.fetchPayouts()"
        >
          <ul class="mt-2 flex flex-col gap-3">
            <li
              v-for="item in earnings.payouts"
              :key="item.id"
              data-testid="payout-history-row"
            >
              <SvCard padding="sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="font-semibold text-text">
                      {{ money(item.gross_amount_minor, item.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-text-muted">
                      Salary {{ money(item.salary_amount_minor, item.currency) }} ·
                      commission {{ money(item.commission_amount_minor, item.currency) }} ·
                      adjustments {{ money(item.adjustment_amount_minor, item.currency) }} · {{ item.status }}
                    </p>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <SvButton
                      v-if="canDownload && item.status === 'paid'"
                      variant="secondary"
                      :data-testid="`statement-${item.id}`"
                      :loading="earnings.generating"
                      @click="downloadStatement(item.id)"
                    >
                      {{ item.has_statement ? 'Download statement' : 'Generate statement' }}
                    </SvButton>
                    <SvButton
                      v-if="canQuery"
                      variant="secondary"
                      :data-testid="`query-item-${item.id}`"
                      @click="openCreate(item.id, 'payout_item')"
                    >
                      Query this
                    </SvButton>
                  </div>
                </div>
                <p
                  v-if="lastStatement && lastStatement.itemId === item.id"
                  class="mt-2 text-xs"
                >
                  <a
                    :href="lastStatement.download.url"
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline"
                    :data-testid="`statement-link-${item.id}`"
                  >Download {{ lastStatement.filename }}</a>
                </p>
              </SvCard>
            </li>
          </ul>

          <nav
            v-if="earnings.payoutsMeta.last_page > 1"
            class="mt-4 flex items-center justify-between"
            aria-label="Payout history pagination"
          >
            <SvButton
              variant="secondary"
              :disabled="earnings.payoutsMeta.current_page <= 1"
              @click="payoutsPage(earnings.payoutsMeta.current_page - 1)"
            >
              Previous
            </SvButton>
            <span class="text-sm text-text-muted">Page {{ earnings.payoutsMeta.current_page }} of {{ earnings.payoutsMeta.last_page }}</span>
            <SvButton
              variant="secondary"
              :disabled="earnings.payoutsMeta.current_page >= earnings.payoutsMeta.last_page"
              @click="payoutsPage(earnings.payoutsMeta.current_page + 1)"
            >
              Next
            </SvButton>
          </nav>
        </SvStateBoundary>
      </section>

      <!-- earnings queries -->
      <section
        v-if="canQuery"
        aria-labelledby="queries-heading"
        class="mt-8"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2
            id="queries-heading"
            class="text-sm font-semibold text-text"
          >
            My earnings queries
          </h2>
          <SvButton
            data-testid="open-query"
            @click="openCreate()"
          >
            Raise a query
          </SvButton>
        </div>
        <SvStateBoundary
          class="mt-2"
          :state="queriesState"
          :error-message="queries.listError ?? undefined"
          empty-message="You have not raised any earnings queries."
          @retry="queries.fetchQueries('personnel')"
        >
          <ul class="mt-2 flex flex-col gap-3">
            <li
              v-for="query in queries.queries"
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
                    </p>
                    <p class="mt-1 break-words text-sm text-text">
                      {{ query.body }}
                    </p>
                  </div>
                  <SvButton
                    variant="secondary"
                    :data-testid="`query-details-${query.id}`"
                    @click="openQuery(query)"
                  >
                    Open
                  </SvButton>
                </div>
              </SvCard>
            </li>
          </ul>
        </SvStateBoundary>
      </section>
    </template>

    <!-- create query modal -->
    <SvModal
      :open="createOpen"
      title="Raise an earnings query"
      description="Ask Finance about one of your own facts — a commission entry, a salary entry, or a payout item. Enter its reference and describe the issue. Finance will respond; any correction is made as a separate adjustment."
      @close="closeCreate"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCreate"
      >
        <p
          v-if="createError"
          data-testid="query-error"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ createError }}
        </p>
        <SvSelect
          id="query-subject-type"
          label="What is this about?"
          :model-value="createForm.subject_type"
          :options="EARNINGS_QUERY_SUBJECT_OPTIONS"
          required
          @update:model-value="createForm.subject_type = $event"
        />
        <SvInput
          id="query-subject-ulid"
          label="Reference"
          hint="The 26-character reference of your own commission, salary or payout item."
          :model-value="createForm.subject_ulid"
          :errors="createErrors.subject_ulid"
          required
          @update:model-value="createForm.subject_ulid = $event"
        />
        <SvSelect
          id="query-type"
          label="Type of question"
          :model-value="createForm.query_type"
          :options="EARNINGS_QUERY_TYPE_OPTIONS"
          required
          @update:model-value="createForm.query_type = $event"
        />
        <SvTextarea
          id="query-body"
          label="Describe the issue"
          :model-value="createForm.body"
          :errors="createErrors.body"
          required
          @update:model-value="createForm.body = $event"
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
            data-testid="query-submit"
            :loading="queries.mutating"
          >
            Submit query
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- query detail modal -->
    <SvModal
      :open="detailQuery !== null"
      title="Earnings query"
      description="The status of your query and Finance's response. Any monetary correction appears as a separate adjustment reference."
      @close="closeQuery"
    >
      <dl
        v-if="detailQuery"
        class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
      >
        <dt class="text-text-muted">
          Type
        </dt>
        <dd class="text-text">
          {{ earningsQueryTypeLabel(detailQuery.query_type) }}
        </dd>
        <dt class="text-text-muted">
          Status
        </dt>
        <dd class="text-text">
          {{ earningsQueryStatusLabel(detailQuery.status) }}
        </dd>
        <dt class="text-text-muted">
          Your message
        </dt>
        <dd class="break-words text-text">
          {{ detailQuery.body }}
        </dd>
        <template v-if="detailQuery.resolution_note">
          <dt class="text-text-muted">
            Finance response
          </dt>
          <dd class="break-words text-text">
            {{ detailQuery.resolution_note }}
          </dd>
        </template>
        <template v-if="detailQuery.resolved_adjustment_id">
          <dt class="text-text-muted">
            Correction reference
          </dt>
          <dd class="break-all text-text">
            {{ detailQuery.resolved_adjustment_id }}
          </dd>
        </template>
      </dl>
      <div class="mt-4 flex justify-end">
        <SvButton
          variant="secondary"
          @click="closeQuery"
        >
          Close
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
