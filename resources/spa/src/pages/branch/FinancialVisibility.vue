<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { SvIconLocked } from '@/design-system/icons';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useBranchExperienceStore } from '@/stores/branchExperienceStore';
import { formatMoney } from '@/utils/money';
import { formatDateTime } from '@/utils/dates';

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
  <!--
    COR-UI08-UI12-VIS-001 — a read-only finance LEDGER. A locked banner states what this workspace
    can never do; rows set reference and time, a status chip, and the server's amount right-aligned.
    No record, validate, edit, void or adjust control exists here — Front Office records, Finance
    checks.
  -->
  <section
    class="sv-r-page"
    :data-testid="`branch-${kind}`"
  >
    <SvPageHeader
      :title="title"
      eyebrow="Financial visibility"
      :description="description"
    />
    <div
      class="sv-r-record sv-r-record--spine sv-r-tone-teal mb-5 flex items-start gap-3 pl-6 text-sm text-sv-text-secondary"
      role="note"
    >
      <span
        aria-hidden="true"
        class="sv-r-icon sv-r-tone-teal"
      >
        <SvIconLocked />
      </span>
      <span class="pt-2">Context only: this workspace exposes no payment references, client contact, maker identity or financial mutation controls.</span>
    </div>
    <SvStateBoundary
      :state="state"
      :error-message="store.error ?? undefined"
      :empty-message="`No branch ${kind === 'invoices' ? 'invoices' : 'payment records'} are available.`"
      @retry="load"
    >
      <section
        class="sv-r-ledger"
        :aria-label="title"
      >
        <div class="sv-r-ledger__head">
          <p class="sv-r-title">
            {{ title }}
          </p>
          <span class="sv-r-chip">Read-only</span>
        </div>
        <div
          class="sv-r-ledger__colhead"
          style="--sv-r-ledger-cols: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr)"
          aria-hidden="true"
        >
          <span>Reference</span><span>Status</span><span class="text-right">{{ kind === 'invoices' ? 'Balance' : 'Recorded' }}</span>
        </div>
        <ul :aria-label="title">
          <!-- Each record is an <article> (the UI-10 contract reads `main article` as the record card). -->
          <li
            v-for="row in rows"
            :key="row.id"
            class="border-b border-sv-border last:border-b-0"
          >
            <article
              class="sv-r-ledger__row"
              style="--sv-r-ledger-cols: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr)"
            >
              <div>
                <p class="font-display font-bold text-heading">
                  {{ kind === 'invoices' ? ('invoice_number' in row && row.invoice_number ? row.invoice_number : 'Draft invoice') : ('invoice' in row ? row.invoice?.invoice_number ?? 'Payment record' : 'Payment record') }}
                </p>
                <p class="mt-1 text-xs text-text-muted">
                  {{ row.created_at ? formatDateTime(row.created_at) : 'Date unavailable' }}
                </p>
              </div>
              <div>
                <p class="sr-only">
                  Status
                </p><span
                  class="sv-r-chip sv-r-chip--tone sv-r-chip--dot capitalize"
                  :class="kind === 'invoices' ? 'sv-r-tone-teal' : 'sv-r-tone-sun'"
                >
                  {{ row.status.replaceAll('_', ' ') }}
                </span>
              </div>
              <div class="md:text-right">
                <p class="text-xs font-semibold uppercase tracking-wide text-text-muted md:sr-only">
                  {{ kind === 'invoices' ? 'Balance' : 'Recorded' }}
                </p><p class="sv-numeric font-display text-lg font-extrabold text-heading">
                  {{ formatMoney(kind === 'invoices' && 'balance_minor' in row ? row.balance_minor : 'total_amount_minor' in row ? row.total_amount_minor : 0, row.currency) }}
                </p>
              </div>
            </article>
          </li>
        </ul>
      </section>
    </SvStateBoundary>
  </section>
</template>