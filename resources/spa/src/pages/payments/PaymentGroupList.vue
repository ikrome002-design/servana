<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePaymentStore } from '@/stores/paymentStore';

/**
 * Finance payment recordings (Plan §41; Phase 18A). Read-only list of recording
 * groups awaiting Finance action (customer_payment.view). There is NO validation,
 * rejection, or receipt control here — those are Phase 18B. A group held for a
 * duplicate-reference review is flagged; the override lives on the detail page.
 */
const store = usePaymentStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.groups.length === 0) return 'empty';
  return 'success';
});

function statusLabel(status: string): string {
  return status === 'pending_validation' ? 'Pending validation' : status === 'recorded' ? 'Held — duplicate review' : status;
}

onMounted(() => {
  void store.fetchGroups();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Payment recordings
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Recorded payments awaiting validation. Validation, receipts and refunds arrive in a later phase.
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No payment recordings yet."
      error-message="We couldn’t load payment recordings."
      @retry="() => store.fetchGroups()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Payment recordings"
      >
        <li
          v-for="group in store.groups"
          :key="group.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  <RouterLink
                    :to="{ name: 'finance.payment-records.detail', params: { id: group.id } }"
                    class="hover:underline focus-visible:underline"
                  >
                    Invoice {{ group.invoice?.invoice_number ?? '—' }}
                  </RouterLink>
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  Recorded by {{ group.maker?.name ?? '—' }}
                </p>
              </div>
              <div class="text-right">
                <p class="font-display text-base font-semibold text-heading">
                  {{ group.total.formatted }}
                </p>
                <span
                  class="mt-1 inline-block rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                  data-testid="group-status-badge"
                >{{ statusLabel(group.status) }}</span>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
