<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePersonnelQueueStore } from '@/stores/queueStore';
import { queueStatusLabel } from '@/utils/queue';

// Personnel own-scope queue (Plan §37, §19; Phase 16B). Shows ONLY entries assigned
// to the authenticated Personnel user (enforced server-side). Read-only: no
// branch-wide filter, no staff selector, no mutation controls, no contact export.
// Client contact is masked to the minimum needed to perform the work.
const queue = usePersonnelQueueStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (queue.loading) return 'loading';
  if (queue.error) return 'error';
  if (queue.entries.length === 0) return 'empty';
  return 'success';
});

onMounted(() => {
  void queue.fetchMine();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      My queue
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="You have no one in your queue right now."
      error-message="We couldn’t load your queue."
      @retry="() => queue.fetchMine()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="My queue"
      >
        <li
          v-for="entry in queue.entries"
          :key="entry.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  <span class="text-text-muted">#{{ entry.position }}</span>
                  {{ entry.client?.full_name ?? 'Client' }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ entry.service?.name }}
                  <span v-if="entry.is_preferred_request"> · requested you</span>
                </p>
                <p class="mt-0.5 text-xs text-text-muted">
                  {{ entry.estimated_wait.label }} · ~{{ entry.estimated_wait.effective_minutes }} min
                </p>
              </div>
              <span
                class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                data-testid="queue-status-badge"
              >{{ queueStatusLabel(entry.status) }}</span>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
