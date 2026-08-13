<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { formatMoney } from '@/utils/money';

const props = defineProps<{ kind: 'invoices' | 'payments' }>();
const store = useBranchExperienceStore();
const rows = computed(() => props.kind === 'invoices' ? store.invoices : store.payments);
const title = computed(() => props.kind === 'invoices' ? 'Invoices' : 'Payment records');
const description = computed(() => props.kind === 'invoices'
  ? 'Read-only branch invoice position. Front Office creates invoices; Branch cannot create, edit, finalize, void or adjust them.'
  : 'Read-only recording and validation status. Front Office records payments and Finance validates or rejects them.');
const state = computed(() => store.loading ? 'loading' : store.error ? 'error' : rows.value.length ? 'success' : 'empty');

function load(): Promise<void> {
  return props.kind === 'invoices'
    ? store.fetchInvoices({ sort: '-created_at' })
    : store.fetchPayments({ sort: '-created_at' });
}

onMounted(load);
watch(() => props.kind, load);
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    :data-testid="`branch-${kind}`"
  >
    <SvPageHeader
      :title="title"
      eyebrow="Financial visibility"
      :description="description"
    />
    <div
      class="mb-4 rounded-control border border-sv-info-border bg-sv-info-bg px-4 py-3 text-sm text-sv-info-fg"
      role="note"
    >
      Context only: this workspace exposes no payment references, client contact, maker identity or financial mutation controls.
    </div>
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      :empty-message="`No branch ${kind === 'invoices' ? 'invoices' : 'payment records'} are available.`"
      @retry="load"
    >
      <ul
        class="grid gap-3"
        :aria-label="title"
      >
        <li
          v-for="row in rows"
          :key="row.id"
        >
          <SvCard
            as="article"
            class="grid gap-3 md:grid-cols-[1.2fr_.8fr_.8fr] md:items-center"
          >
            <div>
              <p class="font-display font-bold text-heading">
                {{ kind === 'invoices' ? ('invoice_number' in row && row.invoice_number ? row.invoice_number : 'Draft invoice') : ('invoice' in row ? row.invoice?.invoice_number ?? 'Payment record' : 'Payment record') }}
              </p>
              <p class="mt-1 text-xs text-text-muted">
                {{ row.created_at ? new Date(row.created_at).toLocaleString() : 'Date unavailable' }}
              </p>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                Status
              </p><p class="mt-1 font-semibold capitalize text-heading">
                {{ row.status.replaceAll('_', ' ') }}
              </p>
            </div>
            <div class="md:text-right">
              <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                {{ kind === 'invoices' ? 'Balance' : 'Recorded' }}
              </p><p class="mt-1 font-bold text-heading">
                {{ formatMoney(kind === 'invoices' && 'balance_minor' in row ? row.balance_minor : 'total_amount_minor' in row ? row.total_amount_minor : 0, row.currency) }}
              </p>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
