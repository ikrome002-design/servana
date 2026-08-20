<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useFrontOfficeWorkspaceStore } from '@/stores/frontOfficeWorkspaceStore';

const auth = useAuthStore();
const store = useFrontOfficeWorkspaceStore();
const overview = computed(() => store.overview);
const state = computed(() => (store.overviewLoading ? 'loading' : store.overviewError ? 'error' : overview.value ? 'success' : 'empty'));

function observed(value?: string): string | undefined {
  if (!value) return undefined;
  return new Intl.DateTimeFormat('en-KE', { hour: 'numeric', minute: '2-digit', timeZone: 'Africa/Nairobi' }).format(new Date(value));
}

onMounted(() => { void store.fetchOverview(); });
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="front-office-dashboard"
  >
    <SvStateBoundary
      :state="state"
      :error-message="store.overviewError ?? undefined"
      empty-message="No assigned branch workspace is available."
      @retry="store.fetchOverview(true)"
    >
      <template v-if="overview">
        <SvOperationalHero
          eyebrow="Today’s service desk"
          :title="'Good day, ' + (auth.user?.name ?? 'Front Office')"
          :description="overview.branch.name + ' is live. Move arrivals from welcome to service, then hand billing evidence to Finance without crossing the checker boundary.'"
          :context="overview.branch.code + ' · ' + overview.business_date"
          :observed-at="observed(overview.observed_at)"
        >
          <template #actions>
            <RouterLink
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-bold text-brand-deep shadow-card"
              :to="{ name: 'front-office.walk-ins' }"
            >
              Welcome a walk-in
            </RouterLink>
            <RouterLink
              class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white"
              :to="{ name: 'front-office.appointments' }"
            >
              Open appointments
            </RouterLink>
          </template>

          <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="rounded-control border border-white/10 bg-white/10 p-3">
              <strong class="block font-display text-2xl">{{ overview.appointments.today }}</strong>
              <span class="text-xs text-white/70">Appointments today</span>
            </div>
            <div class="rounded-control border border-white/10 bg-white/10 p-3">
              <strong class="block font-display text-2xl">{{ overview.queue.waiting }}</strong>
              <span class="text-xs text-white/70">Waiting now</span>
            </div>
            <div class="rounded-control border border-white/10 bg-white/10 p-3">
              <strong class="block font-display text-2xl">{{ overview.sessions.in_progress }}</strong>
              <span class="text-xs text-white/70">In service</span>
            </div>
            <div class="rounded-control border border-white/10 bg-white/10 p-3">
              <strong class="block font-display text-2xl">{{ overview.payments.receipts_ready_today }}</strong>
              <span class="text-xs text-white/70">Receipts ready today</span>
            </div>
          </div>
        </SvOperationalHero>

        <div class="mt-5 grid gap-4 lg:grid-cols-[1.4fr_0.6fr]">
          <SvCard
            as="section"
            padding="lg"
            aria-labelledby="today-flow-heading"
            class="overflow-hidden"
          >
            <div class="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
                  Live branch flow
                </p>
                <h2
                  id="today-flow-heading"
                  class="mt-1 font-display text-xl font-bold text-heading"
                >
                  Welcome → queue → service → billing
                </h2>
              </div>
              <RouterLink
                class="inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
                :to="{ name: 'front-office.activity' }"
              >
                View daily activity
              </RouterLink>
            </div>
            <ol class="mt-6 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
              <li class="rounded-control border border-sv-border bg-sv-surface-subtle p-4">
                <span class="text-xs font-bold text-text-muted">01 · Arrivals</span>
                <strong class="mt-2 block text-2xl text-heading">{{ overview.appointments.arrivals }}</strong>
                <span class="text-sm text-text-muted">checked in or queued</span>
              </li>
              <li class="rounded-control border border-sv-warning-border bg-sv-warning-bg p-4">
                <span class="text-xs font-bold text-sv-warning-fg">02 · Queue</span>
                <strong class="mt-2 block text-2xl text-heading">{{ overview.queue.active }}</strong>
                <span class="text-sm text-text-muted">active · longest estimate {{ overview.queue.longest_estimated_wait_minutes }} min</span>
              </li>
              <li class="rounded-control border border-sv-info-border bg-sv-info-bg p-4">
                <span class="text-xs font-bold text-sv-info-fg">03 · Service</span>
                <strong class="mt-2 block text-2xl text-heading">{{ overview.sessions.completed }}</strong>
                <span class="text-sm text-text-muted">completed today</span>
              </li>
              <li class="rounded-control border border-sv-success-border bg-sv-success-bg p-4">
                <span class="text-xs font-bold text-sv-success-fg">04 · Billing handoff</span>
                <strong class="mt-2 block text-2xl text-heading">{{ overview.payments.pending_validation }}</strong>
                <span class="text-sm text-text-muted">awaiting Finance</span>
              </li>
            </ol>
          </SvCard>

          <SvCard
            as="section"
            padding="lg"
            aria-labelledby="attention-heading"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-text-muted">
              Next safest action
            </p>
            <h2
              id="attention-heading"
              class="mt-1 font-display text-xl font-bold text-heading"
            >
              Work requiring attention
            </h2>
            <ul class="mt-4 divide-y divide-border">
              <li
                v-for="task in overview.tasks"
                :key="task.key"
                class="flex min-h-sv-touch items-center justify-between gap-3 py-3"
              >
                <span class="text-sm font-medium text-heading">{{ task.label }}</span>
                <RouterLink
                  class="sv-focus-ring inline-flex min-h-sv-touch min-w-sv-touch items-center justify-center rounded-control bg-sv-surface-subtle px-3 text-sm font-bold text-heading"
                  :aria-label="'Open ' + task.label + ': ' + task.count"
                  :to="{ name: task.route_name }"
                >
                  {{ task.count }}
                </RouterLink>
              </li>
            </ul>
          </SvCard>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-3">
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-brand"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Invoice desk
            </p>
            <h2 class="mt-1 font-display text-lg font-bold text-heading">
              {{ overview.invoices.drafts }} drafts
            </h2>
            <p class="mt-2 text-sm text-text-muted">
              {{ overview.invoices.awaiting_payment }} finalized invoices still await client payment.
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'front-office.invoices' }"
            >
              Open invoices
            </RouterLink>
          </SvCard>
          <SvCard
            as="article"
            class="border-t-4 border-t-sv-success-border"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
              Receipt readiness
            </p>
            <h2 class="mt-1 font-display text-lg font-bold text-heading">
              {{ overview.payments.receipts_ready_today }} ready today
            </h2>
            <p class="mt-2 text-sm text-text-muted">
              Original receipts appear only after Finance validates the payment group.
            </p>
            <RouterLink
              class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline"
              :to="{ name: 'front-office.payments-status' }"
            >
              Track payment status
            </RouterLink>
          </SvCard>
          <SvAlert
            severity="info"
            title="Subscription recovery remains gated"
            data-testid="front-office-wallet-gate"
          >
            {{ overview.subscription.reason }}
          </SvAlert>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
