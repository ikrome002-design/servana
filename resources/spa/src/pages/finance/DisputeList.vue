<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceDisputeStore } from '@/stores/financeDisputeStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Finance dispute list (Plan §44; Phase 18B). Finance opens an investigation linked to
 * an invoice and/or a payment record; the disputed source record is NEVER mutated.
 * `finance_dispute.manage` gates all controls. Contact/reference are masked by the API.
 */
const store = useFinanceDisputeStore();
const permissions = usePermissionStore();
const router = useRouter();

const creating = ref(false);
const busy = ref(false);
const formError = ref<string | null>(null);
const form = ref({ invoice: '', payment_record: '', reason: '' });

const canManage = computed(() => permissions.can('finance_dispute.manage'));

const statusOptions = [
  { value: '', label: 'All disputes' },
  { value: 'open', label: 'Open' },
  { value: 'under_review', label: 'Under review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'rejected', label: 'Rejected' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.disputes.length === 0) return 'empty';
  return 'success';
});

async function submit(): Promise<void> {
  if (form.value.reason.trim() === '' || (form.value.invoice.trim() === '' && form.value.payment_record.trim() === '')) return;
  busy.value = true;
  formError.value = null;
  try {
    const dispute = await store.create({
      invoice: form.value.invoice.trim() || undefined,
      payment_record: form.value.payment_record.trim() || undefined,
      reason: form.value.reason.trim(),
    });
    creating.value = false;
    form.value = { invoice: '', payment_record: '', reason: '' };
    void router.push({ name: 'finance.disputes.detail', params: { id: dispute.id } });
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    formError.value = err.response?.data?.error?.message ?? 'The dispute could not be opened.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchDisputes();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Disputes
      </h1>
      <div class="flex flex-wrap items-center gap-2">
        <SvSelect
          id="dispute-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full sm:w-56"
          @update:model-value="() => store.fetchDisputes()"
        />
        <SvButton
          v-if="canManage"
          data-testid="dispute-create-open"
          @click="creating = true"
        >
          Open dispute
        </SvButton>
      </div>
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load disputes."
      empty-message="No disputes match this filter."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="dispute in store.disputes"
          :key="dispute.id"
        >
          <SvCard
            as="button"
            padding="md"
            class="w-full text-left"
            data-testid="dispute-row"
            @click="() => router.push({ name: 'finance.disputes.detail', params: { id: dispute.id } })"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ dispute.reason }}
                </p>
                <p class="text-sm text-text-muted">
                  Invoice {{ dispute.invoice?.invoice_number ?? '—' }}
                  <span v-if="dispute.has_evidence"> · evidence attached</span>
                </p>
              </div>
              <span class="rounded bg-surface-alt px-2 py-0.5 text-xs text-text-muted">{{ dispute.status }}</span>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvDialog
      :open="creating"
      title="Open a finance dispute"
      description="Link the dispute to an invoice and/or a payment record. The disputed source record is never changed by the investigation."
      @close="creating = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvTextInput
          id="dispute-invoice"
          v-model="form.invoice"
          label="Invoice ID (optional)"
        />
        <SvTextInput
          id="dispute-payment-record"
          v-model="form.payment_record"
          label="Payment record ID (optional)"
        />
        <SvTextArea
          id="dispute-reason"
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
          @click="creating = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="dispute-create-confirm"
          :loading="busy"
          :disabled="form.reason.trim() === '' || (form.invoice.trim() === '' && form.payment_record.trim() === '')"
          @click="submit"
        >
          Open dispute
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
