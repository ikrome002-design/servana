<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useReceiptStore } from '@/stores/receiptStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { formatDateTime } from '@/utils/dates';

/**
 * Receipt list (Plan §43; Phase 18B). Shared by Finance and Front Office — both hold
 * `receipt.view`. Receipts are issued AUTOMATICALLY on payment validation; there is NO
 * manual issue action anywhere. Reissue + review controls live on the detail screen and
 * are capability-gated. Money is server-formatted KES.
 */
const store = useReceiptStore();
const route = useRoute();
const router = useRouter();

const isFinance = computed(() => route.meta.roleIdentity === 'merchant_finance');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.receipts.length === 0) return 'empty';
  return 'success';
});

function detailRoute(id: string): { name: string; params: Record<string, string> } {
  return isFinance.value
    ? { name: 'finance.receipts.detail', params: { id } }
    : { name: 'front-office.receipt-detail', params: { receiptUlid: id } };
}

onMounted(() => {
  void store.fetchReceipts();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — receipts as a STUB FILE. Each original or reissued receipt is a ticket
    with a perforated edge: its number, the invoice it settles, the server-formatted amount, the
    issue time and whether its file is ready. The rail states the automatic-issue rule; there is no
    manual issue control anywhere.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Receipts"
      eyebrow="Merchant-Client Finance"
      description="Receipts are issued automatically when Finance validates a payment."
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load receipts."
      empty-message="No receipts yet. A receipt is issued automatically when Finance validates a payment."
    >
      <div class="sv-r-grid sv-r-split">
        <ul
          class="sv-r-grid-tight min-w-0 md:grid-cols-2"
          aria-label="Receipts"
        >
          <li
            v-for="receipt in store.receipts"
            :key="receipt.id"
          >
            <button
              type="button"
              class="sv-r-record sv-r-record--ticket sv-focus-ring block w-full text-left"
              data-testid="receipt-row"
              @click="() => router.push(detailRoute(receipt.id))"
            >
              <span class="flex items-start justify-between gap-3">
                <span class="min-w-0">
                  <span class="sv-r-eyebrow block">
                    {{ receipt.is_reissue ? 'Reissued receipt' : 'Original receipt' }}
                  </span>
                  <span class="mt-1 block font-display text-lg font-extrabold text-heading">
                    Receipt #{{ receipt.receipt_number }}
                    <span
                      v-if="receipt.is_reissue"
                      class="sv-r-chip ml-1 align-middle"
                    >Reissue</span>
                  </span>
                  <span class="mt-1 block text-sm text-text-muted">
                    Invoice {{ receipt.invoice?.invoice_number ?? '—' }}<template v-if="receipt.created_at"> · {{ formatDateTime(receipt.created_at) }}</template>
                  </span>
                </span>
                <span class="shrink-0 text-right">
                  <span class="sv-numeric block font-display text-lg font-extrabold text-heading">{{ receipt.amount.formatted }}</span>
                  <span
                    class="mt-1 inline-flex rounded-pill border px-2 py-0.5 text-xs font-semibold"
                    :class="receipt.downloadable ? 'border-sv-success-border bg-sv-success-bg text-sv-success-fg' : 'border-sv-border bg-surface-alt text-text-muted'"
                  >{{ receipt.downloadable ? 'Ready' : 'Preparing…' }}</span>
                </span>
              </span>
            </button>
          </li>
        </ul>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Receipt rules"
        >
          <section class="sv-r-note sv-r-tone-green">
            <h2 class="sv-r-note__title">
              Issued automatically
            </h2>
            <p class="sv-r-note__body">
              One original receipt follows each validated payment group. Nobody issues a receipt by hand, and a rejected group never receives one.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-neutral">
            <h2 class="sv-r-note__title">
              Reissues
            </h2>
            <p class="sv-r-note__body">
              A reissue is a new document that points back to the original. Its controls live on the receipt detail and depend on your capability.
            </p>
          </section>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>
