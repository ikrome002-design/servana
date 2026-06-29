<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { apiClient } from '@/services/apiClient';
import { useNotificationStore } from '@/stores/notificationStore';
import { useQueueStore } from '@/stores/queueStore';
import type { QueueEntry, ServiceEligibility } from '@/types/models';
import { assignmentModeLabel, queueStatusLabel, waitEstimateLabel } from '@/utils/queue';

// Front Office queue-entry detail with capability-gated actions (Plan §37; Phase
// 16B). Action availability is driven by the API `can` map (UX only); the API
// re-checks every mutation. Client contact is masked.
const route = useRoute();
const queue = useQueueStore();
const notifications = useNotificationStore();

const entry = ref<QueueEntry | null>(null);
const loading = ref(true);
const error = ref(false);
const activeDialog = ref<'assign' | 'transfer' | 'cancel' | null>(null);
const eligible = ref<ServiceEligibility[]>([]);
const personnelChoice = ref('');
const reason = ref('');
const working = ref(false);

const id = computed(() => String(route.params.id));
const can = computed(() => entry.value?.can);
const boundaryState = computed<'loading' | 'error' | 'success'>(() => {
  if (loading.value) return 'loading';
  if (error.value) return 'error';
  return 'success';
});
const personnelOptions = computed(() =>
  eligible.value
    .filter((e) => e.active && e.staff_profile_id)
    .map((e) => ({ value: e.staff_profile_id as string, label: e.staff_name ?? 'Personnel' })),
);

async function load(): Promise<void> {
  loading.value = true;
  error.value = false;
  try {
    entry.value = await queue.fetchEntry(id.value);
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

async function openAssign(dialog: 'assign' | 'transfer'): Promise<void> {
  personnelChoice.value = '';
  reason.value = '';
  eligible.value = [];
  const serviceId = entry.value?.service?.id;
  if (serviceId !== undefined) {
    try {
      const { data } = await apiClient.get<{ data: ServiceEligibility[] }>(`/services/${serviceId}/eligibility`);
      eligible.value = data.data;
    } catch {
      eligible.value = [];
    }
  }
  activeDialog.value = dialog;
}

function openCancel(): void {
  reason.value = '';
  activeDialog.value = 'cancel';
}

async function run(action: () => Promise<QueueEntry>, message: string): Promise<void> {
  working.value = true;
  try {
    entry.value = await action();
    activeDialog.value = null;
    notifications.addToast({ type: 'success', message });
  } catch (err: unknown) {
    const m = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Something went wrong.';
    notifications.addToast({ type: 'error', message: m });
  } finally {
    working.value = false;
  }
}

const submitAssign = (): Promise<void> => run(() => queue.assign(id.value, 'manual', personnelChoice.value), 'Personnel assigned.');
const submitTransfer = (): Promise<void> => run(() => queue.transfer(id.value, personnelChoice.value, reason.value), 'Entry transferred.');
const submitCancel = (): Promise<void> => run(() => queue.cancel(id.value, reason.value), 'Entry cancelled.');
const submitCall = (): Promise<void> => run(() => queue.transition(id.value, 'call'), 'Client called.');
const submitStart = (): Promise<void> => run(() => queue.transition(id.value, 'start'), 'Service started.');
const submitComplete = (): Promise<void> => run(() => queue.transition(id.value, 'complete'), 'Service completed.');
const submitNoShow = (): Promise<void> => run(() => queue.transition(id.value, 'no-show'), 'Marked as no-show.');

onMounted(load);
</script>

<template>
  <section class="mx-auto w-full max-w-2xl p-4 md:p-6">
    <RouterLink
      :to="{ name: 'front-office.queue' }"
      class="text-sm font-semibold text-heading underline"
    >
      ← Back to the queue
    </RouterLink>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load this queue entry."
      @retry="load"
    >
      <SvCard
        v-if="entry"
        as="div"
        padding="lg"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-bold text-heading">
            <span class="text-text-muted">#{{ entry.position }}</span>
            {{ entry.client?.full_name ?? 'Queue entry' }}
          </h1>
          <span
            class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
            data-testid="queue-status-badge"
          >{{ queueStatusLabel(entry.status) }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-text-muted">
              Service
            </dt>
            <dd class="font-medium text-text">
              {{ entry.service?.name }} ({{ entry.service?.duration_minutes }} min)
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Estimated wait
            </dt>
            <dd class="font-medium text-text">
              {{ waitEstimateLabel(entry.estimated_wait.effective_minutes) }}
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Client contact
            </dt>
            <dd class="font-medium text-text">
              {{ entry.client?.phone_masked }}
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Assigned personnel
            </dt>
            <dd class="font-medium text-text">
              {{ entry.assigned_personnel?.display_name ?? 'Unassigned' }}
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Assignment
            </dt>
            <dd class="font-medium text-text">
              {{ assignmentModeLabel(entry.assignment_mode) }}
            </dd>
          </div>
          <div v-if="entry.preferred_personnel">
            <dt class="text-text-muted">
              Preferred
            </dt>
            <dd class="font-medium text-text">
              {{ entry.preferred_personnel.display_name }}
            </dd>
          </div>
          <div v-if="entry.cancellation_reason">
            <dt class="text-text-muted">
              Reason
            </dt>
            <dd class="font-medium text-text">
              {{ entry.cancellation_reason }}
            </dd>
          </div>
        </dl>

        <div class="mt-6 flex flex-wrap gap-2">
          <SvButton
            v-if="can?.assign"
            variant="primary"
            data-testid="action-assign"
            @click="openAssign('assign')"
          >
            Assign personnel
          </SvButton>
          <SvButton
            v-if="can?.call"
            variant="primary"
            :loading="working"
            data-testid="action-call"
            @click="submitCall"
          >
            Call
          </SvButton>
          <SvButton
            v-if="can?.start"
            variant="primary"
            :loading="working"
            data-testid="action-start"
            @click="submitStart"
          >
            Start
          </SvButton>
          <SvButton
            v-if="can?.complete"
            variant="primary"
            :loading="working"
            data-testid="action-complete"
            @click="submitComplete"
          >
            Complete
          </SvButton>
          <SvButton
            v-if="can?.transfer"
            variant="secondary"
            data-testid="action-transfer"
            @click="openAssign('transfer')"
          >
            Transfer
          </SvButton>
          <SvButton
            v-if="can?.no_show"
            variant="secondary"
            :loading="working"
            data-testid="action-no-show"
            @click="submitNoShow"
          >
            Mark no-show
          </SvButton>
          <SvButton
            v-if="can?.cancel"
            variant="destructive"
            data-testid="action-cancel"
            @click="openCancel"
          >
            Cancel
          </SvButton>
        </div>
      </SvCard>
    </SvStateBoundary>

    <SvModal
      :open="activeDialog === 'assign' || activeDialog === 'transfer'"
      :title="activeDialog === 'transfer' ? 'Transfer queue entry' : 'Assign personnel'"
      description="Only eligible, available personnel can take this entry."
      @close="activeDialog = null"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="activeDialog === 'transfer' ? submitTransfer() : submitAssign()"
      >
        <SvSelect
          id="queue-personnel-choice"
          v-model="personnelChoice"
          label="Personnel"
          placeholder="Select personnel"
          :options="personnelOptions"
          required
        />
        <SvTextarea
          v-if="activeDialog === 'transfer'"
          id="queue-transfer-reason"
          v-model="reason"
          label="Reason"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="working"
          :disabled="personnelChoice === '' || (activeDialog === 'transfer' && reason.trim() === '')"
        >
          Confirm
        </SvButton>
      </form>
    </SvModal>

    <SvModal
      :open="activeDialog === 'cancel'"
      title="Cancel queue entry"
      description="A reason is required."
      @close="activeDialog = null"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCancel"
      >
        <SvTextarea
          id="queue-cancel-reason"
          v-model="reason"
          label="Reason"
        />
        <SvButton
          type="submit"
          variant="destructive"
          :loading="working"
          :disabled="reason.trim() === ''"
        >
          Cancel entry
        </SvButton>
      </form>
    </SvModal>
  </section>
</template>
