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
import SvFeaturePanel from '@/components/refined/SvFeaturePanel.vue';
import SvKpiCard from '@/components/refined/SvKpiCard.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconChevronRight, SvIconLocked } from '@/design-system/icons';
import { navigationIcon } from '@/navigation/navigationIcons';
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
  { to: 'merchant.merchant-profile', icon: 'merchant', label: 'Merchant profile', hint: 'Business identity, logo and contact details' },
  { to: 'merchant.staff', icon: 'staff', label: 'Staff overview and lifecycle', hint: 'Owner invitations and staff access' },
  { to: 'merchant.compensation', icon: 'compensation', label: 'Compensation summary', hint: 'Owner view of plans and payout runs' },
  { to: 'merchant.compensation-payout-approvals', icon: 'payout', label: 'High-value payout approvals', hint: 'Runs above the owner threshold' },
] as const;

onMounted(() => { void dashboard.fetchOverview(); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the owner's asymmetric editorial dashboard.

    A greeting header, then subscription HEALTH on the dark feature surface (plan, billing state,
    cycle, and the next invoice with its one action) beside the owner's next-best-action routes;
    the portfolio band (branches, staff access, owner approvals) beneath; and the two truthfully
    unavailable runtimes — payment initiation and reporting — kept visible and inert. Every figure
    is the server's `GET /merchant/dashboard` projection; nothing is summed here.
  -->
  <section
    class="sv-r-page"
    data-testid="merchant-dashboard"
  >
    <SvTonalPageHeader
      eyebrow="Owner overview"
      :title="`Welcome, ${auth.user?.name ?? 'Merchant Administrator'}`"
      :description="`${merchant.name ?? 'Your business'} — merchant-wide billing, branch, staff and compensation attention without operational superuser controls.`"
      :context="merchant.name ?? undefined"
    >
      <template #actions>
        <RouterLink
          class="sv-r-action sv-focus-ring"
          :to="{ name: 'merchant.branches' }"
        >
          Review branches
        </RouterLink>
        <RouterLink
          class="sv-r-action sv-r-action--quiet sv-focus-ring"
          :to="{ name: 'merchant.subscription' }"
        >
          Open subscription
        </RouterLink>
      </template>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="dashboard.error ?? undefined"
      empty-message="No owner overview is available."
      @retry="dashboard.fetchOverview()"
    >
      <div
        v-if="overview"
        class="sv-r-stack"
      >
        <!-- Row 1: subscription health (feature) beside the owner's next best actions ---------- -->
        <div class="sv-r-grid sv-r-split-lead">
          <SvFeaturePanel
            eyebrow="Subscription health"
            title="Your Servana plan"
          >
            <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
              <article data-testid="dashboard-subscription">
                <h2 class="sr-only">
                  Subscription
                </h2>
                <p class="sv-r-feature__label">
                  Current plan
                </p>
                <template v-if="overview.subscription">
                  <p class="sv-r-feature__value sv-r-figure-xl mt-2">
                    {{ overview.subscription.plan_name }}
                  </p>
                  <p class="sv-r-feature__accent sv-numeric mt-2 text-base font-semibold">
                    {{ formatMoney(overview.subscription.amount_minor, overview.subscription.currency) }} / {{ overview.subscription.billing_interval }}
                  </p>
                  <span
                    aria-hidden="true"
                    class="sv-r-feature__rule mt-4 block"
                  />
                  <p class="sv-r-feature__hint mt-3">
                    Billing: {{ overview.subscription.billing_status }} · cycle ends {{ overview.subscription.current_period_end }}
                  </p>
                </template>
                <p
                  v-else
                  class="sv-r-feature__hint mt-2"
                >
                  No active subscription was returned.
                </p>
              </article>

              <div
                class="sv-r-feature__cell"
                data-testid="dashboard-billing-attention"
              >
                <p class="sv-r-feature__eyebrow">
                  Billing attention
                </p>
                <template v-if="overview.billing.next_invoice">
                  <div class="mt-2 flex flex-wrap items-center gap-2">
                    <p class="font-semibold">
                      {{ overview.billing.next_invoice.invoice_number ?? 'Current invoice' }}
                    </p>
                    <SvStatusBadge
                      size="sm"
                      :label="statusLabel(overview.billing.next_invoice.status)"
                      tone="info"
                    />
                  </div>
                  <p class="sv-r-feature__value mt-2 text-3xl">
                    {{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }}
                  </p>
                  <p class="sv-r-feature__hint">
                    Balance due
                  </p>
                  <RouterLink
                    class="sv-r-feature-action sv-focus-ring mt-4"
                    :to="{ name: 'merchant.subscription-invoice-detail', params: { invoiceUlid: overview.billing.next_invoice.id } }"
                  >
                    Review invoice<SvIconChevronRight
                      aria-hidden="true"
                      class="h-4 w-4"
                    />
                  </RouterLink>
                </template>
                <p
                  v-else
                  class="sv-r-feature__hint mt-2"
                >
                  No outstanding subscription invoice needs attention.
                </p>
                <!-- UI16-CONTENT-002: the server sentence already ends with a full stop. -->
                <p class="sv-r-feature__hint mt-4 border-t border-white/15 pt-3">
                  <span class="font-semibold text-white">Payment initiation unavailable.</span>
                  {{ sentence(overview.billing.payment_runtime.reason) }}. No payment success or attempt state is fabricated here.
                </p>
              </div>
            </div>
          </SvFeaturePanel>

          <SvPanel
            as="aside"
            eyebrow="Next best action"
            title="Owner routes"
            tone="warm"
          >
            <nav aria-label="Owner routes">
              <ul class="flex flex-col gap-2">
                <li
                  v-for="item in ownerRoutes"
                  :key="item.to"
                >
                  <RouterLink
                    :to="{ name: item.to }"
                    class="sv-r-queue__row sv-focus-ring group flex-nowrap"
                  >
                    <span
                      aria-hidden="true"
                      class="sv-r-icon sv-r-tone-warm"
                    >
                      <component :is="navigationIcon(item.icon)" />
                    </span>
                    <span class="sv-r-queue__label">
                      {{ item.label }}
                      <span class="sv-r-queue__detail">{{ item.hint }}</span>
                    </span>
                    <SvIconChevronRight
                      aria-hidden="true"
                      class="h-4 w-4 shrink-0 text-sv-text-muted transition-transform duration-sv-fast group-hover:translate-x-0.5"
                    />
                  </RouterLink>
                </li>
              </ul>
            </nav>
          </SvPanel>
        </div>

        <!-- Row 2: the portfolio band ---------------------------------------------------------- -->
        <div class="sv-r-grid sv-r-cols-3">
          <SvKpiCard
            label="Branches"
            tone="green"
            :icon="navigationIcon('branch')"
            :to="{ name: 'merchant.branches' }"
            link-label="Review branches"
            data-testid="dashboard-branches"
          >
            <span class="sv-numeric">{{ overview.branches.active }} active</span>
            <template #meta>
              <p class="sv-r-kpi__hint">
                {{ overview.branches.total }} total · {{ overview.branches.suspended }} suspended · capacity {{ overview.branches.limit === null ? 'unlimited' : `${overview.branches.total} of ${overview.branches.limit}` }}
              </p>
            </template>
          </SvKpiCard>

          <SvKpiCard
            label="Staff access"
            tone="teal"
            :icon="navigationIcon('staff')"
            :to="{ name: 'merchant.staff' }"
            link-label="Staff overview"
            data-testid="dashboard-staff"
          >
            <span class="sv-numeric">{{ overview.staff.active }} active</span>
            <template #meta>
              <p class="sv-r-kpi__hint">
                {{ overview.staff.invited }} invited · {{ overview.staff.suspended }} suspended · {{ overview.staff.pending_owner_invitations }} pending owner invitations
              </p>
            </template>
          </SvKpiCard>

          <SvKpiCard
            label="Owner approvals"
            tone="sun"
            :icon="navigationIcon('payout')"
            :to="overview.compensation ? { name: 'merchant.compensation-payout-approvals' } : null"
            link-label="Review approvals"
            data-testid="dashboard-compensation"
          >
            <template v-if="overview.compensation">
              <span class="sv-numeric">{{ overview.compensation.pending_high_value_approvals }}</span>
            </template>
            <span
              v-else
              class="text-base font-medium text-sv-text-muted"
            >Not in your permissions</span>
            <template #meta>
              <p class="sv-r-kpi__hint">
                {{ overview.compensation ? 'high-value approvals waiting' : 'Your permissions do not include the compensation summary.' }}
              </p>
            </template>
          </SvKpiCard>
        </div>

        <!-- Row 3: the gated reporting runtime, inert and exact -------------------------------- -->
        <section
          class="sv-r-panel sv-r-panel--dashed"
          data-testid="dashboard-reporting-gate"
        >
          <div class="flex flex-wrap items-start gap-4">
            <span
              aria-hidden="true"
              class="sv-r-icon sv-r-tone-neutral"
            >
              <SvIconLocked />
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="sv-r-title">
                Performance and daily reports
              </h2>
              <p class="sv-r-lede mt-1 max-w-3xl">
                Revenue, branch/service/staff performance and daily-report delivery are not displayed
                as zero. Their canonical reporting runtime is unavailable.
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
