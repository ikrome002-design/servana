<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useQueueStore } from '@/stores/queueStore';
import type { QueueEntry } from '@/types/models';
import { assignmentModeLabel, queueStatusLabel, waitEstimateLabel } from '@/utils/queue';
import { SvIconArrowDown, SvIconArrowUp } from '@/design-system/icons';

// Front Office queue board (Plan §37; Phase 16B). Branch-scoped; client contact is
// ALWAYS masked by the server. Action availability is driven by each entry's API
// `can` map (UX only) — the API re-checks every mutation. Reorder uses
// keyboard-accessible move-up / move-down controls (no drag-only input).
const queue = useQueueStore();
const notifications = useNotificationStore();
const working = ref<string | null>(null);

const statusOptions = [
  { value: '', label: 'All active' },
  { value: 'waiting', label: 'Waiting' },
  { value: 'assigned', label: 'Assigned' },
  { value: 'called', label: 'Called' },
  { value: 'in_service', label: 'In service' },
];

function queueTone(status: string): SvStatusTone {
  if (status === 'waiting') return 'warning';
  if (status === 'in_service' || status === 'completed') return 'success';
  if (status === 'cancelled' || status === 'no_show') return 'error';
  return 'info';
}

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (queue.loading) return 'loading';
  if (queue.error) return 'error';
  if (queue.entries.length === 0) return 'empty';
  return 'success';
});

const waitingIds = computed(() =>
  queue.entries.filter((e) => e.status === 'waiting').sort((a, b) => a.position - b.position).map((e) => e.id),
);

async function run(action: () => Promise<unknown>, id: string, message: string): Promise<void> {
  working.value = id;
  try {
    await action();
    await queue.fetchQueue();
    notifications.addToast({ type: 'success', message });
  } catch (err: unknown) {
    const m = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Something went wrong.';
    notifications.addToast({ type: 'error', message: m });
  } finally {
    working.value = null;
  }
}

const callEntry = (e: QueueEntry): Promise<void> => run(() => queue.transition(e.id, 'call'), e.id, 'Client called.');
const startEntry = (e: QueueEntry): Promise<void> => run(() => queue.transition(e.id, 'start'), e.id, 'Service started.');
const completeEntry = (e: QueueEntry): Promise<void> => run(() => queue.transition(e.id, 'complete'), e.id, 'Service completed.');
const noShowEntry = (e: QueueEntry): Promise<void> => run(() => queue.transition(e.id, 'no-show'), e.id, 'Marked as no-show.');
const assignNext = (e: QueueEntry): Promise<void> => run(() => queue.assign(e.id, 'next_available'), e.id, 'Assigned next available.');

async function move(id: string, direction: -1 | 1): Promise<void> {
  const order = [...waitingIds.value];
  const i = order.indexOf(id);
  const j = i + direction;
  if (i < 0 || j < 0 || j >= order.length) return;
  [order[i], order[j]] = [order[j], order[i]];
  await run(() => queue.reorder(order), id, 'Queue reordered.');
}

onMounted(() => {
  void queue.fetchQueue();
});
</script>

<template>
  <section class="mx-auto max-w-6xl">
    <SvOperationalHero
      eyebrow="Live service flow"
      title="Queue"
      description="Keep waiting, assigned, called and in-service states visually distinct. Every assignment and transfer is rechecked against branch scope and service eligibility by the server."
    >
      <template #actions>
        <PermissionGate permission="queue.create">
          <RouterLink
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-bold text-brand-deep"
            :to="{ name: 'front-office.walk-ins' }"
            data-testid="start-walk-in"
          >
            Start a walk-in
          </RouterLink>
        </PermissionGate>
      </template>
      <div class="flex flex-wrap gap-3 text-sm text-white/80">
        <span class="rounded-full bg-white/10 px-3 py-1.5">{{ queue.entries.length }} visible entries</span>
        <span class="rounded-full bg-white/10 px-3 py-1.5">Assigned branch only</span>
      </div>
    </SvOperationalHero>

    <SvCard
      as="form"
      class="mt-5 flex flex-wrap items-end gap-3"
      padding="md"
      novalidate
      @submit.prevent="queue.fetchQueue()"
    >
      <div class="w-56">
        <SvSelect
          id="queue-status"
          v-model="queue.filterStatus"
          label="Status"
          :options="statusOptions"
        />
      </div>
      <SvButton
        type="submit"
        variant="secondary"
        data-testid="filter-queue"
      >
        Apply
      </SvButton>
    </SvCard>

    <div class="mt-5">
      <SvStateBoundary
        :state="boundaryState"
        empty-message="The queue is empty. Start a walk-in to add someone."
        error-message="We couldn’t load the queue."
        @retry="() => queue.fetchQueue()"
      >
        <ul
          class="flex flex-col gap-3"
          aria-label="Queue entries"
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
                    <span
                      v-if="entry.estimated_wait.override_minutes !== null"
                      class="font-semibold"
                    >· overridden</span>
                    <span v-if="entry.preferred_personnel"> · prefers {{ entry.preferred_personnel.display_name }}</span>
                  </p>
                </div>
                <SvStatusBadge
                  :label="queueStatusLabel(entry.status)"
                  :tone="queueTone(entry.status)"
                  sr-prefix="Queue status:"
                  data-testid="queue-status-badge"
                />
              </div>

              <div class="mt-3 flex flex-wrap gap-2">
                <SvButton
                  v-if="entry.status === 'waiting' && entry.can?.assign"
                  variant="primary"
                  :loading="working === entry.id"
                  :data-testid="`assign-${entry.id}`"
                  @click="assignNext(entry)"
                >
                  Assign next available
                </SvButton>
                <SvButton
                  v-if="entry.can?.call"
                  variant="primary"
                  :loading="working === entry.id"
                  @click="callEntry(entry)"
                >
                  Call
                </SvButton>
                <SvButton
                  v-if="entry.can?.start"
                  variant="primary"
                  :loading="working === entry.id"
                  @click="startEntry(entry)"
                >
                  Start
                </SvButton>
                <SvButton
                  v-if="entry.can?.complete"
                  variant="primary"
                  :loading="working === entry.id"
                  @click="completeEntry(entry)"
                >
                  Complete
                </SvButton>
                <RouterLink
                  :to="{ name: 'front-office.queue-entry', params: { queueUlid: entry.id } }"
                  class="inline-flex min-h-[44px] items-center text-sm font-semibold text-heading underline"
                >
                  Manage
                </RouterLink>
                <RouterLink
                  v-if="entry.can?.transfer"
                  :to="{ name: 'front-office.queue-transfer', params: { queueUlid: entry.id } }"
                  class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control border border-sv-border px-3 text-sm font-semibold text-heading"
                >
                  Transfer
                </RouterLink>
                <SvButton
                  v-if="entry.can?.no_show"
                  variant="secondary"
                  :loading="working === entry.id"
                  @click="noShowEntry(entry)"
                >
                  No-show
                </SvButton>
                <template v-if="entry.status === 'waiting' && entry.can?.assign">
                  <SvButton
                    variant="secondary"
                    :disabled="waitingIds[0] === entry.id"
                    :aria-label="`Move ${entry.client?.full_name ?? 'entry'} up`"
                    :data-testid="`move-up-${entry.id}`"
                    @click="move(entry.id, -1)"
                  >
                    <SvIconArrowUp
                      aria-hidden="true"
                      class="h-5 w-5"
                    />
                  </SvButton>
                  <SvButton
                    variant="secondary"
                    :disabled="waitingIds[waitingIds.length - 1] === entry.id"
                    :aria-label="`Move ${entry.client?.full_name ?? 'entry'} down`"
                    :data-testid="`move-down-${entry.id}`"
                    @click="move(entry.id, 1)"
                  >
                    <SvIconArrowDown
                      aria-hidden="true"
                      class="h-5 w-5"
                    />
                  </SvButton>
                </template>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>
    </div>
  </section>
</template>
