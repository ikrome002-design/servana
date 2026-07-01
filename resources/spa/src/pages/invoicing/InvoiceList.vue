<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useInvoiceStore } from '@/stores/invoiceStore';
import { usePermissionStore } from '@/stores/permissionStore';
import { invoiceStatusLabel } from '@/utils/invoice';

// Invoice list (Plan §40; Phase 17). Shared by Front Office and Finance — client
// contact is masked, money is server-formatted KES, and "New invoice" appears only
// for a user holding invoice.create (Front Office). NO payment or receipt control
// appears here (Phase 18).
const store = useInvoiceStore();
const permissions = usePermissionStore();
const route = useRoute();
const router = useRouter();

const isFinance = computed(() => route.path.startsWith('/finance'));
const canCreate = computed(() => permissions.can('invoice.create'));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.invoices.length === 0) return 'empty';
  return 'success';
});

const statusOptions = [
  { value: '', label: 'All invoices' },
  { value: 'draft', label: 'Draft' },
  { value: 'issued', label: 'Issued' },
  { value: 'voided', label: 'Voided' },
  { value: 'adjusted', label: 'Adjusted' },
];

function detailRoute(id: string): { name: string; params: { id: string } } {
  return { name: isFinance.value ? 'finance.invoices.detail' : 'front-office.invoices.detail', params: { id } };
}

function goCreate(): void {
  void router.push({ name: 'front-office.invoices.create' });
}

onMounted(() => {
  void store.fetchInvoices();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Invoices
      </h1>
      <div class="flex flex-wrap items-center gap-2">
        <SvSelect
          id="invoice-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full sm:w-56"
          @update:model-value="() => store.fetchInvoices()"
        />
        <SvButton
          v-if="canCreate"
          data-testid="new-invoice"
          @click="goCreate"
        >
          New invoice
        </SvButton>
      </div>
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No invoices match this filter."
      error-message="We couldn’t load invoices."
      @retry="() => store.fetchInvoices()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Invoices"
      >
        <li
          v-for="invoice in store.invoices"
          :key="invoice.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  <RouterLink
                    :to="detailRoute(invoice.id)"
                    class="hover:underline focus-visible:underline"
                  >
                    {{ invoice.invoice_number ?? 'Draft' }}
                  </RouterLink>
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ invoice.client?.full_name ?? 'Client' }}
                </p>
              </div>
              <div class="text-right">
                <p class="font-display text-base font-semibold text-heading">
                  {{ invoice.total.formatted }}
                </p>
                <span
                  class="mt-1 inline-block rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                  data-testid="invoice-status-badge"
                >{{ invoiceStatusLabel(invoice.status) }}</span>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
