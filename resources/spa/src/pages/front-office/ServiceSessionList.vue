<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useServiceSessionStore } from '@/stores/serviceSessionStore';
import type { ServiceSession } from '@/types/models';
import {
  COMMISSION_PREVIEW_LABEL,
  commissionPreviewSummary,
  serviceSessionStatusLabel,
} from '@/utils/serviceSession';

// Front Office service sessions (Plan §25.2; Phase 16C). Operational list: status,
// masked client, service, personnel, completion preview ("not earned or payable").
// Start + complete are driven by the queue board; this view owns cancellation (of a
// pending session) and service-notes editing. NO invoice, payment, receipt, fee-rule,
// earned/payable commission, or payout control appears here (Phases 17/18/20).
const store = useServiceSessionStore();

const cancelTarget = ref<ServiceSession | null>(null);
const cancelReason = ref('');
const notesTarget = ref<ServiceSession | null>(null);
const notesValue = ref('');
const busy = ref(false);
const actionError = ref<string | null>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.sessions.length === 0) return 'empty';
  return 'success';
});

const statusOptions = [
  { value: '', label: 'Active (pending / in progress)' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'pending', label: 'Pending' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

function openCancel(session: ServiceSession): void {
  cancelTarget.value = session;
  cancelReason.value = '';
  actionError.value = null;
}

function openNotes(session: ServiceSession): void {
  notesTarget.value = session;
  notesValue.value = session.notes ?? '';
  actionError.value = null;
}

async function confirmCancel(): Promise<void> {
  if (cancelTarget.value === null || cancelReason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.cancel(cancelTarget.value.id, cancelReason.value.trim());
    cancelTarget.value = null;
    await store.fetchSessions();
  } catch {
    actionError.value = 'Unable to cancel this session.';
  } finally {
    busy.value = false;
  }
}

async function confirmNotes(): Promise<void> {
  if (notesTarget.value === null) return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.updateNotes(notesTarget.value.id, notesValue.value);
    notesTarget.value = null;
    await store.fetchSessions();
  } catch {
    actionError.value = 'Unable to save notes.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchSessions();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Service sessions
      </h1>
      <SvSelect
        id="session-status-filter"
        v-model="store.filterStatus"
        label="Filter"
        :options="statusOptions"
        class="w-full sm:w-64"
        @update:model-value="() => store.fetchSessions()"
      />
    </div>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No service sessions match this filter."
      error-message="We couldn’t load service sessions."
      @retry="() => store.fetchSessions()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Service sessions"
      >
        <li
          v-for="session in store.sessions"
          :key="session.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-heading">
                  {{ session.client?.full_name ?? 'Client' }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ session.service?.name }}
                  <span v-if="session.personnel"> · {{ session.personnel.display_name }}</span>
                </p>
                <p
                  v-if="session.preferred_personnel_honored === false"
                  class="mt-0.5 text-xs text-text-muted"
                >
                  Preferred-personnel request overridden
                </p>
              </div>
              <span
                class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                data-testid="session-status-badge"
              >{{ serviceSessionStatusLabel(session.status) }}</span>
            </div>

            <!-- Completion preview — explicitly NOT earned or payable (Phase 16C). -->
            <div
              v-if="session.status === 'completed' && session.commission_preview"
              class="mt-3 rounded-control border border-border bg-surface-alt px-3 py-2"
              data-testid="commission-preview"
            >
              <p class="text-xs font-semibold text-text">
                {{ COMMISSION_PREVIEW_LABEL }}
              </p>
              <p class="mt-0.5 text-xs text-text-muted">
                {{ commissionPreviewSummary(session.commission_preview) }}
              </p>
            </div>

            <div
              v-if="session.notes"
              class="mt-3 text-sm text-text-muted"
            >
              {{ session.notes }}
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <SvButton
                v-if="session.can?.update_notes"
                variant="secondary"
                @click="openNotes(session)"
              >
                Notes
              </SvButton>
              <SvButton
                v-if="session.can?.cancel"
                variant="destructive"
                @click="openCancel(session)"
              >
                Cancel
              </SvButton>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <!-- Cancel session -->
    <SvDialog
      :open="cancelTarget !== null"
      title="Cancel service session"
      description="This cannot be undone. A reason is required."
      @close="cancelTarget = null"
    >
      <SvTextArea
        id="session-cancel-reason"
        v-model="cancelReason"
        label="Reason"
        :rows="3"
        required
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-danger"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="cancelTarget = null"
        >
          Keep session
        </SvButton>
        <SvButton
          variant="destructive"
          :loading="busy"
          :disabled="cancelReason.trim() === ''"
          @click="confirmCancel"
        >
          Cancel session
        </SvButton>
      </div>
    </SvDialog>

    <!-- Edit notes -->
    <SvDialog
      :open="notesTarget !== null"
      title="Service notes"
      description="Operational notes only — never client contact details."
      @close="notesTarget = null"
    >
      <SvTextArea
        id="session-notes"
        v-model="notesValue"
        label="Notes"
        :rows="4"
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-danger"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="notesTarget = null"
        >
          Cancel
        </SvButton>
        <SvButton
          :loading="busy"
          @click="confirmNotes"
        >
          Save notes
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
