<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useRefundStore } from '@/stores/refundStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { PAYMENT_METHODS } from '@/stores/paymentStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

/**
 * External refund list (Plan §44; Phase 18B). Servana NEVER moves funds; a refund
 * records the intent against a validated payment component. Finance (maker,
 * `refund.create`) requests; a DISTINCT Finance membership approves + finalizes. The
 * request carries a client-generated idempotency key; the external reference is masked.
 */
const store = useRefundStore();
const permissions = usePermissionStore();
const router = useRouter();

const requesting = ref(false);
const busy = ref(false);
const formError = ref<string | null>(null);
const form = ref({ payment_record: '', amount: '', method: 'cash', reason: '', reference: '' });
const amountMinor = computed(() => Number(form.value.amount) || 0);

const canCreate = computed(() => permissions.can('refund.create'));

const statusOptions = [
  { value: '', label: 'All refunds' },
  { value: 'requested', label: 'Awaiting approval' },
  { value: 'approved', label: 'Awaiting finalization' },
  { value: 'finalized', label: 'Finalized' },
  { value: 'rejected', label: 'Rejected' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.refunds.length === 0) return 'empty';
  return 'success';
});
/** COR-UI08-UI12-VIS-001: the four-eyes chain with the LOADED refunds counted at each stage. */
const pipeline = computed(() => [
  { status: 'requested', label: 'Requested', hint: 'awaiting a distinct approver', tone: 'sv-r-tone-sun' },
  { status: 'approved', label: 'Approved', hint: 'awaiting finalization', tone: 'sv-r-tone-teal' },
  { status: 'finalized', label: 'Finalized', hint: 'paid outside Servana and recorded', tone: 'sv-r-tone-green' },
  { status: 'rejected', label: 'Rejected', hint: 'closed without a refund', tone: 'sv-r-tone-neutral' },
].map((stage) => ({ ...stage, count: store.refunds.filter((refund) => refund.status === stage.status).length })));

function methodLabel(method: string): string {
  return PAYMENT_METHODS.find((option) => option.value === method)?.label ?? method.replaceAll('_', ' ');
}

function statusText(status: string): string {
  return statusOptions.find((option) => option.value === status)?.label ?? status.replaceAll('_', ' ');
}

function statusClass(status: string): string {
  if (status === 'finalized') return 'border-sv-success-border bg-sv-success-bg text-sv-success-fg';
  if (status === 'requested') return 'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg';
  if (status === 'approved') return 'border-sv-info-border bg-sv-info-bg text-sv-info-fg';
  return 'border-sv-border bg-surface-alt text-text-muted';
}

async function submitRequest(): Promise<void> {
  if (form.value.payment_record.trim() === '' || amountMinor.value <= 0 || form.value.reason.trim() === '') return;
  busy.value = true;
  formError.value = null;
  try {
    const refund = await store.request({
      payment_record: form.value.payment_record.trim(),
      amount_minor: amountMinor.value,
      method: form.value.method,
      reason: form.value.reason.trim(),
      reference: form.value.reference.trim() || undefined,
    });
    requesting.value = false;
    form.value = { payment_record: '', amount: '', method: 'cash', reason: '', reference: '' };
    void router.push({ name: 'finance.refunds.detail', params: { id: refund.id } });
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    formError.value = err.response?.data?.error?.message ?? 'The refund could not be requested.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchRefunds();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — external refunds as a FOUR-EYES PIPELINE. The chain (requested → approved
    by a distinct member → finalized) heads the page with the loaded count at each stage; the
    refunds follow in a ledger with amount, method, invoice, masked reference and status. Servana
    records intent only — it never moves money, and the rail says so.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="External refunds"
      eyebrow="Merchant-Client Finance"
      description="Refunds paid to clients outside Servana, recorded and approved here. Servana does not move money."
    >
      <template #actions>
        <div class="flex flex-wrap items-end gap-2">
          <SvSelect
            id="refund-status-filter"
            v-model="store.filterStatus"
            label="Filter"
            :options="statusOptions"
            class="w-full md:w-56"
            @update:model-value="() => store.fetchRefunds()"
          />
          <SvButton
            v-if="canCreate"
            data-testid="refund-request-open"
            @click="requesting = true"
          >
            Request refund
          </SvButton>
        </div>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load refunds."
      empty-message="No refunds match this filter."
    >
      <div class="sv-r-stack">
        <ol
          class="sv-r-grid-tight md:grid-cols-4"
          aria-label="Refund approval chain"
        >
          <li
            v-for="(stage, index) in pipeline"
            :key="stage.status"
            class="sv-r-kpi"
            :class="stage.tone"
          >
            <div class="sv-r-kpi__top">
              <p class="sv-r-kpi__label">
                {{ index + 1 }} · {{ stage.label }}
              </p>
            </div>
            <p class="sv-r-kpi__value">
              {{ stage.count }}
            </p>
            <p class="sv-r-kpi__hint">
              {{ stage.hint }}
            </p>
          </li>
        </ol>

        <section
          class="sv-r-ledger"
          aria-labelledby="refund-ledger-heading"
        >
          <div class="sv-r-ledger__head">
            <h2
              id="refund-ledger-heading"
              class="sv-r-title"
            >
              Refund requests
            </h2>
            <span class="sv-r-chip">References masked · counts on this page</span>
          </div>
          <ul>
            <li
              v-for="refund in store.refunds"
              :key="refund.id"
              class="border-b border-sv-border last:border-b-0"
            >
              <button
                type="button"
                class="sv-focus-ring flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left transition-colors duration-sv-fast hover:bg-surface-alt"
                data-testid="refund-row"
                @click="() => router.push({ name: 'finance.refunds.detail', params: { id: refund.id } })"
              >
                <span class="min-w-0">
                  <span class="sv-numeric block font-display text-base font-extrabold text-heading">
                    {{ refund.amount.formatted }} · <span class="capitalize">{{ methodLabel(refund.method) }}</span>
                  </span>
                  <span class="mt-0.5 block text-sm text-text-muted">
                    Invoice {{ refund.invoice?.invoice_number ?? '—' }} · ref {{ refund.reference_masked ?? '—' }}
                  </span>
                </span>
                <span
                  class="inline-flex rounded-pill border px-2.5 py-0.5 text-xs font-semibold"
                  :class="statusClass(refund.status)"
                >{{ statusText(refund.status) }}</span>
              </button>
            </li>
          </ul>
        </section>

        <section class="sv-r-note sv-r-tone-neutral">
          <h2 class="sv-r-note__title">
            Servana records intent only
          </h2>
          <p class="sv-r-note__body">
            The refund is paid outside Servana. A distinct Finance member approves and finalizes it, and the amount is capped at the component’s remaining refundable value on the server.
          </p>
        </section>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="requesting"
      title="Request an external refund"
      description="Servana records the intent only; it never moves funds. The refund is capped at the component’s remaining refundable amount and requires a distinct approver and finalizer."
      @close="requesting = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvTextInput
          id="refund-component"
          v-model="form.payment_record"
          label="Validated payment component (ID)"
        />
        <SvTextInput
          id="refund-amount"
          v-model="form.amount"
          type="number"
          label="Amount (minor units)"
        />
        <SvSelect
          id="refund-method"
          v-model="form.method"
          label="Refund method"
          :options="PAYMENT_METHODS"
        />
        <SvTextInput
          id="refund-reference"
          v-model="form.reference"
          label="External reference (optional for cash)"
        />
        <SvTextArea
          id="refund-reason"
          v-model="form.reason"
          label="Reason"
        />
      </div>
      <p
        v-if="formError"
        class="mt-2 text-sm text-sv-error-fg"
        role="alert"
      >
        {{ formError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="requesting = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="refund-request-confirm"
          :loading="busy"
          :disabled="form.payment_record.trim() === '' || amountMinor <= 0 || form.reason.trim() === ''"
          @click="submitRequest"
        >
          Request refund
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
