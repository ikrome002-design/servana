<script setup lang="ts">
/**
 * SMS Billing Settings — Super Administrator contract page §5.4.9 (Phase UI-08).
 *
 * Configures how in-platform personnel SMS usage is PRICED and added to branch/merchant billing.
 * The versioned `platform_sms_billing_rules` table is the authority (COR-UI08-001 §9); deployment
 * config is only the genesis bootstrap, and the page never computes a price itself.
 *
 * PRIVACY BOUNDARY (UI/UX plan §5.4.9, binding). Nothing on this page is a recipient list, a phone
 * number, a message body, a provider credential or callback data. That is not enforced by hiding
 * anything — the seven endpoints behind it return only aggregates and configuration rows, so the
 * data never reaches the browser at all.
 *
 * IMMUTABILITY. Scheduling a new price NEVER re-prices recorded usage: a version supersedes, it
 * does not rewrite. The page says so where a user could otherwise assume otherwise.
 */
import { computed, onMounted, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvDatePicker from '@/components/ui/SvDatePicker.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvMetricCard from '@/components/ui/SvMetricCard.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvMoneyInput from '@/components/ui/SvMoneyInput.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvPermissionState from '@/components/ui/SvPermissionState.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import type { SvColumn, SvDataState } from '@/components/ui/dataContract';
import { useCan } from '@/composables/useCan';
import {
  usePlatformSmsBillingStore,
  type SmsBillingRule,
  type SmsUsageRow,
} from '@/stores/platformSmsBillingStore';

const store = usePlatformSmsBillingStore();
const { can } = useCan();

const canView = computed(() => can('platform.billing_settings.view'));
const canUpdate = computed(() => can('platform.billing_settings.update'));

// ---------------------------------------------------------------------------------------------
// Loading / state
// ---------------------------------------------------------------------------------------------

const dataState = computed<SvDataState>(() => {
  if (!canView.value) return 'forbidden';
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return 'idle';
});

const versionsState = computed<SvDataState>(() => {
  if (store.loading) return 'loading';
  if (store.error !== null) return 'error';
  return store.versions.length === 0 ? 'empty' : 'idle';
});

const usageState = computed<SvDataState>(() => {
  if (store.error !== null) return 'error';
  return store.usage.length === 0 ? 'empty' : 'idle';
});

async function refresh(): Promise<void> {
  await store.load();
  await store.loadUsage();
}

onMounted(() => {
  if (canView.value) void refresh();
});

// ---------------------------------------------------------------------------------------------
// Schedule dialog
// ---------------------------------------------------------------------------------------------

const scheduleOpen = ref(false);
const scheduleSubmitting = ref(false);
const scheduleError = ref<string | null>(null);
const unitCostMinor = ref<number | null>(null);
const effectiveFrom = ref('');
const taxBasisPoints = ref('');
const warningThreshold = ref('');
const anomalyBasisPoints = ref('');
const scheduleReason = ref('');

function openSchedule(): void {
  scheduleError.value = null;
  unitCostMinor.value = null;
  effectiveFrom.value = '';
  taxBasisPoints.value = '';
  warningThreshold.value = '';
  anomalyBasisPoints.value = '';
  scheduleReason.value = '';
  scheduleOpen.value = true;
}

const optionalNumber = (raw: string): number | null => (raw.trim() === '' ? null : Number(raw));

async function submitSchedule(): Promise<void> {
  // Duplicate-submit prevention: the guard is the FIRST statement, so a double click, an Enter
  // keypress landing on the submit button and a slow network cannot produce two rules.
  if (scheduleSubmitting.value) return;
  scheduleSubmitting.value = true;
  scheduleError.value = null;

  try {
    await store.schedule({
      unit_cost_minor: unitCostMinor.value ?? 0,
      effective_from: effectiveFrom.value,
      reason: scheduleReason.value,
      tax_basis_points: optionalNumber(taxBasisPoints.value),
      usage_warning_threshold_units: optionalNumber(warningThreshold.value),
      usage_anomaly_threshold_basis_points: optionalNumber(anomalyBasisPoints.value),
    });
    scheduleOpen.value = false;
  } catch (error) {
    // 409 = an overlapping effective instant; 422 = backdated or already effective. The server's
    // message is shown verbatim rather than restated, so the two cases stay distinguishable.
    scheduleError.value = resolveMessage(error, 'Unable to schedule this SMS price.');
  } finally {
    scheduleSubmitting.value = false;
  }
}

// ---------------------------------------------------------------------------------------------
// Cancel-scheduled confirmation
// ---------------------------------------------------------------------------------------------

const cancelTarget = ref<SmsBillingRule | null>(null);
const cancelReason = ref('');
const cancelSubmitting = ref(false);
const cancelError = ref<string | null>(null);

function openCancel(rule: SmsBillingRule): void {
  cancelTarget.value = rule;
  cancelReason.value = '';
  cancelError.value = null;
}

async function confirmCancel(): Promise<void> {
  if (cancelSubmitting.value || cancelTarget.value === null) return;
  cancelSubmitting.value = true;
  cancelError.value = null;

  try {
    await store.cancelScheduled(cancelTarget.value.id, cancelReason.value);
    cancelTarget.value = null;
  } catch (error) {
    cancelError.value = resolveMessage(error, 'Unable to withdraw this scheduled price.');
  } finally {
    cancelSubmitting.value = false;
  }
}

function resolveMessage(error: unknown, fallback: string): string {
  const apiError = (error as { apiError?: { message?: string } }).apiError;
  return apiError?.message ?? fallback;
}

// ---------------------------------------------------------------------------------------------
// Cost-notice preview
// ---------------------------------------------------------------------------------------------

const previewRecipients = ref('100');
const previewSegments = ref('1');
const previewError = ref<string | null>(null);

async function preview(): Promise<void> {
  previewError.value = null;
  try {
    await store.previewCostNotice(Number(previewRecipients.value), Number(previewSegments.value));
  } catch (error) {
    previewError.value = resolveMessage(error, 'Unable to preview the cost notice.');
  }
}

// ---------------------------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------------------------

const versionColumns: SvColumn<SmsBillingRule>[] = [
  { key: 'state', label: 'State', priority: 'primary' },
  { key: 'unit_cost', label: 'Unit cost', priority: 'primary', align: 'numeric' },
  { key: 'effective_from', label: 'Effective from', priority: 'secondary' },
  { key: 'tax_basis_points', label: 'Disclosed tax (bps)', priority: 'detail', align: 'numeric', value: (r) => r.tax_basis_points === null ? 'Not set' : String(r.tax_basis_points) },
  { key: 'reason', label: 'Reason', priority: 'detail', value: (r) => r.reason },
  { key: 'cancellation_reason', label: 'Withdrawal reason', priority: 'detail', value: (r) => r.cancellation_reason ?? '—' },
];

const usageColumns: SvColumn<SmsUsageRow>[] = [
  { key: 'usage_month', label: 'Month', priority: 'primary' },
  { key: 'merchant_id', label: 'Merchant', priority: 'secondary', value: (r) => r.merchant_id ?? 'Unattributed' },
  { key: 'message_count', label: 'Messages', priority: 'secondary', align: 'numeric', value: (r) => String(r.message_count) },
  { key: 'recipient_count', label: 'Recipients', priority: 'secondary', align: 'numeric', value: (r) => String(r.recipient_count) },
  { key: 'billable_units', label: 'Billable units', priority: 'secondary', align: 'numeric', value: (r) => String(r.billable_units) },
  { key: 'amount', label: 'Amount', priority: 'primary', align: 'numeric' },
];

const stateTone = (state: string): 'success' | 'info' | 'neutral' | 'warning' => {
  if (state === 'effective') return 'success';
  if (state === 'pending') return 'info';
  if (state === 'cancelled') return 'warning';
  return 'neutral';
};
</script>

<template>
  <div
    class="mx-auto w-full max-w-6xl"
    data-testid="sms-billing-screen"
  >
    <SvPageHeader
      title="SMS billing settings"
      eyebrow="Billing & commercial"
      description="Price in-platform personnel SMS usage and map it to branch and merchant billing. A new price is scheduled and supersedes the current one; it never re-prices SMS already recorded."
    >
      <template #actions>
        <SvButton
          v-if="canUpdate"
          variant="primary"
          data-testid="sms-schedule-open"
          @click="openSchedule"
        >
          Schedule a price
        </SvButton>
      </template>
    </SvPageHeader>

    <SvPermissionState v-if="!canView" />

    <template v-else>
      <p
        class="mb-6 text-xs text-sv-text-muted"
        data-testid="sms-last-refreshed"
      >
        Last refreshed
        <SvDateTime :value="store.lastRefreshed" />
      </p>

      <SvAlert
        v-if="store.error"
        severity="error"
        title="We could not load SMS billing settings"
        class="mb-6"
      >
        <p>{{ store.error }}</p>
        <SvButton
          variant="secondary"
          size="sm"
          class="mt-3"
          data-testid="sms-retry"
          @click="refresh"
        >
          Try again
        </SvButton>
      </SvAlert>

      <!-- Current + scheduled rule ------------------------------------------------------- -->
      <section
        aria-labelledby="sms-current-heading"
        class="mb-8"
      >
        <h2
          id="sms-current-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Effective and scheduled price
        </h2>

        <div class="grid gap-4 sm:grid-cols-2">
          <SvMetricCard
            label="Effective now"
            :context="store.settings?.current ? 'In force for every SMS charged today.' : 'No price is in force.'"
            :loading="dataState === 'loading'"
            data-testid="sms-current-rule"
          >
            <SvMoney
              v-if="store.settings?.current"
              :minor-units="store.settings.current.unit_cost_minor"
              :currency="store.settings.currency"
              size="lg"
            />
            <span
              v-else
              class="text-sm text-sv-text-muted"
            >Not available</span>
          </SvMetricCard>

          <SvMetricCard
            label="Scheduled next"
            :context="store.settings?.next ? 'Takes effect automatically; withdraw it before then if needed.' : 'Nothing is scheduled.'"
            :loading="dataState === 'loading'"
            data-testid="sms-next-rule"
          >
            <template v-if="store.settings?.next">
              <SvMoney
                :minor-units="store.settings.next.unit_cost_minor"
                :currency="store.settings.currency"
                size="lg"
              />
              <p class="mt-1 text-xs text-sv-text-muted">
                From <SvDateTime :value="store.settings.next.effective_from" />
              </p>
              <SvButton
                v-if="canUpdate"
                variant="secondary"
                size="sm"
                class="mt-3"
                data-testid="sms-cancel-scheduled"
                @click="openCancel(store.settings.next)"
              >
                Withdraw scheduled price
              </SvButton>
            </template>
            <span
              v-else
              class="text-sm text-sv-text-muted"
            >None scheduled</span>
          </SvMetricCard>
        </div>

        <p class="mt-3 text-xs text-sv-text-muted">
          Currency is inherited from platform billing settings
          <template v-if="store.settings">
            ({{ store.settings.currency }})
          </template>, which remains
          the single currency authority.
        </p>
      </section>

      <!-- Version history ---------------------------------------------------------------- -->
      <section
        aria-labelledby="sms-history-heading"
        class="mb-8"
      >
        <h2
          id="sms-history-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Price history
        </h2>

        <div class="hidden md:block">
          <SvDataTable
            :columns="versionColumns"
            :rows="store.versions"
            :row-key="(r) => r.id"
            caption="SMS price versions, newest first"
            :state="versionsState"
            empty-message="No SMS price has been recorded yet."
            :error-message="store.error ?? undefined"
            @retry="refresh"
          >
            <template #cell:state="{ row }">
              <SvStatusBadge
                :label="row.state"
                :tone="stateTone(row.state)"
                sr-prefix="State:"
              />
            </template>
            <template #cell:unit_cost="{ row }">
              <SvMoney
                :minor-units="row.unit_cost_minor"
                :currency="row.currency"
              />
            </template>
            <template #cell:effective_from="{ row }">
              <SvDateTime :value="row.effective_from" />
            </template>
          </SvDataTable>
        </div>

        <div class="md:hidden">
          <SvResponsiveRecordList
            :columns="versionColumns"
            :rows="store.versions"
            :row-key="(r) => r.id"
            caption="SMS price versions, newest first"
            :state="versionsState"
            empty-message="No SMS price has been recorded yet."
            :error-message="store.error ?? undefined"
            @retry="refresh"
          >
            <template #cell:state="{ row }">
              <SvStatusBadge
                :label="row.state"
                :tone="stateTone(row.state)"
                sr-prefix="State:"
              />
            </template>
            <template #cell:unit_cost="{ row }">
              <SvMoney
                :minor-units="row.unit_cost_minor"
                :currency="row.currency"
              />
            </template>
            <template #cell:effective_from="{ row }">
              <SvDateTime :value="row.effective_from" />
            </template>
          </SvResponsiveRecordList>
        </div>

        <SvPagination
          v-if="store.versionsMeta && store.versionsMeta.last_page > 1"
          :current-page="store.versionsMeta.current_page"
          :last-page="store.versionsMeta.last_page"
          :total="store.versionsMeta.total"
          :per-page="store.versionsMeta.per_page"
          label="SMS price versions"
          class="mt-4"
        />
      </section>

      <!-- Cost-notice preview ------------------------------------------------------------ -->
      <section
        aria-labelledby="sms-notice-heading"
        class="mb-8"
      >
        <h2
          id="sms-notice-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Cost notice preview
        </h2>
        <p class="mb-3 max-w-sv-readable text-sm text-sv-text-muted">
          The exact wording personnel see before sending. It is generated by the server from the
          effective rule, so what is previewed here is what is shown.
        </p>

        <div class="flex flex-wrap items-end gap-3">
          <SvTextInput
            id="sms-preview-recipients"
            v-model="previewRecipients"
            label="Recipients"
            type="number"
            inputmode="numeric"
          />
          <SvTextInput
            id="sms-preview-segments"
            v-model="previewSegments"
            label="Segments per message"
            type="number"
            inputmode="numeric"
          />
          <SvButton
            variant="secondary"
            data-testid="sms-preview-notice"
            @click="preview"
          >
            Preview notice
          </SvButton>
        </div>

        <SvAlert
          v-if="previewError"
          severity="error"
          class="mt-3"
        >
          <p>{{ previewError }}</p>
        </SvAlert>

        <div
          v-else-if="store.costNotice"
          class="mt-4 rounded-card border border-sv-border bg-sv-surface-raised p-4"
          data-testid="sms-cost-notice"
        >
          <p class="text-sm text-sv-text">
            {{ store.costNotice.notice }}
          </p>
          <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
            <dt class="text-sv-text-muted">
              Billable units
            </dt>
            <dd class="sv-numeric">
              {{ store.costNotice.billable_units }}
            </dd>
            <dt class="text-sv-text-muted">
              Amount
            </dt>
            <dd>
              <SvMoney
                :minor-units="store.costNotice.amount_minor"
                :currency="store.costNotice.currency"
              />
            </dd>
          </dl>
          <p class="mt-2 text-xs text-sv-text-muted">
            Any disclosed tax is shown to the sender for transparency; it is disclosed, never
            charged here.
          </p>
        </div>
      </section>

      <!-- Usage --------------------------------------------------------------------------- -->
      <section
        aria-labelledby="sms-usage-heading"
        class="mb-8"
      >
        <h2
          id="sms-usage-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Usage and billing totals
        </h2>
        <p class="mb-3 max-w-sv-readable text-sm text-sv-text-muted">
          Message count, recipient count, billable units and amount are four DISTINCT quantities and
          are never conflated. No recipient list, phone number or message body is available here or
          anywhere else on this page.
        </p>

        <div class="hidden md:block">
          <SvDataTable
            :columns="usageColumns"
            :rows="store.usage"
            :row-key="(r) => `${r.usage_month}:${r.merchant_id ?? 'none'}`"
            caption="SMS usage by month and merchant"
            :state="usageState"
            empty-message="No SMS usage has been recorded for this period."
            @retry="refresh"
          >
            <template #cell:amount="{ row }">
              <SvMoney
                :minor-units="row.amount_minor"
                :currency="row.currency"
              />
            </template>
          </SvDataTable>
        </div>

        <div class="md:hidden">
          <SvResponsiveRecordList
            :columns="usageColumns"
            :rows="store.usage"
            :row-key="(r) => `${r.usage_month}:${r.merchant_id ?? 'none'}`"
            caption="SMS usage by month and merchant"
            :state="usageState"
            empty-message="No SMS usage has been recorded for this period."
            @retry="refresh"
          >
            <template #cell:amount="{ row }">
              <SvMoney
                :minor-units="row.amount_minor"
                :currency="row.currency"
              />
            </template>
          </SvResponsiveRecordList>
        </div>
      </section>

      <!-- Charge reconciliation ----------------------------------------------------------- -->
      <section
        v-if="store.reconciliation"
        aria-labelledby="sms-reconciliation-heading"
      >
        <h2
          id="sms-reconciliation-heading"
          class="mb-3 font-display text-lg font-bold text-sv-text-heading"
        >
          Charge reconciliation
        </h2>

        <div
          class="grid gap-4 sm:grid-cols-2"
          data-testid="sms-reconciliation"
        >
          <SvMetricCard
            label="Linked to an invoice"
            context="SMS charges mapped to a subscription invoice line."
          >
            <span class="sv-numeric text-2xl font-bold">{{ store.reconciliation.invoice_mapping.linked_count }}</span>
          </SvMetricCard>
          <SvMetricCard
            label="Not yet linked"
            context="Recorded usage awaiting its invoice line."
            :increase-is-positive="false"
          >
            <span class="sv-numeric text-2xl font-bold">{{ store.reconciliation.invoice_mapping.unlinked_count }}</span>
          </SvMetricCard>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
          <dt class="text-sv-text-muted">
            Threshold state
          </dt>
          <dd data-testid="sms-threshold-state">
            {{ store.reconciliation.thresholds.warning_state }}
          </dd>
          <dt class="text-sv-text-muted">
            Anomaly state
          </dt>
          <dd data-testid="sms-anomaly-state">
            {{ store.reconciliation.thresholds.anomaly_state }}
          </dd>
        </dl>
      </section>
    </template>

    <!-- Schedule dialog ------------------------------------------------------------------- -->
    <SvDialog
      :open="scheduleOpen"
      title="Schedule an SMS price"
      description="The new price takes effect at the instant you choose. It supersedes the current price and never re-prices SMS already recorded."
      persistent
      @close="scheduleOpen = false"
    >
      <div class="space-y-4">
        <SvMoneyInput
          id="sms-unit-cost"
          v-model="unitCostMinor"
          label="Unit cost per billable unit"
          :currency="store.settings?.currency ?? 'KES'"
          required
        />
        <SvDatePicker
          id="sms-effective-from"
          v-model="effectiveFrom"
          label="Effective from"
          help="A price cannot be backdated, and two prices cannot share an effective instant."
          required
        />
        <SvTextInput
          id="sms-tax-bps"
          v-model="taxBasisPoints"
          label="Disclosed tax (basis points)"
          type="number"
          inputmode="numeric"
          help="Optional. Disclosed to senders for transparency; it is not charged here."
        />
        <SvTextInput
          id="sms-warning-threshold"
          v-model="warningThreshold"
          label="Usage warning threshold (units)"
          type="number"
          inputmode="numeric"
          help="Optional."
        />
        <SvTextInput
          id="sms-anomaly-bps"
          v-model="anomalyBasisPoints"
          label="Usage anomaly threshold (basis points growth)"
          type="number"
          inputmode="numeric"
          help="Optional."
        />
        <SvTextArea
          id="sms-reason"
          v-model="scheduleReason"
          label="Reason"
          :rows="3"
          help="Recorded on the audit event for this change."
          required
        />

        <SvAlert
          v-if="scheduleError"
          severity="error"
          data-testid="sms-schedule-error"
        >
          <p>{{ scheduleError }}</p>
        </SvAlert>

        <p class="text-xs text-sv-text-muted">
          This change requires multi-factor authentication and a fresh step-up. If your step-up has
          expired the server will refuse the change and ask you to re-verify.
        </p>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="scheduleOpen = false"
        >
          Cancel
        </SvButton>
        <SvButton
          variant="primary"
          :loading="scheduleSubmitting"
          loading-label="Scheduling"
          data-testid="sms-schedule-submit"
          @click="submitSchedule"
        >
          Schedule price
        </SvButton>
      </template>
    </SvDialog>

    <!--
      Withdraw confirmation. SvConfirmDialog exposes no slot, and this action requires a mandatory
      reason, so it uses SvDialog rather than a confirm dialog with the reason moved somewhere the
      user cannot see while deciding.
    -->
    <SvDialog
      :open="cancelTarget !== null"
      title="Withdraw the scheduled price?"
      description="The scheduled price will never take effect. The price in force today is unchanged, and no recorded SMS is re-priced. A rule that has already taken effect can never be withdrawn."
      persistent
      @close="cancelTarget = null"
    >
      <div class="space-y-4">
        <SvTextArea
          id="sms-cancel-reason"
          v-model="cancelReason"
          label="Reason"
          :rows="3"
          help="Recorded on the audit event."
          required
        />
        <SvAlert
          v-if="cancelError"
          severity="error"
          data-testid="sms-cancel-error"
        >
          <p>{{ cancelError }}</p>
        </SvAlert>
      </div>

      <template #footer>
        <SvButton
          variant="ghost"
          @click="cancelTarget = null"
        >
          Keep it scheduled
        </SvButton>
        <SvButton
          variant="destructive"
          :loading="cancelSubmitting"
          loading-label="Withdrawing"
          data-testid="sms-cancel-submit"
          @click="confirmCancel"
        >
          Withdraw price
        </SvButton>
      </template>
    </SvDialog>
  </div>
</template>
