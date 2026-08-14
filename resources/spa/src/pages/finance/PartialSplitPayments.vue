<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { useFinanceWorkspaceStore } from '@/stores/financeWorkspaceStore';
import { formatDateTime } from '@/utils/dates';

const store = useFinanceWorkspaceStore();
const filters = reactive({ status: '', page: 1 });
const state = computed(() => (store.partialSplitLoading ? 'loading' : store.partialSplitError ? 'error' : store.partialSplitInvoices.length ? 'success' : 'empty'));
const statusOptions = [
  { value: '', label: 'All partial and split invoices' },
  { value: 'issued', label: 'Issued' },
  { value: 'partially_paid', label: 'Partially paid' },
  { value: 'paid', label: 'Paid' },
  { value: 'refund_pending', label: 'Refund pending' },
];

function load(): void {
  const params: Record<string, string | number> = { page: filters.page, per_page: 15, sort: '-created_at' };
  if (filters.status) params.status = filters.status;
  void store.fetchPartialSplit(params);
}

function applyFilters(): void {
  filters.page = 1;
  load();
}

onMounted(load);
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="finance-partial-split-payments"
  >
    <SvPageHeader
      title="Partial and split payments"
      eyebrow="Balance waterfall"
      description="Review invoices paid across several groups or methods. The server supplies every amount and the whole recording group remains the validation unit."
    >
      <template #actions>
        <div class="w-64">
          <SvSelect
            id="partial-split-status"
            v-model="filters.status"
            label="Invoice state"
            :options="statusOptions"
            @update:model-value="applyFilters"
          />
        </div>
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="state"
      :error-message="store.partialSplitError ?? undefined"
      empty-message="No partial or split payment records match this view."
      @retry="load"
    >
      <div
        class="space-y-5"
        aria-label="Partial and split invoices"
      >
        <SvCard
          v-for="entry in store.partialSplitInvoices"
          :key="entry.invoice.id"
          as="article"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Invoice
              </p>
              <h2 class="mt-1 font-display text-lg font-bold text-heading">
                {{ entry.invoice.number ?? 'Number pending' }}
              </h2>
              <p class="mt-1 text-sm text-text-muted">
                {{ entry.group_count }} payment group<template v-if="entry.group_count !== 1">
                  s
                </template> · {{ entry.has_multi_method_group ? 'multi-method split' : 'partial sequence' }}
              </p>
            </div>
            <SvStatusBadge :label="entry.invoice.status.replaceAll('_', ' ')" />
          </div>

          <div
            class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
            aria-label="Invoice balance waterfall"
          >
            <div class="rounded-control bg-surface-alt p-3">
              <p class="text-xs text-text-muted">
                Issued total
              </p><p class="mt-1 font-bold text-heading">
                {{ entry.balance.total.formatted }}
              </p>
            </div>
            <div class="rounded-control bg-sv-success-bg p-3">
              <p class="text-xs text-sv-success-fg">
                Validated
              </p><p class="mt-1 font-bold text-heading">
                {{ entry.balance.validated.formatted }}
              </p>
            </div>
            <div class="rounded-control bg-sv-warning-bg p-3">
              <p class="text-xs text-sv-warning-fg">
                Recorded, pending
              </p><p class="mt-1 font-bold text-heading">
                {{ entry.balance.pending_recorded.formatted }}
              </p>
            </div>
            <div class="rounded-control bg-sv-info-bg p-3">
              <p class="text-xs text-sv-info-fg">
                Remaining
              </p><p class="mt-1 font-bold text-heading">
                {{ entry.balance.remaining.formatted }}
              </p>
            </div>
          </div>

          <ol
            class="mt-5 space-y-3"
            aria-label="Payment group sequence"
          >
            <li
              v-for="(group, index) in entry.groups"
              :key="group.id"
              class="rounded-control border border-sv-border p-3"
            >
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                    Group {{ index + 1 }} · {{ group.status.replaceAll('_', ' ') }}
                  </p>
                  <p class="mt-1 text-sm text-text-muted">
                    {{ group.maker }}<template v-if="group.recorded_at">
                      · {{ formatDateTime(group.recorded_at) }}
                    </template>
                  </p>
                </div>
                <div class="text-right">
                  <p class="font-bold text-heading">
                    {{ group.total.formatted }}
                  </p>
                  <p class="text-xs text-text-muted">
                    {{ group.receipt ? `Receipt ${group.receipt.number}` : 'No receipt before validation' }}
                  </p>
                </div>
              </div>
              <ul
                class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3"
                aria-label="Payment components"
              >
                <li
                  v-for="component in group.components"
                  :key="component.id"
                  class="rounded-control bg-surface-alt p-3 text-sm"
                >
                  <div class="flex items-center justify-between gap-2">
                    <strong class="capitalize text-heading">{{ component.method.replaceAll('_', ' ') }}</strong><span class="font-semibold text-heading">{{ component.amount.formatted }}</span>
                  </div>
                  <p class="mt-1 text-xs text-text-muted">
                    {{ component.reference_masked ?? 'No reference required' }}
                  </p>
                  <p
                    v-if="component.duplicate_risk"
                    class="mt-2 text-xs font-semibold text-sv-warning-fg"
                  >
                    Duplicate review required
                  </p>
                </li>
              </ul>
              <div class="mt-3 flex justify-end">
                <RouterLink
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-3 py-2 text-sm font-semibold text-heading underline"
                  :to="{ name: 'finance.payments-validation-detail', params: { groupUlid: group.id } }"
                >
                  Open group
                </RouterLink>
              </div>
            </li>
          </ol>
        </SvCard>
      </div>

      <SvPagination
        v-if="store.partialSplitMeta && store.partialSplitMeta.last_page > 1"
        class="mt-5"
        :current-page="store.partialSplitMeta.current_page"
        :last-page="store.partialSplitMeta.last_page"
        :total="store.partialSplitMeta.total"
        :per-page="store.partialSplitMeta.per_page"
        @change="(page) => { filters.page = page; load(); }"
      />
    </SvStateBoundary>
  </section>
</template>
