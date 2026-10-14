<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconDocument } from '@/design-system/icons';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useFinanceExportStore, SUPPORTED_EXPORT_TYPES, type FinanceExportView } from '@/stores/financeExportStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { formatDateTime } from '@/utils/dates';

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
/** COR-UI08-UI12-VIS-001: the store's own label for an export type, and a tone per server status. */
function typeLabel(value: string): string {
  return SUPPORTED_EXPORT_TYPES.find((type) => type.value === value)?.label ?? value.replaceAll('_', ' ');
}

function statusClass(status: string): string {
  if (status === 'ready') return 'border-sv-success-border bg-sv-success-bg text-sv-success-fg';
  if (status === 'failed' || status === 'revoked') return 'border-sv-error-border bg-sv-error-bg text-sv-error-fg';
  if (status === 'queued' || status === 'processing') return 'border-sv-info-border bg-sv-info-bg text-sv-info-fg';
  return 'border-sv-border bg-surface-alt text-text-muted';
}

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
  <!--
    COR-UI08-UI12-VIS-001 — exports as an AUDITED VAULT. Each export is a file record: the data type,
    scope, status pill, rows, downloads and expiry, with download and revoke where the server state
    and capability allow. The rail states the two separate authorizations (request vs download), the
    step-up and the masking; the offered data types are the store's own list.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Exports"
      eyebrow="Reporting & Audit"
      description="Masked, audited exports of finance records. Requesting an export and downloading it are authorised separately."
    >
      <template #actions>
        <div class="flex flex-wrap items-end gap-2">
          <SvSelect
            id="export-status-filter"
            v-model="store.filterStatus"
            label="Filter"
            :options="statusOptions"
            class="w-full md:w-56"
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
      </template>
    </SvPageHeader>

    <p
      v-if="actionError"
      class="mb-4 rounded-field border border-sv-error-border bg-sv-error-bg px-4 py-3 text-sm text-sv-error-fg"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load exports."
      empty-message="No exports match this filter."
    >
      <div class="sv-r-grid sv-r-split">
        <ul class="flex min-w-0 flex-col gap-3">
          <li
            v-for="exp in store.exports"
            :key="exp.id"
          >
            <section
              class="sv-r-record"
              data-testid="export-row"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                  <span
                    aria-hidden="true"
                    class="sv-r-icon sv-r-tone-teal"
                  ><SvIconDocument /></span>
                  <div class="min-w-0">
                    <p class="font-display text-base font-bold capitalize text-heading">
                      {{ typeLabel(exp.export_type) }}
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                      <span
                        class="inline-flex rounded-pill border px-2.5 py-0.5 font-semibold capitalize"
                        :class="statusClass(exp.status)"
                      >{{ exp.status }}</span>
                      <span class="text-text-muted">{{ exp.scope === 'branch' ? exp.branch?.name ?? 'Branch' : 'Merchant-wide' }}</span>
                    </p>
                  </div>
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
              <dl class="sv-r-strip mt-4">
                <div>
                  <dt>Rows</dt>
                  <dd>{{ exp.row_count === null ? 'Not counted yet' : `${exp.row_count} rows` }}</dd>
                </div>
                <div>
                  <dt>Downloads</dt>
                  <dd>{{ exp.download_count }} downloads</dd>
                </div>
                <div>
                  <dt>Expires</dt>
                  <dd>{{ exp.expires_at ? formatDateTime(exp.expires_at) : 'Not set' }}</dd>
                </div>
              </dl>
            </section>
          </li>
        </ul>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Export rules"
        >
          <section class="sv-r-note sv-r-tone-teal">
            <h2 class="sv-r-note__title">
              Two separate permissions
            </h2>
            <p class="sv-r-note__body">
              Requesting an export and downloading it are authorized separately. A request needs a fresh step-up and a reason.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-neutral">
            <h2 class="sv-r-note__title">
              Masked by default
            </h2>
            <p class="sv-r-note__body">
              Exports are scoped to your access and masked. Download links are short-lived and never stored.
            </p>
          </section>
        </aside>
      </div>
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
