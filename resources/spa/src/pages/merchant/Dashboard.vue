<script setup lang="ts">
/**
 * Merchant Administrator Dashboard — Appendix A §6.4.2 (Phase UI-09; recomposed in UI-16).
 *
 * UI16-VIS-011: the owner dashboard was a row of four identical top-bordered cards over two equal
 * panels — the flattest of the eight account dashboards and the only one with no command surface.
 * It now opens on an owner hero that states the four facts an owner governs (plan, branches, staff,
 * owner approvals) and the two places an owner acts, then gives billing attention the dominant
 * column beside the owner's own routes, and shows the gated reporting surface as gated.
 *
 * Every figure is the server's `GET /merchant/dashboard` projection; nothing is summed here, no
 * operational control (services, pricing, staff assignment, payments) is offered, and a missing
 * section is stated as missing rather than shown as zero.
 */
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantDashboardStore } from '@/stores/merchantDashboardStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { formatMoney } from '@/utils/money';
import { statusLabel } from '@/utils/statusLabel';

const auth = useAuthStore();
const merchant = useMerchantStore();
const dashboard = useMerchantDashboardStore();

const state = computed(() => dashboard.loading ? 'loading' : dashboard.error ? 'error' : dashboard.overview ? 'success' : 'empty');
const overview = computed(() => dashboard.overview);

/** The server's gate sentence, with its own full stop removed so a second sentence can follow. */
function sentence(reason: string | null | undefined): string {
  return (reason ?? '').trim().replace(/\.+$/, '');
}

/** The owner's own destinations — every one is a live contract route this account holds. */
const ownerRoutes = [
  { to: 'merchant.merchant-profile', label: 'Merchant profile', hint: 'Business identity, logo and contact details' },
  { to: 'merchant.staff', label: 'Staff overview and lifecycle', hint: 'Owner invitations and staff access' },
  { to: 'merchant.compensation', label: 'Compensation summary', hint: 'Owner view of plans and payout runs' },
  { to: 'merchant.compensation-payout-approvals', label: 'High-value payout approvals', hint: 'Runs above the owner threshold' },
] as const;

onMounted(() => { void dashboard.fetchOverview(); });
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="merchant-dashboard"
  >
    <SvOperationalHero
      class="mb-6"
      eyebrow="Owner overview"
      :title="`Welcome, ${auth.user?.name ?? 'Merchant Administrator'}`"
      :description="`${merchant.name ?? 'Your business'} — merchant-wide billing, branch, staff and compensation attention without operational superuser controls.`"
      :context="merchant.name ?? undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-sv-brand px-4 py-2 text-sm font-semibold text-sv-text-on-brand hover:bg-sv-brand-hover"
          :to="{ name: 'merchant.branches' }"
        >
          Review branches
        </RouterLink>
        <RouterLink
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-white/25 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/15"
          :to="{ name: 'merchant.subscription' }"
        >
          Open subscription
        </RouterLink>
      </template>

      <div
        v-if="overview"
        class="grid gap-3 md:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1fr_1fr]"
      >
        <article
          class="rounded-card border border-white/15 bg-white/10 p-4"
          data-testid="dashboard-subscription"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
            Current plan
          </p>
          <h2 class="sr-only">
            Subscription
          </h2>
          <template v-if="overview.subscription">
            <p class="mt-1 font-display text-2xl font-extrabold leading-tight">
              {{ overview.subscription.plan_name }}
            </p>
            <p class="sv-numeric text-sm text-white/80">
              {{ formatMoney(overview.subscription.amount_minor, overview.subscription.currency) }} / {{ overview.subscription.billing_interval }}
            </p>
            <p class="mt-2 text-xs text-white/70">
              Billing: {{ overview.subscription.billing_status }} · cycle ends {{ overview.subscription.current_period_end }}
            </p>
          </template>
          <p
            v-else
            class="mt-1 text-sm text-white/80"
          >
            No active subscription was returned.
          </p>
        </article>

        <article
          class="rounded-card border border-white/10 bg-white/5 p-4"
          data-testid="dashboard-branches"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
            Branches
          </p>
          <p class="sv-numeric mt-1 font-display text-2xl font-extrabold">
            {{ overview.branches.active }} active
          </p>
          <p class="text-xs text-white/70">
            {{ overview.branches.total }} total · {{ overview.branches.suspended }} suspended · capacity {{ overview.branches.limit === null ? 'unlimited' : `${overview.branches.total} of ${overview.branches.limit}` }}
          </p>
        </article>

        <article
          class="rounded-card border border-white/10 bg-white/5 p-4"
          data-testid="dashboard-staff"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
            Staff access
          </p>
          <p class="sv-numeric mt-1 font-display text-2xl font-extrabold">
            {{ overview.staff.active }} active
          </p>
          <p class="text-xs text-white/70">
            {{ overview.staff.invited }} invited · {{ overview.staff.suspended }} suspended · {{ overview.staff.pending_owner_invitations }} pending owner invitations
          </p>
        </article>

        <article
          class="rounded-card border border-white/10 bg-white/5 p-4"
          data-testid="dashboard-compensation"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
            Owner approvals
          </p>
          <template v-if="overview.compensation">
            <p class="sv-numeric mt-1 font-display text-2xl font-extrabold">
              {{ overview.compensation.pending_high_value_approvals }}
            </p>
            <p class="text-xs text-white/70">
              high-value approvals waiting
            </p>
          </template>
          <p
            v-else
            class="mt-1 text-sm text-white/80"
          >
            Your permissions do not include the compensation summary.
          </p>
        </article>
      </div>
    </SvOperationalHero>

    <SvStateBoundary
      :state="state"
      :error-message="dashboard.error ?? undefined"
      empty-message="No owner overview is available."
      @retry="dashboard.fetchOverview()"
    >
      <template v-if="overview">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,0.7fr)]">
          <section
            class="sv-owner-billing rounded-card border border-sv-border p-5 shadow-card md:p-6"
            data-testid="dashboard-billing-attention"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
              Subscription billing
            </p>
            <h2 class="mt-1 font-display text-xl font-extrabold text-sv-text-heading">
              Billing attention
            </h2>

            <div
              v-if="overview.billing.next_invoice"
              class="mt-5 flex flex-col gap-4 rounded-card border border-sv-border bg-sv-surface-raised p-4 md:flex-row md:items-center md:justify-between"
            >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="font-semibold text-sv-text-heading">
                    {{ overview.billing.next_invoice.invoice_number ?? 'Current invoice' }}
                  </p>
                  <SvStatusBadge
                    size="sm"
                    :label="statusLabel(overview.billing.next_invoice.status)"
                    tone="info"
                  />
                </div>
                <p class="sv-numeric mt-1 font-display text-2xl font-extrabold text-sv-text-heading">
                  {{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }}
                </p>
                <p class="text-xs text-sv-text-muted">
                  Balance due
                </p>
              </div>
              <RouterLink
                class="sv-focus-ring inline-flex min-h-sv-touch shrink-0 items-center gap-1 self-start rounded-control border border-sv-border-strong px-4 py-2 text-sm font-semibold text-sv-text-heading hover:bg-sv-surface-subtle md:self-auto"
                :to="{ name: 'merchant.subscription-invoice-detail', params: { invoiceUlid: overview.billing.next_invoice.id } }"
              >
                Review invoice<SvIconChevronRight
                  aria-hidden="true"
                  class="h-4 w-4"
                />
              </RouterLink>
            </div>
            <p
              v-else
              class="mt-4 text-sm text-sv-text-muted"
            >
              No outstanding subscription invoice needs attention.
            </p>

            <!-- UI16-CONTENT-002: the server sentence already ends with a full stop. -->
            <SvAlert
              severity="info"
              title="Payment initiation unavailable"
              class="mt-4"
            >
              {{ sentence(overview.billing.payment_runtime.reason) }}. No payment success or attempt state is fabricated here.
            </SvAlert>
          </section>

          <nav
            aria-labelledby="owner-routes-heading"
            class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
          >
            <h2
              id="owner-routes-heading"
              class="font-display text-lg font-bold text-sv-text-heading"
            >
              Owner routes
            </h2>
            <ul class="mt-3 flex flex-col gap-1">
              <li
                v-for="item in ownerRoutes"
                :key="item.to"
              >
                <RouterLink
                  :to="{ name: item.to }"
                  class="sv-focus-ring group flex min-h-sv-touch items-center justify-between gap-3 rounded-control px-3 py-2.5 hover:bg-sv-surface-subtle"
                >
                  <span class="min-w-0">
                    <span class="block text-sm font-semibold text-sv-text-heading">{{ item.label }}</span>
                    <span class="block text-xs text-sv-text-muted">{{ item.hint }}</span>
                  </span>
                  <SvIconChevronRight
                    aria-hidden="true"
                    class="h-4 w-4 shrink-0 text-sv-text-muted transition-transform duration-sv-fast group-hover:translate-x-0.5"
                  />
                </RouterLink>
              </li>
            </ul>
          </nav>
        </div>

        <section
          class="mt-6 rounded-card border border-dashed border-sv-border-strong bg-sv-surface-subtle p-5"
          data-testid="dashboard-reporting-gate"
        >
          <h2 class="flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading">
            <SvIconLocked
              aria-hidden="true"
              class="h-5 w-5 text-sv-text-muted"
            />
            Performance and daily reports
          </h2>
          <p class="mt-2 max-w-3xl text-sm text-sv-text-muted">
            Revenue, branch/service/staff performance and daily-report delivery are not displayed
            as zero. Their canonical reporting runtime is unavailable.
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
.sv-owner-billing {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-accent) 18%, transparent), transparent 45%),
    var(--sv-color-surface-raised);
}
</style>
