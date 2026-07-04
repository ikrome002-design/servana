<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useRefundStore } from '@/stores/refundStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { PAYMENT_METHODS } from '@/stores/paymentStore';

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
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        External refunds
      </h1>
      <div class="flex flex-wrap items-center gap-2">
        <SvSelect
          id="refund-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full sm:w-56"
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
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load refunds."
      empty-message="No refunds match this filter."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="refund in store.refunds"
          :key="refund.id"
        >
          <SvCard
            as="button"
            padding="md"
            class="w-full text-left"
            data-testid="refund-row"
            @click="() => router.push({ name: 'finance.refunds.detail', params: { id: refund.id } })"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ refund.amount.formatted }} · {{ refund.method }}
                </p>
                <p class="text-sm text-text-muted">
                  Invoice {{ refund.invoice?.invoice_number ?? '—' }} · ref {{ refund.reference_masked ?? '—' }}
                </p>
              </div>
              <span class="rounded bg-surface-alt px-2 py-0.5 text-xs text-text-muted">{{ refund.status }}</span>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvModal
      :open="requesting"
      title="Request an external refund"
      description="Servana records the intent only; it never moves funds. The refund is capped at the component’s remaining refundable amount and requires a distinct approver and finalizer."
      @close="requesting = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvInput
          id="refund-component"
          v-model="form.payment_record"
          label="Validated payment component (ID)"
        />
        <SvInput
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
        <SvInput
          id="refund-reference"
          v-model="form.reference"
          label="External reference (optional for cash)"
        />
        <SvTextarea
          id="refund-reason"
          v-model="form.reason"
          label="Reason"
        />
      </div>
      <p
        v-if="formError"
        class="mt-2 text-sm text-[color:var(--color-danger,#dc2626)]"
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
    </SvModal>
  </section>
</template>
