<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useInvoiceStore } from '@/stores/invoiceStore';
import { useRouter } from 'vue-router';
import type { Invoice } from '@/types/models';

/**
 * Front Office payments entry (Plan §41; Phase 18A). Lists the invoices a payment
 * can be recorded against (issued / partially paid) and links each to the recording
 * form. Recording is not validation and issues no receipt.
 */
const store = useInvoiceStore();
const router = useRouter();
const recordable = ref<Invoice[]>([]);
const loading = ref(true);
const error = ref(false);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (loading.value) return 'loading';
  if (error.value) return 'error';
  if (recordable.value.length === 0) return 'empty';
  return 'success';
});

function goRecord(id: string): void {
  void router.push({ name: 'front-office.invoice-payment-create', params: { invoiceUlid: id } });
}

onMounted(async () => {
  try {
    await store.fetchInvoices();
    recordable.value = store.invoices.filter((i) => i.status === 'issued' || i.status === 'partially_paid');
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Record a payment
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Choose an issued or partially-paid invoice to record a customer payment against.
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No invoices are ready for a payment right now."
      error-message="We couldn’t load invoices."
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Recordable invoices"
      >
        <li
          v-for="invoice in recordable"
          :key="invoice.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  {{ invoice.invoice_number }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  Balance {{ invoice.balance.formatted }}
                </p>
              </div>
              <SvButton
                data-testid="record-payment"
                @click="goRecord(invoice.id)"
              >
                Record a payment
              </SvButton>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
