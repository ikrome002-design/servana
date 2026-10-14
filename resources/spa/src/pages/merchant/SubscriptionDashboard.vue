<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvPanel from '@/components/refined/SvPanel.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCan } from '@/composables/useCan';
import { useSubscriptionInvoiceStore } from '@/stores/subscriptionInvoiceStore';
import { useSubscriptionStore } from '@/stores/subscriptionStore';
import { formatMoney } from '@/utils/money';

/**
 * Subscription dashboard (Plan §22, §48, §49; Phase 20B). Merchant Administrator read of the
 * subscription lifecycle status + the INDEPENDENT merchant billing status, current plan/price,
 * trial/current-period dates, the pending scheduled change, and the latest invoice. In billing
 * read-only states (`read_only_grace`/`suspended_billing`) a plain-language explanation is shown and
 * mutation entry points are removed — the backend remains authoritative.
 */
const store = useSubscriptionStore();
const invoiceStore = useSubscriptionInvoiceStore();
const { can } = useCan();

const canView = computed(() => can('merchant.subscription.view'));

const SUBSCRIPTION_STATUS_LABELS: Record<string, string> = {
  trialing: 'Trialing',
  active: 'Active',
  read_only_grace: 'Read-only grace',
  overdue: 'Overdue',
  suspended_billing: 'Suspended (billing)',
  cancelled: 'Cancelled',
  expired: 'Expired',
};

const BILLING_STATUS_LABELS: Record<string, string> = {
  trialing: 'Trialing',
  active: 'Active',
  overdue: 'Overdue',
  read_only_grace: 'Read-only grace',
  suspended_billing: 'Suspended',
};

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.subscription === null) return 'empty';
  return 'success';
});

const sub = computed(() => store.subscription);
const latestInvoice = computed(() => invoiceStore.invoices[0] ?? null);
const billingReadOnly = computed(() => sub.value?.billing_read_only === true);

function label(map: Record<string, string>, key: string | undefined): string {
  return key === undefined ? '—' : (map[key] ?? key);
}

function dateOnly(iso: string | null | undefined): string {
  if (iso === null || iso === undefined) return '—';
  return iso.slice(0, 10);
}

onMounted(async () => {
  if (!canView.value) return;
  await store.fetchSubscription();
  try {
    await invoiceStore.fetchInvoices();
  } catch {
    // The dashboard still renders without the latest-invoice summary.
  }
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a polished billing overview (the Billings reference, in Servana terms).
    "Subscription overview" pairs the CURRENT PLAN (its pill, price and both state chips, with the
    plan entry point) with the two BILLING PERIODS drawn as dated bands; beneath, the scheduled
    change and the latest invoice sit side by side. Every value is the server's; no state is
    inferred and no payment control exists here.
  -->
  <div class="sv-r-page sv-r-stack">
    <SvPageHeader
      class="!mb-0"
      title="Subscription and billing"
      eyebrow="Account plan"
      description="See the plan that powers your merchant account, its billing position and the next action that needs owner attention."
    >
      <template
        v-if="canView && sub"
        #actions
      >
        <SvButton
          variant="secondary"
          @click="store.fetchSubscription()"
        >
          Refresh subscription
        </SvButton>
      </template>
    </SvPageHeader>

    <p
      v-if="!canView"
      class="rounded-control bg-surface-alt px-4 py-3 text-sm text-text-muted"
      role="note"
    >
      You do not have access to subscription details.
    </p>

    <SvStateBoundary
      v-else
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No subscription was found for your account."
      @retry="store.fetchSubscription()"
    >
      <div
        v-if="sub"
        class="sv-r-stack"
      >
        <!-- Billing read-only explanation -->
        <div
          v-if="billingReadOnly"
          class="sv-r-record sv-r-record--spine sv-r-tone-sun pl-6 text-sm text-text"
          role="status"
        >
          <p class="font-semibold">
            Billing is in read-only mode.
          </p>
          <p class="mt-1 text-text-muted">
            While your account is in
            <strong>{{ label(SUBSCRIPTION_STATUS_LABELS, sub.status) }}</strong>, you can still view
            your subscription and download existing invoices, but plan changes and new invoice PDFs
            are paused until billing is brought up to date.
          </p>
        </div>

        <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
          Subscription overview
        </p>
        <div class="sv-r-grid sv-r-split-even -mt-3">
          <!-- Current plan -->
          <section
            class="sv-r-panel sv-r-panel--tonal sv-r-tone-warm flex flex-col"
            aria-labelledby="sub-current-plan"
          >
            <p class="sv-r-kpi__label">
              Current plan
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <h2
                id="sub-current-plan"
                class="sv-r-chip sv-r-chip--tone sv-r-tone-green !text-sm"
              >
                {{ sub.plan?.name ?? 'Not available' }}
              </h2>
            </div>
            <p class="sv-r-figure mt-3">
              {{ formatMoney(sub.price.amount_minor, sub.price.currency) }}
              <span class="text-base font-medium text-text-muted">/ {{ sub.billing_interval }}</span>
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
              <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-green">
                <span data-testid="subscription-status">{{ label(SUBSCRIPTION_STATUS_LABELS, sub.status) }}</span>
                subscription
              </span>
              <span class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-teal">
                <span data-testid="billing-status">{{ label(BILLING_STATUS_LABELS, sub.billing_status) }}</span>
                billing
              </span>
            </div>
            <div
              v-if="can('merchant.subscription.plan_change')"
              class="mt-auto pt-5"
            >
              <RouterLink
                :to="{ name: 'merchant.subscription-plan' }"
                class="sv-r-action sv-r-action--dark sv-focus-ring"
              >
                Manage plan
              </RouterLink>
            </div>
          </section>

          <!-- Billing periods -->
          <section
            class="sv-r-panel"
            aria-labelledby="sub-periods"
          >
            <h2
              id="sub-periods"
              class="sv-r-kpi__label"
            >
              Billing periods
            </h2>
            <dl class="mt-4 flex flex-col gap-5">
              <div>
                <dt class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Trial period
                </dt>
                <dd class="mt-2">
                  <span
                    aria-hidden="true"
                    class="relative mx-1.5 block h-0.5 bg-sv-border-strong"
                  ><span class="absolute -left-1.5 -top-1 h-2.5 w-2.5 rounded-full bg-sv-accent" /><span class="absolute -right-1.5 -top-1 h-2.5 w-2.5 rounded-full bg-sv-accent" /></span>
                  <span class="mt-2 flex justify-between text-sm font-medium text-text">
                    <span>{{ dateOnly(sub.trial_started_at) }}</span>
                    <span class="sr-only"> → </span>
                    <span>{{ dateOnly(sub.trial_ends_at) }}</span>
                  </span>
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Current billing period
                </dt>
                <dd class="mt-2">
                  <span
                    aria-hidden="true"
                    class="relative mx-1.5 block h-0.5 bg-sv-border-strong"
                  ><span class="absolute -left-1.5 -top-1 h-2.5 w-2.5 rounded-full bg-sv-brand-secondary" /><span class="absolute -right-1.5 -top-1 h-2.5 w-2.5 rounded-full bg-sv-brand-secondary" /></span>
                  <span class="mt-2 flex justify-between text-sm font-medium text-text">
                    <span>{{ sub.current_period_start }}</span>
                    <span class="sr-only"> → </span>
                    <span>{{ sub.current_period_end }}</span>
                  </span>
                </dd>
              </div>
            </dl>
          </section>
        </div>

        <div class="sv-r-grid sv-r-split-even">
          <!-- Scheduled plan change -->
          <SvPanel
            eyebrow="Next cycle"
            title="Scheduled plan change"
            tone="sun"
          >
            <p
              v-if="!sub.scheduled_plan_change"
              class="sv-r-lede"
              data-testid="no-scheduled-change"
            >
              No plan change is scheduled. Changes take effect at the next billing cycle with no
              proration.
            </p>
            <p
              v-else
              class="sv-r-lede"
              data-testid="scheduled-change-summary"
            >
              Changing to <strong>{{ sub.scheduled_plan_change?.target_plan?.name ?? 'Not available' }}</strong>
              ({{ formatMoney(sub.scheduled_plan_change.target_price.amount_minor, sub.scheduled_plan_change.target_price.currency) }})
              on <strong>{{ sub.scheduled_plan_change.effective_at }}</strong>.
            </p>
          </SvPanel>

          <!-- Latest invoice -->
          <SvPanel
            eyebrow="Billing history"
            title="Latest invoice"
            tone="teal"
          >
            <template
              v-if="can('merchant.subscription.invoice.view')"
              #actions
            >
              <RouterLink
                :to="{ name: 'merchant.subscription-invoices' }"
                class="sv-r-link sv-focus-ring rounded-control"
              >
                All invoices
              </RouterLink>
            </template>
            <p
              v-if="latestInvoice === null"
              class="sv-r-lede"
            >
              No invoices have been issued yet.
            </p>
            <div
              v-else
              class="text-sm text-text"
              data-testid="latest-invoice"
            >
              <p>
                <strong>{{ latestInvoice.invoice_number ?? 'Draft' }}</strong> ·
                {{ formatMoney(latestInvoice.total_minor, latestInvoice.currency) }} ·
                {{ latestInvoice.status }}
              </p>
              <p
                v-if="latestInvoice.payment_reference_pending"
                class="mt-1 text-text-muted"
              >
                Payment reference pending — see your billing dashboard
              </p>
            </div>
          </SvPanel>
        </div>
      </div>
    </SvStateBoundary>
  </div>
</template>