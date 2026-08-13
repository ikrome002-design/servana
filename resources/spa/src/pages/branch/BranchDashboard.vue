<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { formatMoney } from '@/utils/money';

const auth = useAuthStore();
const store = useBranchExperienceStore();
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.overview ? 'success' : 'empty');
const overview = computed(() => store.overview);

onMounted(() => { void store.fetchOverview(); });
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="branch-dashboard"
  >
    <SvPageHeader
      :title="`Good day, ${auth.user?.name ?? 'Branch Manager'}`"
      eyebrow="Live branch desk"
      :description="overview ? `${overview.branch.name} · ${overview.business_date}` : 'Readiness, workload and close-of-day attention for your assigned branch.'"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep"
          :to="{ name: 'branch.branch-day' }"
        >
          Open branch day
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No assigned branch is available."
      @retry="store.fetchOverview()"
    >
      <template v-if="overview">
        <div class="overflow-hidden rounded-card border border-sv-border bg-sv-surface shadow-card">
          <div class="grid gap-5 bg-gradient-to-br from-brand-deep via-brand-deep to-brand-citrus/80 p-5 text-white md:grid-cols-[1.4fr_1fr] md:p-7">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
                Today’s operating pulse
              </p>
              <h2 class="mt-2 font-display text-2xl font-extrabold">
                {{ overview.branch.name }}
              </h2>
              <p class="mt-1 text-sm text-white/75">
                {{ overview.branch.town ?? 'Town not set' }} · Branch {{ overview.branch.code }}
              </p>
              <div class="mt-5 flex flex-wrap gap-2">
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">Day {{ overview.day.status.replaceAll('_', ' ') }}</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">Queue {{ overview.day.queue_is_open ? 'open' : 'closed' }}</span>
                <span class="rounded-full bg-white/15 px-3 py-1 text-sm font-semibold">{{ overview.staff.active }} active staff</span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.queue.active }}
                </p><p class="text-xs text-white/75">
                  Active queue
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.appointments.today }}
                </p><p class="text-xs text-white/75">
                  Appointments today
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.services.active }}
                </p><p class="text-xs text-white/75">
                  Active services
                </p>
              </div>
              <div class="rounded-control bg-white/10 p-3">
                <p class="text-2xl font-bold">
                  {{ overview.financial.pending_payment_validations }}
                </p><p class="text-xs text-white/75">
                  Awaiting Finance
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-brand"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Flow
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Queue and appointments
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.queue.active + overview.appointments.active_today }}
            </p>
            <p class="text-sm text-text-muted">
              active commitments
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'branch.operations-queue' }"
            >
              Review queue
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-success-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Validated
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Branch revenue
            </h2>
            <p
              v-if="overview.financial.validated_revenue_by_currency.length"
              class="mt-3 text-xl font-bold text-heading"
            >
              {{ formatMoney(overview.financial.validated_revenue_by_currency[0]!.amount_minor, overview.financial.validated_revenue_by_currency[0]!.currency) }}
            </p>
            <p
              v-else
              class="mt-3 text-sm text-text-muted"
            >
              No validated payments recorded yet.
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'branch.finance-payments' }"
            >
              View payment status
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-warning-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Close readiness
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Day blockers
            </h2>
            <p class="mt-3 text-2xl font-bold text-heading">
              {{ overview.day.close_blockers.length + overview.day.financial_close_blockers.length }}
            </p>
            <p class="text-sm text-text-muted">
              items require attention
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'branch.branch-day' }"
            >
              Review branch day
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-info-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Cash-up
            </p>
            <h2 class="mt-1 font-display font-bold text-heading">
              Maker status
            </h2>
            <p class="mt-3 text-xl font-bold capitalize text-heading">
              {{ overview.cash_up?.status.replaceAll('_', ' ') ?? 'Not started' }}
            </p>
            <p class="text-sm text-text-muted">
              Finance remains the checker.
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'branch.cash-up' }"
            >
              Open cash-up
            </RouterLink>
          </SvCard>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">
              Subscription notice
            </h2>
            <template v-if="overview.billing.next_invoice">
              <p class="mt-3 text-sm font-semibold text-heading">
                {{ overview.billing.next_invoice.invoice_number ?? 'Current subscription invoice' }}
              </p>
              <p class="text-sm text-text-muted">
                {{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }} due · {{ overview.billing.next_invoice.status }}
              </p>
            </template>
            <p
              v-else
              class="mt-3 text-sm text-text-muted"
            >
              No outstanding subscription invoice is visible.
            </p>
            <SvAlert
              class="mt-4"
              severity="info"
              title="Payment action unavailable"
            >
              {{ overview.billing.payment_runtime.reason }}. No payment attempt or success is fabricated.
            </SvAlert>
          </SvCard>
          <SvCard as="section">
            <h2 class="font-display text-lg font-bold text-heading">
              Reporting readiness
            </h2>
            <p class="mt-3 text-sm text-text-muted">
              The Branch report workspace remains gated until its canonical reporting runtime exists.
            </p>
            <p
              class="mt-3 rounded-control bg-surface-alt px-3 py-2 text-sm font-semibold text-text"
              role="note"
            >
              {{ overview.reporting.reason }}
            </p>
          </SvCard>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
