<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconChevronRight, SvIconSecurity } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { useAuthStore } from '@/stores/authStore';
import { useFinanceWorkspaceStore, type FinanceMoney } from '@/stores/financeWorkspaceStore';

const auth = useAuthStore();
const store = useFinanceWorkspaceStore();
const overview = computed(() => store.overview);
const state = computed(() => (store.overviewLoading ? 'loading' : store.overviewError ? 'error' : overview.value ? 'success' : 'empty'));

function moneySummary(rows: FinanceMoney[]): string {
  return rows.length === 0 ? 'No amount recorded' : rows.map((row) => row.formatted).join(' · ');
}

onMounted(() => {
  void store.fetchOverview();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the Finance RISK command centre. The control position leads on the dark
    feature surface as three risk dials the checker owns (validation backlog, duplicate risk,
    cash-ups awaiting review) under the maker/checker statement; the ranked priority queue (with
    fresh step-up markers) sits beside it; close controls follow as a four-tile band; the money
    position keeps its per-currency ledger — the server's formatted amounts, never summed or
    converted — and the Wallet gate stays stated, never zeroed.
  -->
  <section
    class="sv-r-page"
    data-testid="finance-dashboard"
  >
    <SvTonalPageHeader
      eyebrow="Financial control desk"
      :title="`Good day, ${auth.user?.name ?? 'Finance'}`"
      :description="overview ? `${overview.branch_context.label} · checker-owned controls and reconciliation` : 'Validate payments, close periods and move Finance work forward without crossing maker/checker boundaries.'"
      context="Checker"
    >
      <template #actions>
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'finance.payments-validations' }"
        >
          Review validations
        </RouterLink>
        <RouterLink
          class="sv-r-action sv-r-action--quiet sv-focus-ring"
          :to="{ name: 'finance.tasks' }"
        >
          Open task inbox
        </RouterLink>
      </template>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned Finance branch is available."
      @retry="store.fetchOverview()"
    >
      <div
        v-if="overview"
        class="sv-r-stack"
      >
        <div class="sv-r-grid sv-r-split-lead">
          <SvFeaturePanel
            eyebrow="Control position"
            title="What needs a checker now"
          >
            <h2 class="sr-only">
              {{ overview.branch_context.label }}
            </h2>
            <p class="sv-r-feature__hint mt-2 max-w-2xl text-sm leading-6">
              Recorded money remains pending until a different authorized checker validates the whole group. Receipt issuance follows that server decision automatically.
            </p>
            <div class="mt-5 grid gap-3 md:grid-cols-3">
              <RouterLink
                class="sv-r-feature__cell sv-focus-ring block transition-transform duration-sv-fast hover:-translate-y-0.5"
                :to="{ name: 'finance.payments-validations' }"
              >
                <p class="sv-r-feature__value text-4xl">
                  {{ overview.payments.pending_validation }}
                </p>
                <p class="sv-r-feature__label mt-2">
                  {{ overview.payments.pending_validation }} awaiting validation
                </p>
              </RouterLink>
              <RouterLink
                class="sv-r-feature__cell sv-focus-ring block transition-transform duration-sv-fast hover:-translate-y-0.5"
                :to="{ name: 'finance.payments-duplicates' }"
              >
                <p class="sv-r-feature__value sv-r-feature__accent text-4xl">
                  {{ overview.payments.duplicate_risk }}
                </p>
                <p class="sv-r-feature__label mt-2">
                  {{ overview.payments.duplicate_risk }} duplicate risks
                </p>
              </RouterLink>
              <RouterLink
                class="sv-r-feature__cell sv-focus-ring block transition-transform duration-sv-fast hover:-translate-y-0.5"
                :to="{ name: 'finance.cash-up' }"
              >
                <p class="sv-r-feature__value text-4xl">
                  {{ overview.controls.cash_ups_requiring_review }}
                </p>
                <p class="sv-r-feature__label mt-2">
                  {{ overview.controls.cash_ups_requiring_review }} cash-ups to review
                </p>
              </RouterLink>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
              <div class="rounded-control bg-white/5 px-3 py-2">
                <p class="sv-r-feature__value text-xl">
                  {{ overview.invoices.outstanding }}
                </p>
                <p class="sv-r-feature__hint">
                  Outstanding invoices
                </p>
              </div>
              <div class="rounded-control bg-white/5 px-3 py-2">
                <p class="sv-r-feature__value text-xl">
                  {{ overview.controls.original_receipts }}
                </p>
                <p class="sv-r-feature__hint">
                  Original receipts
                </p>
              </div>
              <div class="rounded-control bg-white/5 px-3 py-2">
                <p class="sv-r-feature__value text-xl">
                  {{ overview.compensation.payouts_requiring_action }}
                </p>
                <p class="sv-r-feature__hint">
                  Payout actions
                </p>
              </div>
              <div class="rounded-control bg-white/5 px-3 py-2">
                <p class="sv-r-feature__value text-xl">
                  {{ overview.controls.reopen_requests }}
                </p>
                <p class="sv-r-feature__hint">
                  Reopen requests
                </p>
              </div>
            </div>
          </SvFeaturePanel>

          <section
            aria-labelledby="finance-attention-heading"
            class="sv-r-panel sv-r-panel--tonal sv-r-tone-teal"
          >
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
              Priority queue
            </p>
            <h2
              id="finance-attention-heading"
              class="sv-r-title mt-1"
            >
              Work requiring attention
            </h2>
            <ol class="sv-r-queue mt-4">
              <li
                v-for="task in overview.tasks.filter((item) => item.count > 0).slice(0, 5)"
                :key="task.key"
                class="sv-r-queue__row sv-r-tone-teal flex-nowrap"
              >
                <span class="sv-r-queue__count">{{ task.count }}</span>
                <span class="sv-r-queue__label">
                  {{ task.label }}
                  <span class="sv-r-queue__detail flex flex-wrap items-center gap-2">
                    {{ task.maker_checker }}
                    <span
                      v-if="task.step_up_required"
                      class="inline-flex items-center gap-1 rounded-pill border border-sv-warning-border bg-sv-warning-bg px-2 py-px font-semibold text-sv-warning-fg"
                    ><SvIconSecurity
                      aria-hidden="true"
                      class="h-3.5 w-3.5"
                    />Fresh step-up</span>
                  </span>
                </span>
                <RouterLink
                  class="sv-r-link sv-focus-ring shrink-0 rounded-control"
                  :to="{ name: task.route_name }"
                >
                  Open<SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4"
                  />
                </RouterLink>
              </li>
              <li
                v-if="overview.tasks.every((task) => task.count === 0)"
                class="rounded-card border border-dashed border-sv-border p-4 text-sm text-sv-text-muted"
              >
                No Finance control tasks are currently waiting.
              </li>
            </ol>
          </section>
        </div>

        <section aria-labelledby="finance-close-heading">
          <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
            Close controls
          </p>
          <h2
            id="finance-close-heading"
            class="sv-r-title mb-3 mt-1"
          >
            Reconcile before locking
          </h2>
          <div class="sv-r-grid sv-r-cols-4">
            <SvKpiCard
              label="Cash-ups"
              tone="teal"
              :icon="navigationIcon('cash-up')"
              :to="{ name: 'finance.cash-up' }"
              link-label="Review cash-ups"
            >
              {{ overview.controls.cash_ups_requiring_review }}
            </SvKpiCard>
            <SvKpiCard
              label="Open periods"
              tone="sun"
              :icon="navigationIcon('period')"
              :to="{ name: 'finance.periods' }"
              link-label="Financial periods"
            >
              {{ overview.controls.open_periods }}
            </SvKpiCard>
            <SvKpiCard
              label="Disputes"
              tone="alert"
              :icon="navigationIcon('dispute')"
              :to="{ name: 'finance.disputes' }"
              link-label="Open disputes"
            >
              {{ overview.controls.active_disputes }}
            </SvKpiCard>
            <SvKpiCard
              label="Refunds"
              tone="warm"
              :icon="navigationIcon('refund')"
              :to="{ name: 'finance.refunds' }"
              link-label="External refunds"
            >
              {{ overview.controls.refunds_requiring_action }}
            </SvKpiCard>
          </div>
        </section>

        <!-- Money position: the Billings-reference ledger band. -->
        <section
          aria-labelledby="finance-money-heading"
          class="sv-r-ledger"
        >
          <div class="sv-r-ledger__head">
            <h2
              id="finance-money-heading"
              class="sv-r-title"
            >
              Money position
            </h2>
            <span class="sv-r-chip">Per currency · server-formatted</span>
          </div>
          <div class="grid divide-y divide-sv-border md:grid-cols-2 md:divide-y-0 lg:grid-cols-4 lg:divide-x">
            <article class="p-5">
              <p class="sv-r-eyebrow">
                Recorded, not recognized
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Pending payments
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.payments.pending_recorded) }}
              </p>
              <RouterLink
                class="sv-r-link sv-focus-ring mt-1 rounded-control"
                :to="{ name: 'finance.payments' }"
              >
                Inspect records<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="p-5">
              <p class="sv-r-eyebrow">
                Balance still due
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Invoice exposure
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.invoices.outstanding_balance) }}
              </p>
              <RouterLink
                class="sv-r-link sv-focus-ring mt-1 rounded-control"
                :to="{ name: 'finance.invoices' }"
              >
                Review invoices<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="bg-sv-success-bg/40 p-5">
              <p class="sv-r-eyebrow">
                Server-recognized
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Validated payments
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.invoices.validated_payments) }}
              </p>
              <RouterLink
                class="sv-r-link sv-focus-ring mt-1 rounded-control"
                :to="{ name: 'finance.receipts' }"
              >
                Open receipts<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="p-5">
              <p class="sv-r-eyebrow">
                People liability
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Salary + commission
              </h3>
              <p class="sv-numeric mt-3 text-sm font-bold text-sv-text-heading">
                Salary {{ moneySummary(overview.compensation.salary_due) }}
              </p>
              <p class="sv-numeric mt-1 text-sm font-bold text-sv-text-heading">
                Commission {{ moneySummary(overview.compensation.commission_due) }}
              </p>
              <RouterLink
                class="sv-r-link sv-focus-ring mt-1 rounded-control"
                :to="{ name: 'finance.compensation-liabilities' }"
              >
                Review liabilities<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
          </div>
        </section>

        <SvAlert
          severity="info"
          title="Wallet billing remains gated"
          data-testid="finance-wallet-gate"
        >
          {{ overview.subscription.reason }} No amount or status is shown as zero in its place.
        </SvAlert>
      </div>
    </SvStateBoundary>
  </section>
</template>