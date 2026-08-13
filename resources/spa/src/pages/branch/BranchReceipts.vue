<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useReceiptStore } from '@/stores/receiptStore';

const store = useReceiptStore();
const downloading = ref<string | null>(null);
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
  <section
    class="mx-auto max-w-5xl"
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
      <ul
        class="grid gap-3"
        aria-label="Branch receipts"
      >
        <li
          v-for="receipt in store.receipts"
          :key="receipt.id"
        >
          <SvCard
            as="article"
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <h2 class="font-display font-bold text-heading">
                Receipt #{{ receipt.receipt_number }}
              </h2><p class="text-sm text-text-muted">
                Invoice {{ receipt.invoice?.invoice_number ?? '—' }} · {{ receipt.amount.formatted }}
              </p><p class="mt-1 text-xs text-text-muted">
                {{ receipt.downloadable ? 'Ready to print' : 'File is being prepared' }}
              </p>
            </div>
            <SvButton
              variant="secondary"
              :loading="downloading === receipt.id"
              :disabled="!receipt.downloadable"
              @click="download(receipt.id)"
            >
              Download receipt
            </SvButton>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
