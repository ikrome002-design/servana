<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
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
  <section class="mx-auto max-w-6xl">
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
      <ul class="flex flex-col gap-2">
        <li
          v-for="cashUp in store.cashUps"
          :key="cashUp.id ?? cashUp.business_date"
        >
          <SvCard
            as="button"
            padding="md"
            class="w-full text-left"
            data-testid="cash-up-row"
            @click="() => cashUp.id && router.push({ name: 'finance.cash-up.detail', params: { id: cashUp.id } })"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ cashUp.business_date }}
                </p>
                <p class="text-sm text-text-muted">
                  Expected {{ cashUp.expected.formatted }} · Counted {{ cashUp.counted.formatted }}
                </p>
              </div>
              <div class="text-right">
                <p
                  class="font-semibold"
                  :class="cashUp.variance_minor === 0 ? 'text-heading' : 'text-sv-warning-fg'"
                >
                  Variance {{ cashUp.variance.formatted }}
                </p>
                <span class="rounded bg-surface-alt px-2 py-0.5 text-xs text-text-muted">{{ cashUp.status }}</span>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
