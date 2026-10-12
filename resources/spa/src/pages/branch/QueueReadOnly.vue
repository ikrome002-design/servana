<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useQueueStore } from '@/stores/queueStore';
import { assignmentModeLabel, queueStatusLabel, waitEstimateLabel } from '@/utils/queue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

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
  <section class="mx-auto max-w-6xl">
    <SvPageHeader
      title="Branch queue"
      eyebrow="Operational visibility"
      description="A read view of who is waiting and in service at your branch. Front Office runs the queue."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'branch.queue-configuration' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border-strong bg-sv-surface-raised px-4 py-2 text-sm font-semibold text-sv-text-heading hover:bg-sv-surface-subtle"
        >
          Queue settings
        </RouterLink>
      </template>
    </SvPageHeader>

    <SvStateBoundary
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
