<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
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
  <section
    class="mx-auto max-w-7xl"
    data-testid="finance-dashboard"
  >
    <SvPageHeader
      :title="`Good day, ${auth.user?.name ?? 'Finance'}`"
      eyebrow="Financial control desk"
      :description="overview ? `${overview.branch_context.label} · checker-owned controls and reconciliation` : 'Validate payments, close periods and move Finance work forward without crossing maker/checker boundaries.'"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
          :to="{ name: 'finance.payments-validations' }"
        >
          Review validations
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned Finance branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="overflow-hidden rounded-card border border-sv-border bg-sv-surface shadow-card">
          <div class="grid gap-6 bg-brand-deep p-5 text-white md:grid-cols-[1.3fr_0.7fr] md:p-7">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/70">
                Control position
              </p>
              <h2 class="mt-2 font-display text-2xl font-extrabold">
                {{ overview.branch_context.label }}
              </h2>
              <p class="mt-2 max-w-2xl text-sm leading-6 text-white/75">
                Recorded money remains pending until a different authorized checker validates the whole group. Receipt issuance follows that server decision automatically.
              </p>
              <div class="mt-5 flex flex-wrap gap-2">
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payments.pending_validation }} awaiting validation</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.payments.duplicate_risk }} duplicate risks</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.controls.cash_ups_requiring_review }} cash-ups to review</span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.invoices.outstanding }}
                </p>
                <p class="text-xs text-white/75">
                  Outstanding invoices
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.controls.original_receipts }}
                </p>
                <p class="text-xs text-white/75">
                  Original receipts
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.compensation.payouts_requiring_action }}
                </p>
                <p class="text-xs text-white/75">
                  Payout actions
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.controls.reopen_requests }}
                </p>
                <p class="text-xs text-white/75">
                  Reopen requests
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-brand"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Recorded, not recognized
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Pending payments
            </h2>
            <p class="mt-3 text-lg font-bold text-heading">
              {{ moneySummary(overview.payments.pending_recorded) }}
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'finance.payments' }"
            >
              Inspect records
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-warning-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Balance still due
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Invoice exposure
            </h2>
            <p class="mt-3 text-lg font-bold text-heading">
              {{ moneySummary(overview.invoices.outstanding_balance) }}
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'finance.invoices' }"
            >
              Review invoices
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-success-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Server-recognized
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Validated payments
            </h2>
            <p class="mt-3 text-lg font-bold text-heading">
              {{ moneySummary(overview.invoices.validated_payments) }}
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'finance.receipts' }"
            >
              Open receipts
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-info-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              People liability
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Salary + commission
            </h2>
            <p class="mt-3 text-sm font-bold text-heading">
              Salary {{ moneySummary(overview.compensation.salary_due) }}
            </p>
            <p class="mt-1 text-sm font-bold text-heading">
              Commission {{ moneySummary(overview.compensation.commission_due) }}
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'finance.compensation-liabilities' }"
            >
              Review liabilities
            </RouterLink>
          </SvCard>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[1.35fr_0.65fr]">
          <SvCard
            as="section"
            aria-labelledby="finance-attention-heading"
          >
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Priority queue
                </p>
                <h2
                  id="finance-attention-heading"
                  class="font-display text-lg font-bold text-heading"
                >
                  Work requiring attention
                </h2>
              </div>
              <RouterLink
                class="inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
                :to="{ name: 'finance.tasks' }"
              >
                Open task inbox
              </RouterLink>
            </div>
            <ul class="mt-3 divide-y divide-border">
              <li
                v-for="task in overview.tasks.filter((item) => item.count > 0).slice(0, 5)"
                :key="task.key"
                class="flex min-h-sv-touch items-center justify-between gap-3 py-3"
              >
                <span>
                  <span class="block text-sm font-semibold text-heading">{{ task.label }}</span>
                  <span class="text-xs text-text-muted">{{ task.maker_checker }}<template v-if="task.step_up_required"> · fresh step-up</template></span>
                </span>
                <span class="flex items-center gap-3">
                  <strong class="text-heading">{{ task.count }}</strong>
                  <RouterLink
                    class="sv-focus-ring rounded-control px-2 py-1 text-sm font-semibold text-heading underline"
                    :to="{ name: task.route_name }"
                  >Open</RouterLink>
                </span>
              </li>
              <li
                v-if="overview.tasks.every((task) => task.count === 0)"
                class="py-4 text-sm text-text-muted"
              >
                No Finance control tasks are currently waiting.
              </li>
            </ul>
          </SvCard>

          <div class="space-y-4">
            <SvCard as="section">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Close controls
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                Reconcile before locking
              </h2>
              <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt class="text-text-muted">
                    Cash-ups
                  </dt><dd class="mt-1 text-xl font-bold text-heading">
                    {{ overview.controls.cash_ups_requiring_review }}
                  </dd>
                </div>
                <div>
                  <dt class="text-text-muted">
                    Open periods
                  </dt><dd class="mt-1 text-xl font-bold text-heading">
                    {{ overview.controls.open_periods }}
                  </dd>
                </div>
                <div>
                  <dt class="text-text-muted">
                    Disputes
                  </dt><dd class="mt-1 text-xl font-bold text-heading">
                    {{ overview.controls.active_disputes }}
                  </dd>
                </div>
                <div>
                  <dt class="text-text-muted">
                    Refunds
                  </dt><dd class="mt-1 text-xl font-bold text-heading">
                    {{ overview.controls.refunds_requiring_action }}
                  </dd>
                </div>
              </dl>
            </SvCard>
            <SvAlert
              severity="info"
              title="Wallet billing remains gated"
              data-testid="finance-wallet-gate"
            >
              {{ overview.subscription.reason }} No amount or status is shown as zero in its place.
            </SvAlert>
          </div>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
