<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { RouterLink } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useFlaggedEventStore, type FlaggedStatus } from '@/stores/flaggedEventStore';

/**
 * Flagged-event queue (Plan §13.2, §80; Phase 19). Branch-scoped list of flagged
 * audit events across the review lifecycle. Read-only over the source audit rows;
 * the review workflow lives on the detail screen.
 */
const store = useFlaggedEventStore();

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'open', label: 'Open' },
  { value: 'under_review', label: 'Under review' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'dismissed', label: 'Dismissed' },
  { value: 'reopened', label: 'Reopened' },
];

const statusLabels: Record<FlaggedStatus, string> = {
  open: 'Open',
  under_review: 'Under review',
  resolved: 'Resolved',
  dismissed: 'Dismissed',
  reopened: 'Reopened',
};

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.items.length === 0) return 'empty';
  return 'success';
});

onMounted(() => {
  void store.fetchAll();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-heading">
          Flagged events
        </h1>
        <p class="mt-1 text-sm text-text-muted">
          Branch-scoped review queue. Source events remain read-only.
        </p>
      </div>
      <SvSelect
        id="flagged-status-filter"
        v-model="store.filterStatus"
        label="Status"
        :options="statusOptions"
        class="w-full sm:w-56"
        @update:model-value="() => store.fetchAll()"
      />
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load flagged events."
      empty-message="No flagged events match this filter."
      @retry="() => store.fetchAll()"
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="item in store.items"
          :key="item.id"
        >
          <SvCard
            as="article"
            padding="md"
            data-testid="flagged-row"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-display font-semibold text-heading">
                  {{ item.audit_event?.action ?? 'Flagged event' }}
                </p>
                <p class="text-sm text-text-muted">
                  <span
                    class="inline-flex items-center rounded-control bg-surface-alt px-2 py-0.5 text-xs font-medium"
                    data-testid="flagged-status"
                  >
                    {{ statusLabels[item.status] }}
                  </span>
                  <span v-if="item.assigned_to"> · {{ item.assigned_to }}</span>
                </p>
                <p class="text-xs text-text-muted">
                  Updated {{ item.updated_at }}
                </p>
              </div>
              <RouterLink
                :to="{ name: 'audit.flagged-detail', params: { id: item.id } }"
                class="inline-flex min-h-[44px] items-center rounded-control px-3 text-sm font-medium text-heading underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                data-testid="flagged-open"
              >
                Review
              </RouterLink>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
