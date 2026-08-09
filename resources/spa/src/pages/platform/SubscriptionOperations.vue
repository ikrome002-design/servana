<script setup lang="ts">
/**
 * Subscription Operations — Super Administrator contract page §5.4.13 (Phase UI-08).
 *
 * Platform-wide monitoring of merchant subscription lifecycles, issued invoices, billing credits
 * and overdue escalation (COR-UI08-001 §10).
 *
 * READ-ONLY BY CONTRACT. The delivered backend is seven GET operations — no table, no migration,
 * no mutation, no new permission key. This page therefore renders NO record-payment, mark-paid,
 * edit-invoice, override-subscription-state, create-credit or query-provider control. Those are
 * absent from the markup entirely rather than disabled: a disabled control still advertises a
 * capability the platform does not have, and Servana never records a subscription payment by hand
 * (Wallet by Citrus owns money-movement truth).
 *
 * An issued invoice is IMMUTABLE. What is displayed is the stored snapshot, never a recalculation.
 */
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvMetricCard from '@/components/ui/SvMetricCard.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { useCan } from '@/composables/useCan';
import {
  usePlatformSubscriptionOperationsStore,
  type BillingCreditRow,
  type EscalationRow,
  type OperationsTab,
  type PlatformSubscription,
  type PlatformSubscriptionInvoice,
} from '@/stores/platformSubscriptionOperationsStore';

const store = usePlatformSubscriptionOperationsStore();
const { can } = useCan();

const canView = computed(() => can('platform.merchant.view'));
// Conditional cross-links only — never a capability this page performs itself.
const canSeeReconciliation = computed(() => can('platform.billing_reconciliation.view'));
const canSeeAudit = computed(() => can('platform.audit.view'));

const TABS: { key: OperationsTab; label: string }[] = [
  { key: 'subscriptions', label: 'Subscriptions' },
  { key: 'invoices', label: 'Invoices' },
  { key: 'credits', label: 'Billing credits' },
  { key: 'escalations', label: 'Escalations' },
];

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';

  const rows =
    store.tab === 'subscriptions' ? store.subscriptions.length
      : store.tab === 'invoices' ? store.invoices.length
        : store.tab === 'credits' ? store.credits.length
          : store.escalations.length;

  return rows === 0 ? 'empty' : 'idle';
});

async function refresh(): Promise<void> {
  await store.loadSummary();
  await store.loadTab();
}

onMounted(() => {
  if (canView.value) void refresh();
});

async function selectTab(tab: OperationsTab): Promise<void> {
  await store.loadTab(tab);
}

async function applyStatus(value: string): Promise<void> {
  store.setFilter('status', value);
  await store.loadTab();
}

// ---------------------------------------------------------------------------------------------
// Detail drawers
// ---------------------------------------------------------------------------------------------

const subscriptionOpen = ref(false);
const invoiceOpen = ref(false);

async function openSubscription(row: PlatformSubscription): Promise<void> {
  await store.openSubscription(row.id);
  subscriptionOpen.value = true;
}

async function openInvoice(row: PlatformSubscriptionInvoice): Promise<void> {
  await store.openInvoice(row.id);
  invoiceOpen.value = true;
}

// ---------------------------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------------------------

const subscriptionColumns: SvColumn<PlatformSubscription>[] = [
  { key: 'merchant', label: 'Merchant', priority: 'primary', value: (r) => r.merchant.name },
  { key: 'plan', label: 'Plan', priority: 'secondary', value: (r) => r.plan.name },
  { key: 'status', label: 'Status', priority: 'primary' },
  { key: 'billing_interval', label: 'Interval', priority: 'secondary', value: (r) => r.billing_interval },
  { key: 'current_period_end', label: 'Next renewal', priority: 'secondary' },
  { key: 'trial_ends_at', label: 'Trial ends', priority: 'detail' },
  { key: 'explanation', label: 'Why this state', priority: 'detail', value: (r) => r.current_state.explanation },
];

const invoiceColumns: SvColumn<PlatformSubscriptionInvoice>[] = [
  { key: 'invoice_number', label: 'Invoice', priority: 'primary', value: (r) => r.invoice_number ?? 'Not yet numbered' },
  { key: 'merchant', label: 'Merchant', priority: 'primary', value: (r) => r.merchant.name },
  { key: 'status', label: 'Status', priority: 'primary' },
  { key: 'total', label: 'Total', priority: 'secondary', align: 'numeric' },
  { key: 'balance', label: 'Balance', priority: 'secondary', align: 'numeric' },
  { key: 'due_at', label: 'Due', priority: 'secondary' },
  { key: 'issued_at', label: 'Issued', priority: 'detail' },
];

const creditColumns: SvColumn<BillingCreditRow>[] = [
  { key: 'merchant', label: 'Merchant', priority: 'primary', value: (r) => r.merchant.name },
  { key: 'description', label: 'Description', priority: 'primary', value: (r) => r.description },
  { key: 'type', label: 'Type', priority: 'secondary', value: (r) => r.type },
  { key: 'amount', label: 'Amount', priority: 'primary', align: 'numeric' },
  { key: 'created_at', label: 'Recorded', priority: 'detail' },
];

const escalationColumns: SvColumn<EscalationRow>[] = [
  { key: 'merchant', label: 'Merchant', priority: 'primary', value: (r) => r.merchant.name },
  { key: 'event_type', label: 'Event', priority: 'primary', value: (r) => r.event_type },
  { key: 'transition', label: 'Billing status', priority: 'secondary', value: (r) => `${r.from_billing_status} to ${r.to_billing_status}` },
  { key: 'created_at', label: 'When', priority: 'secondary' },
  { key: 'reason', label: 'Reason', priority: 'detail', value: (r) => r.reason },
];

const statusTone = (status: string): 'success' | 'warning' | 'error' | 'info' | 'neutral' => {
  if (status === 'active' || status === 'paid') return 'success';
  if (status === 'trialing' || status === 'issued') return 'info';
  if (status === 'overdue' || status === 'suspended_billing') return 'error';
  if (status === 'in_grace' || status === 'pending_payment' || status === 'partially_paid') return 'warning';
  return 'neutral';
};
</script>

<template>
  <div
    class="mx-auto w-full max-w-6xl"
    data-testid="subscription-operations-screen"
  >
    <SvPageHeader
      title="Subscription operations"
      eyebrow="Billing operations"
      description="Monitor merchant subscription lifecycles, issued invoices, billing credits and overdue escalation across the platform. This screen is read-only: subscription state is changed by the billing lifecycle, and issued invoices are immutable."
    />

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="mb-6 text-xs text-sv-text-muted"
        data-testid="subscriptions-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load subscription operations"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="subscriptions-retry"
          @click="refresh"
        >
          Try again
        </SvButton>
      </SvAlert>

      <!-- Summary ------------------------------------------------------------------------- -->
      <section
        v-if="store.summary"
        aria-labelledby="subs-summary-heading"
        class="mb-8"
      >
        <h2
          id="subs-summary-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Platform summary
        </h2>

        <div
          class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
          data-testid="subscriptions-summary"
        >
          <SvMetricCard
            label="Subscriptions"
            context="Every merchant subscription, in any lifecycle state."
          >
            <span class="sv-numeric text-2xl font-bold">{{ store.summary.totals.subscriptions }}</span>
          </SvMetricCard>
          <SvMetricCard
            label="Invoices"
            context="Issued platform subscription invoices."
          >
            <span class="sv-numeric text-2xl font-bold">{{ store.summary.totals.invoices }}</span>
          </SvMetricCard>
          <SvMetricCard
            label="Open invoice balance"
            context="Outstanding balance across unpaid issued invoices."
            :increase-is-positive="false"
          >
            <SvMoney
              :minor-units="store.summary.totals.open_invoice_balance_minor"
              size="lg"
            />
          </SvMetricCard>
          <SvMetricCard
            label="As of"
            context="The instant this projection was computed."
          >
            <SvDateTime :value="store.summary.as_of" />
          </SvMetricCard>
        </div>

        <details class="mt-4 rounded-card border border-sv-border bg-sv-surface-raised p-4">
          <summary class="cursor-pointer text-sm font-medium text-sv-text">
            How each figure is defined
          </summary>
          <dl
            v-if="store.definitions"
            class="mt-3 space-y-2 text-sm"
          >
            <div>
              <dt class="font-medium text-sv-text-muted">
                Subscriptions by status
              </dt>
              <dd class="text-sv-text">
                {{ store.definitions.definitions.subscriptions_by_status }}
              </dd>
            </div>
            <div>
              <dt class="font-medium text-sv-text-muted">
                Open invoice balance
              </dt>
              <dd class="text-sv-text">
                {{ store.definitions.definitions.open_invoice_balance_minor }}
              </dd>
            </div>
            <div>
              <dt class="font-medium text-sv-text-muted">
                Time range
              </dt>
              <dd class="text-sv-text">
                {{ store.definitions.time_range }}
              </dd>
            </div>
          </dl>
        </details>
      </section>

      <!-- Tabs ---------------------------------------------------------------------------- -->
      <div
        role="tablist"
        aria-label="Subscription operations"
        class="mb-4 flex flex-wrap gap-1 border-b border-sv-border"
      >
        <button
          v-for="item in TABS"
          :id="`subs-tab-${item.key}`"
          :key="item.key"
          type="button"
          role="tab"
          :aria-selected="store.tab === item.key"
          :aria-controls="`subs-panel-${item.key}`"
          :tabindex="store.tab === item.key ? 0 : -1"
          class="sv-focus-ring min-h-sv-touch rounded-t-control px-4 py-2 text-sm font-semibold"
          :class="store.tab === item.key
            ? 'border-b-2 border-sv-border-strong text-sv-text-heading'
            : 'text-sv-text-muted hover:text-sv-text'"
          :data-testid="`subs-tab-${item.key}`"
          @click="selectTab(item.key)"
        >
          {{ item.label }}
        </button>
      </div>

      <div class="mb-4 max-w-xs">
        <SvSelect
          id="subs-status-filter"
          :model-value="store.filters.status"
          label="Filter by status"
          :options="[
            { value: '', label: 'All statuses' },
            { value: 'trialing', label: 'Trialing' },
            { value: 'active', label: 'Active' },
            { value: 'in_grace', label: 'In grace' },
            { value: 'overdue', label: 'Overdue' },
            { value: 'suspended_billing', label: 'Suspended for billing' },
            { value: 'cancelled', label: 'Cancelled' },
          ]"
          @update:model-value="applyStatus"
        />
      </div>

      <div
        :id="`subs-panel-${store.tab}`"
        role="tabpanel"
        :aria-labelledby="`subs-tab-${store.tab}`"
        tabindex="0"
        class="focus-visible:outline-none"
      >
        <!-- Subscriptions ----------------------------------------------------------------- -->
        <template v-if="store.tab === 'subscriptions'">
          <div class="hidden md:block">
            <SvDataTable
              :columns="subscriptionColumns"
              :rows="store.subscriptions"
              :row-key="(r) => r.id"
              caption="Merchant subscriptions"
              :state="dataState"
              empty-message="No subscription matches these filters."
              @retry="refresh"
            >
              <template #cell:status="{ row }">
                <SvStatusBadge
                  :label="row.status"
                  :tone="statusTone(row.status)"
                  sr-prefix="Status:"
                />
              </template>
              <template #cell:current_period_end="{ row }">
                <SvDateTime
                  :value="row.current_period_end"
                  mode="date"
                />
              </template>
              <template #cell:trial_ends_at="{ row }">
                <SvDateTime
                  :value="row.trial_ends_at"
                  mode="date"
                />
              </template>
            </SvDataTable>
          </div>
          <div class="md:hidden">
            <SvResponsiveRecordList
              :columns="subscriptionColumns"
              :rows="store.subscriptions"
              :row-key="(r) => r.id"
              caption="Merchant subscriptions"
              :state="dataState"
              empty-message="No subscription matches these filters."
              @retry="refresh"
            >
              <template #cell:status="{ row }">
                <SvStatusBadge
                  :label="row.status"
                  :tone="statusTone(row.status)"
                  sr-prefix="Status:"
                />
              </template>
              <template #actions="{ row }">
                <SvButton
                  variant="secondary"
                  size="sm"
                  @click="openSubscription(row)"
                >
                  Open subscription
                </SvButton>
              </template>
            </SvResponsiveRecordList>
          </div>
        </template>

        <!-- Invoices ---------------------------------------------------------------------- -->
        <template v-else-if="store.tab === 'invoices'">
          <div class="hidden md:block">
            <SvDataTable
              :columns="invoiceColumns"
              :rows="store.invoices"
              :row-key="(r) => r.id"
              caption="Platform subscription invoices"
              :state="dataState"
              empty-message="No invoice matches these filters."
              @retry="refresh"
            >
              <template #cell:status="{ row }">
                <SvStatusBadge
                  :label="row.status"
                  :tone="statusTone(row.status)"
                  sr-prefix="Status:"
                />
              </template>
              <template #cell:total="{ row }">
                <SvMoney
                  :minor-units="row.total.amount"
                  :currency="row.total.currency"
                  :formatted="row.total.formatted"
                />
              </template>
              <template #cell:balance="{ row }">
                <SvMoney
                  :minor-units="row.balance.amount"
                  :currency="row.balance.currency"
                  :formatted="row.balance.formatted"
                />
              </template>
              <template #cell:due_at="{ row }">
                <SvDateTime
                  :value="row.due_at"
                  mode="date"
                />
              </template>
              <template #cell:issued_at="{ row }">
                <SvDateTime :value="row.issued_at" />
              </template>
            </SvDataTable>
          </div>
          <div class="md:hidden">
            <SvResponsiveRecordList
              :columns="invoiceColumns"
              :rows="store.invoices"
              :row-key="(r) => r.id"
              caption="Platform subscription invoices"
              :state="dataState"
              empty-message="No invoice matches these filters."
              @retry="refresh"
            >
              <template #actions="{ row }">
                <SvButton
                  variant="secondary"
                  size="sm"
                  @click="openInvoice(row)"
                >
                  Open invoice
                </SvButton>
              </template>
            </SvResponsiveRecordList>
          </div>
        </template>

        <!-- Credits ----------------------------------------------------------------------- -->
        <template v-else-if="store.tab === 'credits'">
          <p class="mb-3 max-w-sv-readable text-sm text-sv-text-muted">
            Servana holds no credit ledger. These are invoice LINES with a negative amount,
            projected from the issued invoices themselves.
          </p>
          <div class="hidden md:block">
            <SvDataTable
              :columns="creditColumns"
              :rows="store.credits"
              :row-key="(r) => r.id"
              caption="Billing credits applied to platform invoices"
              :state="dataState"
              empty-message="No billing credit has been applied."
              @retry="refresh"
            >
              <template #cell:amount="{ row }">
                <SvMoney
                  :formatted="row.amount.formatted"
                  :currency="row.amount.currency"
                  signed
                />
              </template>
              <template #cell:created_at="{ row }">
                <SvDateTime :value="row.created_at" />
              </template>
            </SvDataTable>
          </div>
          <div class="md:hidden">
            <SvResponsiveRecordList
              :columns="creditColumns"
              :rows="store.credits"
              :row-key="(r) => r.id"
              caption="Billing credits applied to platform invoices"
              :state="dataState"
              empty-message="No billing credit has been applied."
              @retry="refresh"
            />
          </div>
        </template>

        <!-- Escalations ------------------------------------------------------------------- -->
        <template v-else>
          <div class="hidden md:block">
            <SvDataTable
              :columns="escalationColumns"
              :rows="store.escalations"
              :row-key="(r) => r.id"
              caption="Billing escalation timeline"
              :state="dataState"
              empty-message="No escalation has been recorded."
              @retry="refresh"
            >
              <template #cell:created_at="{ row }">
                <SvDateTime :value="row.created_at" />
              </template>
            </SvDataTable>
          </div>
          <div class="md:hidden">
            <SvResponsiveRecordList
              :columns="escalationColumns"
              :rows="store.escalations"
              :row-key="(r) => r.id"
              caption="Billing escalation timeline"
              :state="dataState"
              empty-message="No escalation has been recorded."
              @retry="refresh"
            />
          </div>
        </template>
      </div>

      <p
        v-if="canSeeReconciliation || canSeeAudit"
        class="mt-6 text-xs text-sv-text-muted"
        data-testid="subs-related-links"
      >
        Related governance evidence is available on the pages you are permitted to open.
      </p>
    </template>

    <!-- Subscription detail ---------------------------------------------------------------- -->
    <SvDialog
      :open="subscriptionOpen"
      title="Subscription detail"
      size="lg"
      @close="subscriptionOpen = false"
    >
      <dl
        v-if="store.selectedSubscription"
        class="space-y-3 text-sm"
        data-testid="subscription-detail"
      >
        <div>
          <dt class="font-medium text-sv-text-muted">
            Merchant
          </dt>
          <dd>{{ store.selectedSubscription.merchant.name }}</dd>
        </div>
        <div>
          <dt class="font-medium text-sv-text-muted">
            Plan and interval
          </dt>
          <dd>{{ store.selectedSubscription.plan.name }} · {{ store.selectedSubscription.billing_interval }}</dd>
        </div>
        <div>
          <dt class="font-medium text-sv-text-muted">
            Current state
          </dt>
          <dd>
            <SvStatusBadge
              :label="store.selectedSubscription.status"
              :tone="statusTone(store.selectedSubscription.status)"
              sr-prefix="Status:"
            />
            <p class="mt-1 text-sv-text-muted">
              {{ store.selectedSubscription.current_state.explanation }}
            </p>
          </dd>
        </div>
        <div>
          <dt class="font-medium text-sv-text-muted">
            Next renewal
          </dt>
          <dd>
            <SvDateTime
              :value="store.selectedSubscription.current_period_end"
              mode="date"
            />
          </dd>
        </div>
      </dl>

      <template #footer>
        <SvButton
          variant="secondary"
          @click="subscriptionOpen = false"
        >
          Close
        </SvButton>
      </template>
    </SvDialog>

    <!-- Invoice detail ---------------------------------------------------------------------- -->
    <SvDialog
      :open="invoiceOpen"
      title="Invoice detail"
      size="lg"
      description="The stored invoice snapshot. Issued invoices are immutable and are never recalculated for display."
      @close="invoiceOpen = false"
    >
      <dl
        v-if="store.selectedInvoice"
        class="space-y-3 text-sm"
        data-testid="invoice-detail"
      >
        <div>
          <dt class="font-medium text-sv-text-muted">
            Invoice number
          </dt>
          <dd>{{ store.selectedInvoice.invoice_number ?? 'Not yet numbered' }}</dd>
        </div>
        <div>
          <dt class="font-medium text-sv-text-muted">
            Subtotal, discount and total
          </dt>
          <dd class="flex flex-wrap gap-3">
            <SvMoney
              :minor-units="store.selectedInvoice.subtotal.amount"
              :currency="store.selectedInvoice.subtotal.currency"
              :formatted="store.selectedInvoice.subtotal.formatted"
            />
            <SvMoney
              :minor-units="store.selectedInvoice.discount.amount"
              :currency="store.selectedInvoice.discount.currency"
              :formatted="store.selectedInvoice.discount.formatted"
              signed
            />
            <SvMoney
              :minor-units="store.selectedInvoice.total.amount"
              :currency="store.selectedInvoice.total.currency"
              :formatted="store.selectedInvoice.total.formatted"
            />
          </dd>
        </div>
        <div>
          <dt class="font-medium text-sv-text-muted">
            Snapshot note
          </dt>
          <dd>{{ store.selectedInvoice.snapshot_note }}</dd>
        </div>
      </dl>

      <template #footer>
        <SvButton
          variant="secondary"
          @click="invoiceOpen = false"
        >
          Close
        </SvButton>
      </template>
    </SvDialog>
  </div>
</template>
