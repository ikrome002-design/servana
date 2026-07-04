<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useReceiptStore } from '@/stores/receiptStore';

/**
 * Receipt list (Plan §43; Phase 18B). Shared by Finance and Front Office — both hold
 * `receipt.view`. Receipts are issued AUTOMATICALLY on payment validation; there is NO
 * manual issue action anywhere. Reissue + review controls live on the detail screen and
 * are capability-gated. Money is server-formatted KES.
 */
const store = useReceiptStore();
const route = useRoute();
const router = useRouter();

const isFinance = computed(() => route.path.startsWith('/finance'));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.receipts.length === 0) return 'empty';
  return 'success';
});

function detailRoute(id: string): { name: string; params: { id: string } } {
  return { name: isFinance.value ? 'finance.receipts.detail' : 'front-office.receipts.detail', params: { id } };
}

onMounted(() => {
  void store.fetchReceipts();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Receipts
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load receipts."
      empty-message="No receipts yet. A receipt is issued automatically when Finance validates a payment."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="receipt in store.receipts"
          :key="receipt.id"
        >
          <SvCard
            as="button"
            padding="md"
            class="w-full text-left"
            data-testid="receipt-row"
            @click="() => router.push(detailRoute(receipt.id))"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  Receipt #{{ receipt.receipt_number }}
                  <span
                    v-if="receipt.is_reissue"
                    class="ml-2 rounded bg-surface-alt px-1.5 py-0.5 text-xs text-text-muted"
                  >Reissue</span>
                </p>
                <p class="text-sm text-text-muted">
                  Invoice {{ receipt.invoice?.invoice_number ?? '—' }}
                </p>
              </div>
              <div class="text-right">
                <p class="font-semibold text-heading">
                  {{ receipt.amount.formatted }}
                </p>
                <p class="text-xs text-text-muted">
                  {{ receipt.downloadable ? 'Ready' : 'Preparing…' }}
                </p>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
