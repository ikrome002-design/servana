<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <div class="mx-auto flex max-w-4xl flex-col gap-6">
    <header>
      <h1 class="font-display text-2xl font-bold text-heading">
        Subscription and billing
      </h1>
      <p class="mt-1 text-sm text-text-muted">
        Your Servana subscription, current plan and billing status.
      </p>
    </header>

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
        class="flex flex-col gap-6"
      >
        <!-- Billing read-only explanation -->
        <div
          v-if="billingReadOnly"
          class="rounded-control border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-text"
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

        <!-- Status summary -->
        <SvCard>
          <dl class="grid gap-4 sm:grid-cols-2">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Subscription status
              </dt>
              <dd
                class="mt-1 text-base font-semibold text-heading"
                data-testid="subscription-status"
              >
                {{ label(SUBSCRIPTION_STATUS_LABELS, sub.status) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Billing status
              </dt>
              <dd
                class="mt-1 text-base font-semibold text-heading"
                data-testid="billing-status"
              >
                {{ label(BILLING_STATUS_LABELS, sub.billing_status) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Current plan
              </dt>
              <dd class="mt-1 text-base text-text">
                {{ sub.plan.name }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Price
              </dt>
              <dd class="mt-1 text-base text-text">
                {{ formatMoney(sub.price.amount_minor, sub.price.currency) }}
                <span class="text-text-muted">/ {{ sub.billing_interval }}</span>
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Trial period
              </dt>
              <dd class="mt-1 text-base text-text">
                {{ dateOnly(sub.trial_started_at) }} → {{ dateOnly(sub.trial_ends_at) }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Current period
              </dt>
              <dd class="mt-1 text-base text-text">
                {{ sub.current_period_start }} → {{ sub.current_period_end }}
              </dd>
            </div>
          </dl>
        </SvCard>

        <!-- Scheduled plan change -->
        <SvCard>
          <h2 class="font-display text-lg font-bold text-heading">
            Scheduled plan change
          </h2>
          <p
            v-if="!sub.scheduled_plan_change"
            class="mt-2 text-sm text-text-muted"
            data-testid="no-scheduled-change"
          >
            No plan change is scheduled. Changes take effect at the next billing cycle with no
            proration.
          </p>
          <p
            v-else
            class="mt-2 text-sm text-text"
            data-testid="scheduled-change-summary"
          >
            Changing to <strong>{{ sub.scheduled_plan_change.target_plan.name }}</strong>
            ({{ formatMoney(sub.scheduled_plan_change.target_price.amount_minor, sub.scheduled_plan_change.target_price.currency) }})
            on <strong>{{ sub.scheduled_plan_change.effective_at }}</strong>.
          </p>
          <div
            v-if="can('merchant.subscription.plan_change')"
            class="mt-4"
          >
            <RouterLink
              :to="{ name: 'merchant.plan' }"
              class="text-sm font-semibold text-heading underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
              Manage plan
            </RouterLink>
          </div>
        </SvCard>

        <!-- Latest invoice -->
        <SvCard>
          <div class="flex items-center justify-between gap-4">
            <h2 class="font-display text-lg font-bold text-heading">
              Latest invoice
            </h2>
            <RouterLink
              v-if="can('merchant.subscription.invoice.view')"
              :to="{ name: 'merchant.invoices' }"
              class="text-sm font-semibold text-heading underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
              All invoices
            </RouterLink>
          </div>
          <p
            v-if="latestInvoice === null"
            class="mt-2 text-sm text-text-muted"
          >
            No invoices have been issued yet.
          </p>
          <div
            v-else
            class="mt-2 text-sm text-text"
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
        </SvCard>

        <SvButton
          variant="secondary"
          @click="store.fetchSubscription()"
        >
          Refresh
        </SvButton>
      </div>
    </SvStateBoundary>
  </div>
</template>
