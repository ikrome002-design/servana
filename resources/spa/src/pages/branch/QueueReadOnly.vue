<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useQueueStore } from '@/stores/queueStore';
import { assignmentModeLabel, queueStatusLabel, waitEstimateLabel } from '@/utils/queue';

// Branch Manager read-only queue (Plan §37; Phase 16B). Visibility only — no
// create/assign/transfer/reorder/call/start/complete/cancel/no-show controls. The
// backend rejects any Branch Manager queue mutation regardless. Client contact is
// masked. Queue configuration lives on a separate screen.
const queue = useQueueStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (queue.loading) return 'loading';
  if (queue.error) return 'error';
  if (queue.entries.length === 0) return 'empty';
  return 'success';
});

onMounted(() => {
  void queue.fetchQueue();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Branch queue
      </h1>
      <RouterLink
        :to="{ name: 'branch.queue-configuration' }"
        class="text-sm font-semibold text-heading underline"
      >
        Queue settings
      </RouterLink>
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No one is in the queue right now."
      error-message="We couldn’t load the queue."
      @retry="() => queue.fetchQueue()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Branch queue"
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
                  · {{ assignmentModeLabel(entry.assignment_mode) }}
                  <span v-if="entry.assigned_personnel"> · {{ entry.assigned_personnel.display_name }}</span>
                </p>
                <p class="mt-0.5 text-xs text-text-muted">
                  {{ waitEstimateLabel(entry.estimated_wait.effective_minutes) }}
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
