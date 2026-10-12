<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { SvIconChevronRight, SvIconSecurity } from '@/design-system/icons';
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
    UI16-VIS-014: the Finance dashboard split its greeting header from a flat brown "control
    position" panel, then led with four identical top-bordered money cards ABOVE the checker's own
    queue. The checker's work now leads: the command hero carries the greeting, the control position
    and the four counts; the priority queue is the dominant panel beside the close controls; the
    money position follows as a Billings-reference ledger band with the server's formatted amounts.
    Every amount and count is the `/finance/workspace` projection — nothing is summed or converted
    across currencies in the browser, and recorded money is never shown as recognised.
  -->
  <section
    class="mx-auto max-w-7xl"
    data-testid="finance-dashboard"
  >
    <SvOperationalHero
      class="mb-6"
      eyebrow="Financial control desk"
      :title="`Good day, ${auth.user?.name ?? 'Finance'}`"
      :description="overview ? `${overview.branch_context.label} · checker-owned controls and reconciliation` : 'Validate payments, close periods and move Finance work forward without crossing maker/checker boundaries.'"
      context="Checker"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
          :to="{ name: 'finance.payments-validations' }"
        >
          Review validations
        </RouterLink>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15"
          :to="{ name: 'finance.tasks' }"
        >
          Open task inbox
        </RouterLink>
      </template>

      <div v-if="overview">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/70">
          Control position
        </p>
        <h2 class="sr-only">
          {{ overview.branch_context.label }}
        </h2>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-white/75">
          Recorded money remains pending until a different authorized checker validates the whole group. Receipt issuance follows that server decision automatically.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payments.pending_validation }} awaiting validation</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payments.duplicate_risk }} duplicate risks</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.controls.cash_ups_requiring_review }} cash-ups to review</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="rounded-card border border-white/15 bg-white/10 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.invoices.outstanding }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Outstanding invoices
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.controls.original_receipts }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Original receipts
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.compensation.payouts_requiring_action }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Payout actions
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.controls.reopen_requests }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Reopen requests
            </p>
          </div>
        </div>
      </div>
    </SvOperationalHero>

    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned Finance branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
          <section
            aria-labelledby="finance-attention-heading"
            class="sv-finance-queue rounded-card border border-sv-border p-5 shadow-card md:p-6"
          >
            <div class="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
                  Priority queue
                </p>
                <h2
                  id="finance-attention-heading"
                  class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
                >
                  Work requiring attention
                </h2>
              </div>
            </div>
            <ul class="mt-5 grid gap-3">
              <li
                v-for="task in overview.tasks.filter((item) => item.count > 0).slice(0, 5)"
                :key="task.key"
                class="flex min-h-sv-touch items-center gap-4 rounded-card border border-sv-border bg-sv-surface-raised p-3 pl-4"
              >
                <span class="sv-finance-queue__count sv-numeric inline-flex h-10 min-w-10 shrink-0 items-center justify-center rounded-control px-2 font-display text-lg font-extrabold">{{ task.count }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-sv-text-heading">{{ task.label }}</span>
                  <span class="flex flex-wrap items-center gap-2 text-xs text-sv-text-muted">
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
                  class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center gap-1 rounded-control px-3 py-2 text-sm font-semibold text-sv-link hover:bg-sv-surface-subtle"
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
            </ul>
          </section>

          <section
            aria-labelledby="finance-close-heading"
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Close controls
            </p>
            <h2
              id="finance-close-heading"
              class="mt-1 font-display text-lg font-bold text-sv-text-heading"
            >
              Reconcile before locking
            </h2>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div class="rounded-control bg-sv-surface-subtle p-3">
                <dt class="text-sv-text-muted">
                  Cash-ups
                </dt><dd class="sv-numeric mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
                  {{ overview.controls.cash_ups_requiring_review }}
                </dd>
              </div>
              <div class="rounded-control bg-sv-surface-subtle p-3">
                <dt class="text-sv-text-muted">
                  Open periods
                </dt><dd class="sv-numeric mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
                  {{ overview.controls.open_periods }}
                </dd>
              </div>
              <div class="rounded-control bg-sv-surface-subtle p-3">
                <dt class="text-sv-text-muted">
                  Disputes
                </dt><dd class="sv-numeric mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
                  {{ overview.controls.active_disputes }}
                </dd>
              </div>
              <div class="rounded-control bg-sv-surface-subtle p-3">
                <dt class="text-sv-text-muted">
                  Refunds
                </dt><dd class="sv-numeric mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
                  {{ overview.controls.refunds_requiring_action }}
                </dd>
              </div>
            </dl>
          </section>
        </div>

        <!-- Money position: the Billings-reference ledger band. -->
        <section
          aria-labelledby="finance-money-heading"
          class="mt-6 rounded-card border border-sv-border bg-sv-surface-raised shadow-card"
        >
          <h2
            id="finance-money-heading"
            class="px-5 pt-5 font-display text-lg font-bold text-sv-text-heading"
          >
            Money position
          </h2>
          <div class="mt-3 grid divide-y divide-sv-border border-t border-sv-border md:grid-cols-2 md:divide-y-0 lg:grid-cols-4 lg:divide-x">
            <article class="sv-finance-money p-5">
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Recorded, not recognized
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Pending payments
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.payments.pending_recorded) }}
              </p>
              <RouterLink
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center gap-1 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
                :to="{ name: 'finance.payments' }"
              >
                Inspect records<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="sv-finance-money p-5">
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Balance still due
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Invoice exposure
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.invoices.outstanding_balance) }}
              </p>
              <RouterLink
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center gap-1 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
                :to="{ name: 'finance.invoices' }"
              >
                Review invoices<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="sv-finance-money p-5">
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
                Server-recognized
              </p>
              <h3 class="mt-1 font-display font-bold text-sv-text-heading">
                Validated payments
              </h3>
              <p class="sv-numeric mt-3 font-display text-xl font-extrabold text-sv-text-heading">
                {{ moneySummary(overview.invoices.validated_payments) }}
              </p>
              <RouterLink
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center gap-1 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
                :to="{ name: 'finance.receipts' }"
              >
                Open receipts<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </article>
            <article class="sv-finance-money p-5">
              <p class="text-xs font-semibold uppercase tracking-wide text-sv-text-muted">
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
                class="sv-focus-ring mt-2 inline-flex min-h-sv-touch items-center gap-1 text-sm font-semibold text-sv-link hover:text-sv-link-hover"
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
          class="mt-6"
          severity="info"
          title="Wallet billing remains gated"
          data-testid="finance-wallet-gate"
        >
          {{ overview.subscription.reason }} No amount or status is shown as zero in its place.
        </SvAlert>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-finance-queue {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-secondary) 10%, transparent), transparent 42%),
    var(--sv-color-surface-raised);
}
.sv-finance-queue__count {
  background: color-mix(in srgb, var(--sv-color-brand-secondary) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
</style>