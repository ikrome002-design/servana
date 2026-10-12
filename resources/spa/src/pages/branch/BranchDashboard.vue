<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
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
    UI16-VIS-012: the branch dashboard used to split its greeting header from a separate brown
    "pulse" panel whose gradient named an unconfigured colour (`to-brand-citrus/80`, which compiles
    to nothing, so the panel faded to white), then repeat four identical top-bordered cards. It is
    now one branch-day story: the command hero carries the greeting, the day's state and the four
    live counts; the day's flow is the dominant panel; money and the subscription notice sit beside
    it; reporting is shown as gated.
  -->
  <section
    class="mx-auto max-w-7xl"
    data-testid="branch-dashboard"
  >
    <SvOperationalHero
      class="mb-6"
      eyebrow="Live branch desk"
      :title="`Good day, ${auth.user?.name ?? 'Branch Manager'}`"
      :description="overview ? `${overview.branch.name} · ${overview.business_date}` : 'Readiness, workload and close-of-day attention for your assigned branch.'"
      :context="overview ? `${overview.branch.town ?? 'Town not set'} · Branch ${overview.branch.code}` : undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
          :to="{ name: 'branch.branch-day' }"
        >
          Open branch day
        </RouterLink>
      </template>

      <div v-if="overview">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
          Today’s operating pulse
        </p>
        <h2 class="sr-only">
          {{ overview.branch.name }}
        </h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">Day {{ overview.day.status.replaceAll('_', ' ') }}</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">Queue {{ overview.day.queue_is_open ? 'open' : 'closed' }}</span>
          <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.active }} active staff</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div class="rounded-card border border-white/15 bg-white/10 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.queue.active }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Active queue
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.appointments.today }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Appointments today
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.services.active }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Active services
            </p>
          </div>
          <div class="rounded-card border border-white/10 bg-white/5 p-4">
            <p class="sv-numeric font-display text-3xl font-extrabold leading-none">
              {{ overview.financial.pending_payment_validations }}
            </p>
            <p class="mt-1 text-xs text-white/75">
              Awaiting Finance
            </p>
          </div>
        </div>
      </div>
    </SvOperationalHero>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,0.65fr)]">
          <section
            aria-labelledby="branch-flow-heading"
            class="sv-branch-flow rounded-card border border-sv-border p-5 shadow-card md:p-6"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Branch day
            </p>
            <h2
              id="branch-flow-heading"
              class="mt-1 font-display text-xl font-extrabold text-sv-text-heading"
            >
              Today’s flow
            </h2>
            <ul class="mt-5 grid gap-3">
              <li
                v-for="step in flow"
                :key="step.key"
                class="flex flex-col gap-3 rounded-card border border-sv-border bg-sv-surface-raised p-4 md:flex-row md:items-center"
              >
                <span class="sv-branch-flow__count sv-numeric inline-flex h-12 min-w-12 shrink-0 items-center justify-center self-start rounded-control px-3 font-display text-lg font-extrabold md:self-auto">{{ step.value }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-sv-text-heading">{{ step.label }}</span>
                  <span class="block text-xs text-sv-text-muted">{{ step.hint }}</span>
                </span>
                <RouterLink
                  class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center gap-1 self-start rounded-control px-3 py-2 text-sm font-semibold text-sv-link hover:bg-sv-surface-subtle md:self-auto"
                  :to="{ name: step.to }"
                >
                  {{ step.action }}<SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>

          <div class="flex flex-col gap-6">
            <section
              aria-labelledby="branch-revenue-heading"
              class="sv-branch-revenue rounded-card border border-sv-border p-5 shadow-card"
            >
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
                Validated
              </p>
              <h2
                id="branch-revenue-heading"
                class="mt-1 font-display text-lg font-bold text-sv-text-heading"
              >
                Branch revenue
              </h2>
              <p
                v-if="overview.financial.validated_revenue_by_currency.length"
                class="sv-numeric mt-3 font-display text-3xl font-extrabold text-sv-text-heading"
              >
                {{ formatMoney(overview.financial.validated_revenue_by_currency[0]!.amount_minor, overview.financial.validated_revenue_by_currency[0]!.currency) }}
              </p>
              <p
                v-else
                class="mt-3 text-sm text-sv-text-muted"
              >
                No validated payments recorded yet.
              </p>
              <p class="mt-1 text-xs text-sv-text-muted">
                Only payments Finance has validated count here.
              </p>
            </section>

            <section
              aria-labelledby="branch-subscription-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            >
              <h2
                id="branch-subscription-heading"
                class="font-display text-lg font-bold text-sv-text-heading"
              >
                Subscription notice
              </h2>
              <template v-if="overview.billing.next_invoice">
                <p class="mt-3 text-sm font-semibold text-sv-text-heading">
                  {{ overview.billing.next_invoice.invoice_number ?? 'Current subscription invoice' }}
                </p>
                <p class="text-sm text-sv-text-muted">
                  {{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }} due · {{ statusLabel(overview.billing.next_invoice.status) }}
                </p>
              </template>
              <p
                v-else
                class="mt-3 text-sm text-sv-text-muted"
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
            </section>
          </div>
        </div>

        <section class="mt-6 rounded-card border border-dashed border-sv-border-strong bg-sv-surface-subtle p-5">
          <h2 class="flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading">
            <SvIconLocked
              aria-hidden="true"
              class="h-5 w-5 text-sv-text-muted"
            />
            Reporting readiness
          </h2>
          <p class="mt-2 text-sm text-sv-text-muted">
            The Branch report workspace remains gated until its canonical reporting runtime exists.
          </p>
          <p
            class="mt-3 inline-flex rounded-control bg-sv-surface-raised px-3 py-2 text-sm font-semibold text-sv-text"
            role="note"
          >
            {{ overview.reporting.reason }}
          </p>
        </section>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-branch-flow {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-growth) 10%, transparent), transparent 42%),
    var(--sv-color-surface-raised);
}
.sv-branch-flow__count {
  background: color-mix(in srgb, var(--sv-color-growth) 14%, var(--sv-color-surface-raised));
  color: var(--sv-color-text-heading);
}
.sv-branch-revenue {
  background: linear-gradient(170deg, color-mix(in srgb, var(--sv-color-growth) 12%, var(--sv-color-surface-raised)), var(--sv-color-surface-raised) 65%);
}
</style>