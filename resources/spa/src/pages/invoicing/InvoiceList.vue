<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
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

const isFinance = computed(() => route.meta.roleIdentity === 'merchant_finance');
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

function invoiceTone(status: string): SvStatusTone {
  if (status === 'paid') return 'success';
  if (status === 'voided') return 'error';
  if (status === 'partially_paid' || status === 'adjusted') return 'warning';
  return status === 'issued' ? 'info' : 'neutral';
}

function detailRoute(id: string): { name: string; params: Record<string, string> } {
  return isFinance.value
    ? { name: 'finance.invoices.detail', params: { id } }
    : { name: 'front-office.invoice-detail', params: { invoiceUlid: id } };
}

function goCreate(): void {
  void router.push({ name: 'front-office.invoices-create' });
}

onMounted(() => {
  void store.fetchInvoices();
});
</script>

<template>
  <section :class="isFinance ? 'p-4 md:p-6' : 'mx-auto max-w-6xl'">
    <SvOperationalHero
      v-if="!isFinance"
      eyebrow="Billing handoff"
      title="Invoices"
      description="Create invoices only from completed service truth, then record client payment evidence without claiming Finance validation or receipt issuance."
    >
      <template #actions>
        <SvButton
          v-if="canCreate"
          data-testid="new-invoice"
          @click="goCreate"
        >
          New invoice
        </SvButton>
      </template>
    </SvOperationalHero>
    <div
      v-else
      class="flex flex-wrap items-center justify-between gap-3"
    >
      <h1 class="font-display text-2xl font-bold text-heading">
        Invoices
      </h1>
    </div>

    <SvCard
      class="mt-5 flex flex-wrap items-end justify-between gap-3"
      padding="md"
    >
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
          Invoice register
        </p>
        <p class="mt-1 text-sm text-heading">
          Server totals and status remain authoritative.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <SvSelect
          id="invoice-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full md:w-56"
          @update:model-value="() => store.fetchInvoices()"
        />
      </div>
    </SvCard>

    <div class="mt-5">
      <SvStateBoundary
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
                      class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control pr-3 hover:underline"
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
                  <SvStatusBadge
                    class="mt-1"
                    :label="invoiceStatusLabel(invoice.status)"
                    :tone="invoiceTone(invoice.status)"
                    sr-prefix="Invoice status:"
                    data-testid="invoice-status-badge"
                  />
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>
    </div>
  </section>
</template>
