<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
import { formatMoney } from '@/utils/money';
import { statusLabel } from '@/utils/statusLabel';

const auth = useAuthStore();
const store = useBranchExperienceStore();
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.overview ? 'success' : 'empty');
const overview = computed(() => store.overview);

/** The server's gate sentence, with its own full stop removed so a second sentence can follow. */
function sentence(reason: string | null | undefined): string {
  return (reason ?? '').trim().replace(/\.+$/, '');
}

/**
 * The branch-day flow, in the order a manager works it. Every count is the server's own figure;
 * the one sum (queue + today's active appointments) is the same "active commitments" figure the
 * UI-10 dashboard already showed, computed from two server counts rather than from a list.
 */
const flow = computed(() => {
  const o = overview.value;
  if (!o) return [];
  return [
    { key: 'commitments', label: 'Queue and appointments', hint: 'Active commitments right now', value: String(o.queue.active + o.appointments.active_today), to: 'branch.operations-queue', action: 'Review queue' },
    { key: 'blockers', label: 'Day blockers', hint: 'Items that stop the day from closing', value: String(o.day.close_blockers.length + o.day.financial_close_blockers.length), to: 'branch.branch-day', action: 'Review branch day' },
    { key: 'cash-up', label: 'Cash-up maker status', hint: 'Finance remains the checker', value: o.cash_up ? statusLabel(o.cash_up.status) : 'Not started', to: 'branch.cash-up', action: 'Open cash-up' },
    { key: 'validations', label: 'Awaiting Finance validation', hint: 'Recorded payments Finance has not yet checked', value: String(o.financial.pending_payment_validations), to: 'branch.finance-payments', action: 'View payment status' },
  ];
});

onMounted(() => { void store.fetchOverview(); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the branch-day control story. The DAY STATE takes first visual priority
    on the dark feature surface (day status, queue, staff on shift, and the four live counts), then
    the day's flow runs as a numbered operating sequence — commitments, blockers, the cash-up maker
    step, Finance validation — beside the read-only money and the subscription notice; reporting is
    shown as gated. Every count is the server's; the one sum is the established commitments figure.
  -->
  <section
    class="sv-r-page"
    data-testid="branch-dashboard"
  >
    <SvTonalPageHeader
      eyebrow="Live branch desk"
      :title="`Good day, ${auth.user?.name ?? 'Branch Manager'}`"
      :description="overview ? `${overview.branch.name} · ${overview.business_date}` : 'Readiness, workload and close-of-day attention for your assigned branch.'"
      :context="overview ? `${overview.branch.town ?? 'Town not set'} · Branch ${overview.branch.code}` : undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'branch.branch-day' }"
        >
          Open branch day
        </RouterLink>
      </template>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="store.fetchOverview()"
    >
      <div
        v-if="overview"
        class="sv-r-stack"
      >
        <SvFeaturePanel
          eyebrow="Today’s operating pulse"
          as="div"
        >
          <h2 class="sr-only">
            {{ overview.branch.name }}
          </h2>
          <div class="mt-3 grid gap-6 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.6fr)] lg:items-end">
            <div>
              <p class="sv-r-feature__label">
                Branch day
              </p>
              <p class="sv-r-feature__value sv-r-figure-xl mt-2 capitalize">
                {{ overview.day.status.replaceAll('_', ' ') }}
              </p>
              <span
                aria-hidden="true"
                class="sv-r-feature__rule mt-4 block"
              />
              <div class="mt-4 flex flex-wrap gap-2">
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">Day {{ overview.day.status.replaceAll('_', ' ') }}</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">Queue {{ overview.day.queue_is_open ? 'open' : 'closed' }}</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white">{{ overview.staff.active }} active staff</span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <div class="sv-r-feature__cell">
                <p class="sv-r-feature__value text-3xl">
                  {{ overview.queue.active }}
                </p>
                <p class="sv-r-feature__hint mt-1">
                  Active queue
                </p>
              </div>
              <div class="sv-r-feature__cell">
                <p class="sv-r-feature__value text-3xl">
                  {{ overview.appointments.today }}
                </p>
                <p class="sv-r-feature__hint mt-1">
                  Appointments today
                </p>
              </div>
              <div class="sv-r-feature__cell">
                <p class="sv-r-feature__value text-3xl">
                  {{ overview.services.active }}
                </p>
                <p class="sv-r-feature__hint mt-1">
                  Active services
                </p>
              </div>
              <div class="sv-r-feature__cell">
                <p class="sv-r-feature__value sv-r-feature__accent text-3xl">
                  {{ overview.financial.pending_payment_validations }}
                </p>
                <p class="sv-r-feature__hint mt-1">
                  Awaiting Finance
                </p>
              </div>
            </div>
          </div>
        </SvFeaturePanel>

        <div class="sv-r-grid sv-r-split-lead">
          <SvPanel
            eyebrow="Branch day"
            title="Today’s flow"
            description="In the order a manager works the day."
            tone="green"
          >
            <ol class="sv-r-steps">
              <li
                v-for="(step, index) in flow"
                :key="step.key"
                class="sv-r-step"
              >
                <span
                  aria-hidden="true"
                  class="sv-r-step__marker"
                >{{ index + 1 }}</span>
                <div class="sv-r-queue__row sv-r-tone-green">
                  <span class="sv-r-queue__count">{{ step.value }}</span>
                  <span class="sv-r-queue__label">
                    {{ step.label }}
                    <span class="sv-r-queue__detail">{{ step.hint }}</span>
                  </span>
                  <RouterLink
                    class="sv-r-link sv-focus-ring shrink-0 rounded-control"
                    :to="{ name: step.to }"
                  >
                    {{ step.action }}<SvIconChevronRight
                      aria-hidden="true"
                      class="h-4 w-4"
                    />
                  </RouterLink>
                </div>
              </li>
            </ol>
          </SvPanel>

          <div class="sv-r-stack">
            <SvKpiCard
              label="Branch revenue"
              tone="green"
              :icon="navigationIcon('payment')"
              hint="Only payments Finance has validated count here."
            >
              <template v-if="overview.financial.validated_revenue_by_currency.length">
                {{ formatMoney(overview.financial.validated_revenue_by_currency[0]!.amount_minor, overview.financial.validated_revenue_by_currency[0]!.currency) }}
              </template>
              <span
                v-else
                class="text-base font-medium text-sv-text-muted"
              >No validated payments recorded yet.</span>
              <template #meta>
                <span class="sv-r-chip self-start">Read-only · validated</span>
              </template>
            </SvKpiCard>

            <SvPanel
              title="Subscription notice"
              tone="neutral"
            >
              <template v-if="overview.billing.next_invoice">
                <p class="text-sm font-semibold text-sv-text-heading">
                  {{ overview.billing.next_invoice.invoice_number ?? 'Current subscription invoice' }}
                </p>
                <p class="text-sm text-sv-text-muted">
                  {{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }} due · {{ statusLabel(overview.billing.next_invoice.status) }}
                </p>
              </template>
              <p
                v-else
                class="sv-r-lede"
              >
                No outstanding subscription invoice is visible.
              </p>
              <!-- UI16-CONTENT-002: the server sentence already ends with a full stop. -->
              <SvAlert
                class="mt-4"
                severity="info"
                title="Payment action unavailable"
              >
                {{ sentence(overview.billing.payment_runtime.reason) }}. No payment attempt or success is fabricated.
              </SvAlert>
            </SvPanel>
          </div>
        </div>

        <section class="sv-r-panel sv-r-panel--dashed">
          <div class="flex flex-wrap items-start gap-4">
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-tone-neutral"
            >
              <SvIconLocked />
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="sv-r-title">
                Reporting readiness
              </h2>
              <p class="sv-r-lede mt-1">
                The Branch report workspace remains gated until its canonical reporting runtime exists.
              </p>
            </div>
            <p
              class="sv-r-chip"
              role="note"
            >
              {{ overview.reporting.reason }}
            </p>
          </div>
        </section>
      </div>
    </SvStateBoundary>
  </section>
</template>