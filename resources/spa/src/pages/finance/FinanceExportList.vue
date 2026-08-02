<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceExportStore, SUPPORTED_EXPORT_TYPES, type FinanceExportView } from '@/stores/financeExportStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Finance exports (Plan §65, §67; Phase 18B). Finance requests a scoped, masked export
 * (a fresh step-up is server-enforced), generated async; downloads go through a
 * short-lived authorized signed link (never stored). Only invoices/payments/receipts/
 * cash_up/refunds/disputes are offered — compensation/payouts/billing are not.
 */
const store = useFinanceExportStore();
const permissions = usePermissionStore();

const requesting = ref(false);
const busy = ref(false);
const actionError = ref<string | null>(null);
const form = ref({ export_type: 'invoices', reason: '' });

const canCreate = computed(() => permissions.can('finance_export.create'));
const canDownload = computed(() => permissions.can('finance_export.download'));
const typeOptions = SUPPORTED_EXPORT_TYPES.map((t) => ({ value: t.value, label: t.label }));

const statusOptions = [
  { value: '', label: 'All exports' },
  { value: 'queued', label: 'Queued' },
  { value: 'processing', label: 'Processing' },
  { value: 'ready', label: 'Ready' },
  { value: 'failed', label: 'Failed' },
  { value: 'expired', label: 'Expired' },
  { value: 'revoked', label: 'Revoked' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.exports.length === 0) return 'empty';
  return 'success';
});

async function submitRequest(): Promise<void> {
  if (form.value.reason.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.request({ export_type: form.value.export_type, reason: form.value.reason.trim() });
    requesting.value = false;
    form.value = { export_type: 'invoices', reason: '' };
    await store.fetchExports();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The export could not be requested.';
  } finally {
    busy.value = false;
  }
}

async function download(exp: FinanceExportView): Promise<void> {
  actionError.value = null;
  try {
    const url = await store.downloadLink(exp.id);
    window.open(url, '_blank', 'noopener');
    await store.fetchExports();
  } catch {
    actionError.value = 'This export is not available for download.';
  }
}

async function revoke(exp: FinanceExportView): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.revoke(exp.id);
    await store.fetchExports();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The export could not be revoked.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchExports();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Exports
      </h1>
      <div class="flex flex-wrap items-center gap-2">
        <SvSelect
          id="export-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full sm:w-56"
          @update:model-value="() => store.fetchExports()"
        />
        <SvButton
          v-if="canCreate"
          data-testid="export-request-open"
          @click="requesting = true"
        >
          Request export
        </SvButton>
      </div>
    </div>

    <p
      v-if="actionError"
      class="mt-3 text-sm text-sv-error-fg"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load exports."
      empty-message="No exports match this filter."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="exp in store.exports"
          :key="exp.id"
        >
          <SvCard
            as="section"
            padding="md"
            data-testid="export-row"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ exp.export_type }}
                </p>
                <p class="text-sm text-text-muted">
                  {{ exp.status }} · {{ exp.row_count ?? 0 }} rows · {{ exp.download_count }} downloads
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <SvButton
                  v-if="canDownload && exp.status === 'ready'"
                  data-testid="export-download"
                  @click="download(exp)"
                >
                  Download
                </SvButton>
                <SvButton
                  v-if="canCreate && exp.status === 'ready'"
                  variant="secondary"
                  data-testid="export-revoke"
                  :loading="busy"
                  @click="revoke(exp)"
                >
                  Revoke
                </SvButton>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvDialog
      :open="requesting"
      title="Request a finance export"
      description="The export is scoped to your access and masked. A fresh step-up is required. Only the listed data types are available this phase."
      @close="requesting = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvSelect
          id="export-type"
          v-model="form.export_type"
          label="Data"
          :options="typeOptions"
        />
        <SvTextArea
          id="export-reason"
          v-model="form.reason"
          label="Reason"
        />
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="requesting = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="export-request-confirm"
          :loading="busy"
          :disabled="form.reason.trim() === ''"
          @click="submitRequest"
        >
          Request export
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
