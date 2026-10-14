<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { SvIconClock, SvIconLocked } from '@/design-system/icons';
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

/** Entries grouped by their server status, in the server's own order — presentation only. */
const lanes = computed(() => {
  const groups = new Map<(typeof queue.entries)[number]['status'], typeof queue.entries>();
  for (const entry of queue.entries) groups.set(entry.status, [...(groups.get(entry.status) ?? []), entry]);
  return [...groups.entries()].map(([status, entries]) => ({ status, label: queueStatusLabel(status), entries }));
});

onMounted(() => {
  void queue.fetchQueue();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — read-only queue LANES. Entries are grouped by their server status into
    lanes (a presentation grouping; the server's order is kept inside each lane), each card leading
    with its position and carrying the wait estimate as a delay cue. There is no drag, drop,
    transfer, call or completion control: Front Office runs the queue.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Branch queue"
      eyebrow="Operational visibility"
      description="A read view of who is waiting and in service at your branch. Front Office runs the queue."
    >
      <template #actions>
        <span class="sv-r-chip">
          <SvIconLocked
            aria-hidden="true"
            class="h-3.5 w-3.5"
          />
          Read-only
        </span>
        <RouterLink
          :to="{ name: 'branch.queue-configuration' }"
          class="sv-r-action sv-r-action--quiet sv-focus-ring"
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
      <div
        class="grid gap-4 md:grid-cols-2 lg:grid-cols-3"
        aria-label="Branch queue"
        role="list"
      >
        <section
          v-for="lane in lanes"
          :key="lane.status"
          role="listitem"
          class="sv-r-panel sv-r-panel--sunken"
          :aria-label="lane.label"
        >
          <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
            {{ lane.label }} · {{ lane.entries.length }}
          </p>
          <ul class="flex flex-col gap-3">
            <li
              v-for="entry in lane.entries"
              :key="entry.id"
            >
              <article class="sv-r-record">
                <div class="flex items-start gap-3">
                  <span class="sv-r-queue__count sv-r-tone-green">#{{ entry.position }}</span>
                  <div class="min-w-0 flex-1">
                    <h2 class="break-words font-display text-base font-bold text-heading">
                      {{ entry.client?.full_name ?? 'Client' }}
                    </h2>
                    <p class="mt-0.5 text-sm text-text-muted">
                      {{ entry.service?.name }}
                      · {{ assignmentModeLabel(entry.assignment_mode) }}
                      <span v-if="entry.assigned_personnel"> · {{ entry.assigned_personnel.display_name }}</span>
                    </p>
                  </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                  <span class="sv-r-chip">
                    <SvIconClock
                      aria-hidden="true"
                      class="h-3.5 w-3.5"
                    />
                    {{ waitEstimateLabel(entry.estimated_wait.effective_minutes) }}
                  </span>
                  <span
                    class="sv-r-chip sv-r-chip--tone sv-r-chip--dot sv-r-tone-green"
                    data-testid="queue-status-badge"
                  >{{ queueStatusLabel(entry.status) }}</span>
                </div>
              </article>
            </li>
          </ul>
        </section>
      </div>
    </SvStateBoundary>
  </section>
</template>