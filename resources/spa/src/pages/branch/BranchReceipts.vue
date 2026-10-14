<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useReceiptStore } from '@/stores/receiptStore';

const store = useReceiptStore();
const downloading = ref<string | null>(null);
/** A filter over the receipts already loaded — it never queries the server for more. */
const query = ref('');
const visible = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (needle === '') return store.receipts;
  return store.receipts.filter((receipt) => String(receipt.receipt_number).toLowerCase().includes(needle)
    || (receipt.invoice?.invoice_number ?? '').toLowerCase().includes(needle));
});
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : store.receipts.length ? 'success' : 'empty');

async function download(id: string): Promise<void> {
  downloading.value = id;
  try {
    const url = await store.downloadLink(id);
    window.location.assign(url);
  } finally {
    downloading.value = null;
  }
}

onMounted(() => { void store.fetchReceipts(); });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a receipt ARCHIVE. A filter narrows the receipts already loaded (by
    receipt or invoice number — it searches nothing on the server); each receipt is a ticket-style
    card with its number, invoice and amount, and the one permitted action, download. Branch never
    issues or reissues a receipt.
  -->
  <section
    class="sv-r-page"
    data-testid="branch-receipts"
  >
    <SvPageHeader
      title="Receipts"
      eyebrow="Financial visibility"
      description="View and print receipts issued automatically after Finance validates a payment. Branch cannot issue or reissue receipts."
    />
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      empty-message="No receipts have been issued for this branch."
      @retry="store.fetchReceipts()"
    >
      <div class="sv-r-panel sv-r-panel--sunken sv-r-panel--compact mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="w-full md:w-80">
          <SvTextInput
            id="receipt-filter"
            v-model="query"
            label="Filter by receipt or invoice number"
          />
        </div>
        <span class="sv-r-chip">{{ visible.length }} of {{ store.receipts.length }} shown</span>
      </div>
      <ul
        class="grid gap-3 md:grid-cols-2"
        aria-label="Branch receipts"
      >
        <li
          v-for="receipt in visible"
          :key="receipt.id"
        >
          <article class="sv-r-record sv-r-record--ticket flex h-full flex-col gap-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="sv-r-eyebrow">
                  Receipt
                </p>
                <h2 class="font-display text-lg font-extrabold text-heading">
                  Receipt #{{ receipt.receipt_number }}
                </h2>
              </div>
              <span
                class="sv-r-chip sv-r-chip--tone sv-r-chip--dot"
                :class="receipt.downloadable ? 'sv-r-tone-green' : 'sv-r-tone-sun'"
              >{{ receipt.downloadable ? 'Ready to print' : 'File is being prepared' }}</span>
            </div>
            <p class="text-sm text-text-muted">
              Invoice {{ receipt.invoice?.invoice_number ?? '—' }} · {{ receipt.amount.formatted }}
            </p>
            <div class="mt-auto">
              <SvButton
                variant="secondary"
                :loading="downloading === receipt.id"
                :disabled="!receipt.downloadable"
                @click="download(receipt.id)"
              >
                Download receipt
              </SvButton>
            </div>
          </article>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>