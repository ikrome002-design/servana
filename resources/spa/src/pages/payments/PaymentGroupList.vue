<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import { useRoute } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePaymentStore } from '@/stores/paymentStore';

/**
 * Finance payment recordings (Plan §41; Phase 18A). Read-only list of recording
 * groups awaiting Finance action (customer_payment.view). There is NO validation,
 * rejection, or receipt control here — those are Phase 18B. A group held for a
 * duplicate-reference review is flagged; the override lives on the detail page.
 */
const store = usePaymentStore();
const route = useRoute();
const filters = reactive({ page: 1 });
const validationView = computed(() => route.name === 'finance.payments-validations');
const pageTitle = computed(() => validationView.value ? 'Pending validations' : 'Payment records');
const pageDescription = computed(() => validationView.value
  ? 'Whole payment groups awaiting a Finance checker decision, oldest first. Each decision remains atomic across every component.'
  : 'Recorded groups across their lifecycle. References stay masked and recognized money changes only after server validation.');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.groups.length === 0) return 'empty';
  return 'success';
});

function statusLabel(status: string): string {
  return status === 'pending_validation' ? 'Pending validation' : status === 'recorded' ? 'Held — duplicate review' : status;
}

function load(page = filters.page): void {
  filters.page = page;
  const params: Record<string, string | number> = {
    page,
    per_page: 20,
    sort: validationView.value ? 'recorded_at' : '-recorded_at',
  };
  if (validationView.value) params.status = 'pending_validation';
  void store.fetchGroups(params);
}

onMounted(() => load());
</script>

<template>
  <section class="mx-auto max-w-6xl" :data-testid="validationView ? 'finance-pending-validations' : 'finance-payment-records'">
    <SvPageHeader :title="pageTitle" eyebrow="Merchant-client finance" :description="pageDescription" />

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No payment recordings yet."
      error-message="We couldn’t load payment recordings."
      @retry="load()"
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
                    :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: group.id } }"
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

      <SvPagination
        v-if="store.groupMeta && store.groupMeta.last_page > 1"
        class="mt-5"
        :current-page="store.groupMeta.current_page"
        :last-page="store.groupMeta.last_page"
        :total="store.groupMeta.total"
        :per-page="store.groupMeta.per_page"
        @change="load"
      />
    </SvStateBoundary>
  </section>
</template>
