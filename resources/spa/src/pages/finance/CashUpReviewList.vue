<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useCashUpStore } from '@/stores/cashUpStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

/**
 * Finance cash-up review inbox (Plan §45; Phase 18B). Finance (checker) reviews the
 * cash-ups branch managers submit and approves / rejects / requests correction. Expected
 * totals are server-derived; variance is shown per row. The maker can never approve.
 */
const store = useCashUpStore();
const router = useRouter();

const statusOptions = [
  { value: '', label: 'All cash-ups' },
  { value: 'submitted', label: 'Awaiting review' },
  { value: 'approved', label: 'Approved' },
  { value: 'correction_requested', label: 'Correction requested' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'locked', label: 'Locked' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.cashUps.length === 0) return 'empty';
  return 'success';
});

onMounted(() => {
  void store.fetchCashUps();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — cash-up review as a RECONCILIATION SHEET. Each submitted business day
    is one ledger row: expected (server-derived), counted (branch), variance (server-computed and
    flagged when non-zero) and status, so the checker reads the sheet top to bottom. The rail keeps
    the branch-counts / Finance-checks separation; approval happens on the detail.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Cash-up and reconciliation"
      eyebrow="Controls & Close"
      description="Branch cash-ups submitted for checking. Approve or reject each one against the totals Servana expects; the branch counts, Finance checks."
    >
      <template #actions>
        <SvSelect
          id="cash-up-status-filter"
          v-model="store.filterStatus"
          label="Filter"
          :options="statusOptions"
          class="w-full md:w-56"
          @update:model-value="() => store.fetchCashUps()"
        />
      </template>
    </SvPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load cash-ups."
      empty-message="No cash-ups match this filter."
    >
      <div class="sv-r-grid sv-r-split">
        <section
          class="sv-r-ledger min-w-0"
          style="--sv-r-ledger-cols: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr)"
          aria-labelledby="cash-up-sheet-heading"
        >
          <div class="sv-r-ledger__head">
            <h2
              id="cash-up-sheet-heading"
              class="sv-r-title"
            >
              Reconciliation sheet
            </h2>
            <span class="sv-r-chip">{{ store.cashUps.filter((item) => item.variance_minor !== 0).length }} with a variance</span>
          </div>
          <div
            class="sv-r-ledger__colhead"
            aria-hidden="true"
          >
            <span>Business day</span><span class="text-right">Expected</span><span class="text-right">Counted</span><span class="text-right">Variance</span><span class="text-right">Status</span>
          </div>
          <ul>
            <li
              v-for="cashUp in store.cashUps"
              :key="cashUp.id ?? cashUp.business_date"
              class="border-b border-sv-border last:border-b-0"
            >
              <button
                type="button"
                class="sv-r-ledger__row sv-focus-ring w-full border-b-0 text-left text-sm"
                data-testid="cash-up-row"
                @click="() => cashUp.id && router.push({ name: 'finance.cash-up.detail', params: { id: cashUp.id } })"
              >
                <span class="font-display text-base font-bold text-heading">{{ cashUp.business_date }}</span>
                <span class="sv-numeric text-text md:text-right"><span class="md:sr-only">Expected </span>{{ cashUp.expected.formatted }}</span>
                <span class="sv-numeric text-text md:text-right"><span class="md:sr-only">Counted </span>{{ cashUp.counted.formatted }}</span>
                <span
                  class="sv-numeric font-bold md:text-right"
                  :class="cashUp.variance_minor === 0 ? 'text-heading' : 'text-sv-warning-fg'"
                ><span class="md:sr-only">Variance </span>{{ cashUp.variance.formatted }}</span>
                <span class="md:text-right">
                  <span class="inline-flex rounded-pill border border-sv-border bg-surface-alt px-2.5 py-0.5 text-xs font-semibold capitalize text-text">{{ cashUp.status.replaceAll('_', ' ') }}</span>
                </span>
              </button>
            </li>
          </ul>
        </section>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Cash-up rules"
        >
          <section class="sv-r-note sv-r-tone-teal">
            <h2 class="sv-r-note__title">
              The branch counts, Finance checks
            </h2>
            <p class="sv-r-note__body">
              Expected totals and variances come from the server. The person who submitted a cash-up can never approve it.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-sun">
            <h2 class="sv-r-note__title">
              Before you lock a period
            </h2>
            <p class="sv-r-note__body">
              Resolve every cash-up inside the period first; a locked period blocks changes dated inside it.
            </p>
            <RouterLink
              class="sv-r-link sv-focus-ring mt-2 rounded-control"
              :to="{ name: 'finance.periods' }"
            >
              Financial periods
            </RouterLink>
          </section>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>
