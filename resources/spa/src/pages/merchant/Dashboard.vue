<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantDashboardStore } from '@/stores/merchantDashboardStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { formatMoney } from '@/utils/money';

const auth = useAuthStore();
const merchant = useMerchantStore();
const dashboard = useMerchantDashboardStore();

const state = computed(() => dashboard.loading ? 'loading' : dashboard.error ? 'error' : dashboard.overview ? 'success' : 'empty');
const overview = computed(() => dashboard.overview);

onMounted(() => { void dashboard.fetchOverview(); });
</script>

<template>
  <section class="mx-auto max-w-6xl" data-testid="merchant-dashboard">
    <SvPageHeader
      :title="`Welcome, ${auth.user?.name ?? 'Merchant Administrator'}`"
      eyebrow="Owner overview"
      :description="`${merchant.name ?? 'Your business'} — merchant-wide billing, branch, staff and compensation attention without operational superuser controls.`"
    >
      <template #actions>
        <RouterLink class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-semibold text-brand-deep" :to="{ name: 'merchant.branches' }">
          Review branches
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary class="mt-6" :state="state" :error-message="dashboard.error ?? undefined" empty-message="No owner overview is available." @retry="dashboard.fetchOverview()">
      <template v-if="overview">
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <SvCard as="article" class="border-t-4 border-t-sv-brand bg-sv-surface-warm" data-testid="dashboard-subscription">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Current plan</p>
            <h2 class="mt-1 font-display text-base font-bold text-heading">Subscription</h2>
            <template v-if="overview.subscription">
              <p class="mt-3 text-lg font-bold text-heading">{{ overview.subscription.plan_name }}</p>
              <p class="text-sm text-text-muted">{{ formatMoney(overview.subscription.amount_minor, overview.subscription.currency) }} / {{ overview.subscription.billing_interval }}</p>
              <p class="mt-2 text-sm text-text">Billing: {{ overview.subscription.billing_status }}</p>
              <p class="text-xs text-text-muted">Cycle ends {{ overview.subscription.current_period_end }}</p>
            </template>
            <p v-else class="mt-3 text-sm text-text-muted">No active subscription was returned.</p>
            <RouterLink class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline" :to="{ name: 'merchant.subscription' }">Open subscription</RouterLink>
          </SvCard>

          <SvCard as="article" class="border-t-4 border-t-sv-success-border" data-testid="dashboard-branches">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Business footprint</p>
            <h2 class="mt-1 font-display text-base font-bold text-heading">Branches</h2>
            <p class="mt-3 text-2xl font-bold text-heading">{{ overview.branches.active }} active</p>
            <p class="text-sm text-text-muted">{{ overview.branches.total }} total · {{ overview.branches.suspended }} suspended</p>
            <p class="mt-2 text-xs text-text-muted">Capacity: {{ overview.branches.limit === null ? 'Unlimited' : `${overview.branches.total} of ${overview.branches.limit}` }}</p>
            <RouterLink class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline" :to="{ name: 'merchant.branches' }">Manage branches</RouterLink>
          </SvCard>

          <SvCard as="article" class="border-t-4 border-t-sv-info-border" data-testid="dashboard-staff">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">People access</p>
            <h2 class="mt-1 font-display text-base font-bold text-heading">Staff access</h2>
            <p class="mt-3 text-2xl font-bold text-heading">{{ overview.staff.active }} active</p>
            <p class="text-sm text-text-muted">{{ overview.staff.invited }} invited · {{ overview.staff.suspended }} suspended</p>
            <p class="mt-2 text-xs text-text-muted">{{ overview.staff.pending_owner_invitations }} pending owner invitations</p>
            <RouterLink class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline" :to="{ name: 'merchant.staff' }">Review staff lifecycle</RouterLink>
          </SvCard>

          <SvCard as="article" class="border-t-4 border-t-sv-warning-border" data-testid="dashboard-compensation">
            <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Owner attention</p>
            <h2 class="mt-1 font-display text-base font-bold text-heading">Compensation</h2>
            <template v-if="overview.compensation">
              <p class="mt-3 text-2xl font-bold text-heading">{{ overview.compensation.pending_high_value_approvals }}</p>
              <p class="text-sm text-text-muted">high-value approvals waiting</p>
            </template>
            <p v-else class="mt-3 text-sm text-text-muted">Your permissions do not include the compensation summary.</p>
            <RouterLink class="mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline" :to="{ name: 'merchant.compensation' }">Open compensation</RouterLink>
          </SvCard>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
          <SvCard as="section" class="lg:min-h-64" data-testid="dashboard-billing-attention">
            <h2 class="font-display text-lg font-bold text-heading">Billing attention</h2>
            <div v-if="overview.billing.next_invoice" class="mt-3">
              <p class="text-sm font-semibold text-heading">{{ overview.billing.next_invoice.invoice_number ?? 'Current invoice' }}</p>
              <p class="text-sm text-text-muted">{{ formatMoney(overview.billing.next_invoice.balance_minor, overview.billing.next_invoice.currency) }} due · {{ overview.billing.next_invoice.status }}</p>
              <RouterLink class="mt-2 inline-flex min-h-sv-touch items-center text-sm font-semibold text-heading underline" :to="{ name: 'merchant.subscription-invoice-detail', params: { invoiceUlid: overview.billing.next_invoice.id } }">Review invoice</RouterLink>
            </div>
            <p v-else class="mt-3 text-sm text-text-muted">No outstanding subscription invoice needs attention.</p>
            <SvAlert severity="info" title="Payment initiation unavailable" class="mt-4">
              {{ overview.billing.payment_runtime.reason }}. No payment success or attempt state is fabricated here.
            </SvAlert>
          </SvCard>

          <SvCard as="section" class="lg:min-h-64" data-testid="dashboard-reporting-gate">
            <h2 class="font-display text-lg font-bold text-heading">Performance and daily reports</h2>
            <p class="mt-3 text-sm text-text-muted">
              Revenue, branch/service/staff performance and daily-report delivery are not displayed
              as zero. Their canonical reporting runtime is unavailable.
            </p>
            <p class="mt-3 rounded-control bg-surface-alt px-3 py-2 text-sm font-semibold text-text" role="note">{{ overview.reporting.reason }}</p>
          </SvCard>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>
