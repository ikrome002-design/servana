<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceDisputeStore } from '@/stores/financeDisputeStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { formatDate } from '@/utils/dates';

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
/** COR-UI08-UI12-VIS-001: the loaded disputes placed in their server status lanes (no re-ranking). */
const lanes = computed(() => [
  { status: 'open', label: 'Open', tone: 'sv-r-tone-alert' },
  { status: 'under_review', label: 'Under review', tone: 'sv-r-tone-sun' },
  { status: 'resolved', label: 'Resolved', tone: 'sv-r-tone-green' },
  { status: 'rejected', label: 'Rejected', tone: 'sv-r-tone-neutral' },
].map((lane) => ({ ...lane, items: store.disputes.filter((dispute) => dispute.status === lane.status) })));

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
  <!--
    COR-UI08-UI12-VIS-001 — disputes as a LIFECYCLE BOARD. The loaded disputes sit in their server
    status lanes (open → under review → resolved / rejected) so the investigation backlog reads at a
    glance; each card names the reason, the linked invoice, whether evidence is attached and when it
    was opened. The disputed source record is never changed by an investigation.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Disputes"
      eyebrow="Merchant-Client Finance"
      description="Disputes raised against recorded payments and receipts, followed from opening to resolution."
    >
      <template #actions>
        <div class="flex flex-wrap items-end gap-2">
          <SvSelect
            id="dispute-status-filter"
            v-model="store.filterStatus"
            label="Filter"
            :options="statusOptions"
            class="w-full md:w-56"
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
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load disputes."
      empty-message="No disputes match this filter."
    >
      <div class="sv-r-grid-tight md:grid-cols-2 lg:grid-cols-4">
        <section
          v-for="lane in lanes"
          :key="lane.status"
          class="sv-r-panel sv-r-panel--sunken sv-r-panel--compact min-w-0"
          :aria-labelledby="`dispute-lane-${lane.status}`"
        >
          <div class="flex items-center justify-between gap-2">
            <h2
              :id="`dispute-lane-${lane.status}`"
              class="sv-r-eyebrow sv-r-eyebrow--dot"
              :class="lane.tone"
            >
              {{ lane.label }}
            </h2>
            <span class="sv-r-chip">{{ lane.items.length }}</span>
          </div>
          <ul class="mt-3 flex flex-col gap-2">
            <li
              v-for="dispute in lane.items"
              :key="dispute.id"
            >
              <button
                type="button"
                class="sv-r-record sv-r-record--spine sv-focus-ring block w-full text-left"
                :class="lane.tone"
                data-testid="dispute-row"
                @click="() => router.push({ name: 'finance.disputes.detail', params: { id: dispute.id } })"
              >
                <span class="block font-display font-bold text-heading">{{ dispute.reason }}</span>
                <span class="mt-1 block text-sm text-text-muted">
                  Invoice {{ dispute.invoice?.invoice_number ?? '—' }}<span v-if="dispute.has_evidence"> · evidence attached</span>
                </span>
                <span
                  v-if="dispute.created_at"
                  class="mt-2 block text-xs text-text-muted"
                >Opened {{ formatDate(dispute.created_at) }}</span>
              </button>
            </li>
            <li
              v-if="lane.items.length === 0"
              class="rounded-field border border-dashed border-sv-border p-3 text-xs text-sv-text-muted"
            >
              None in this lane.
            </li>
          </ul>
        </section>
      </div>
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
