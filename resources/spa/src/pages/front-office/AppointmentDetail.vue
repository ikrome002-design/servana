<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { apiClient } from '@/services/apiClient';
import { useAppointmentStore } from '@/stores/appointmentStore';
import { useNotificationStore } from '@/stores/notificationStore';
import type { Appointment, ServiceEligibility } from '@/types/models';
import { appointmentStatusLabel, toBusinessIso } from '@/utils/appointment';

// Appointment detail with capability-gated actions (Plan §36; Phase 16A). Action
// availability is driven by the API `can` map (UX only); the API re-checks every
// mutation. Terminal changes use a confirmation dialog; client contact is masked.
const route = useRoute();
const appointments = useAppointmentStore();
const notifications = useNotificationStore();

const appointment = ref<Appointment | null>(null);
const loading = ref(true);
const error = ref(false);
const activeDialog = ref<'assign' | 'transfer' | 'reschedule' | 'cancel' | null>(null);
const eligible = ref<ServiceEligibility[]>([]);
const personnelChoice = ref('');
const reason = ref('');
const newStart = ref('');
const working = ref(false);

const id = computed(() => String(route.params.id));
const can = computed(() => appointment.value?.can);
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

function fmt(iso: string | null): string {
  return iso === null ? '—' : new Date(iso).toLocaleString();
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = false;
  try {
    appointment.value = await appointments.fetchAppointment(id.value);
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

async function openPersonnelDialog(dialog: 'assign' | 'transfer'): Promise<void> {
  personnelChoice.value = '';
  reason.value = '';
  eligible.value = [];
  const serviceId = appointment.value?.service?.id;
  if (serviceId) {
    try {
      const { data } = await apiClient.get<{ data: ServiceEligibility[] }>(`/services/${serviceId}/eligibility`);
      eligible.value = data.data;
    } catch {
      eligible.value = [];
    }
  }
  activeDialog.value = dialog;
}

function openReschedule(): void {
  newStart.value = '';
  activeDialog.value = 'reschedule';
}

function openCancel(): void {
  reason.value = '';
  activeDialog.value = 'cancel';
}

async function run(action: () => Promise<Appointment>, successMessage: string): Promise<void> {
  working.value = true;
  try {
    appointment.value = await action();
    activeDialog.value = null;
    notifications.addToast({ type: 'success', message: successMessage });
  } catch (err: unknown) {
    const message = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Something went wrong.';
    notifications.addToast({ type: 'error', message });
  } finally {
    working.value = false;
  }
}

const submitAssign = (): Promise<void> => run(() => appointments.assign(id.value, personnelChoice.value), 'Personnel assigned.');
const submitTransfer = (): Promise<void> => run(() => appointments.transfer(id.value, personnelChoice.value, reason.value || undefined), 'Appointment transferred.');
const submitReschedule = (): Promise<void> => run(() => appointments.reschedule(id.value, toBusinessIso(newStart.value)), 'Appointment rescheduled.');
const submitCancel = (): Promise<void> => run(() => appointments.cancel(id.value, reason.value || undefined), 'Appointment cancelled.');
const submitCheckIn = (): Promise<void> => run(() => appointments.checkIn(id.value), 'Client checked in.');
const submitNoShow = (): Promise<void> => run(() => appointments.markNoShow(id.value), 'Marked as no-show.');

onMounted(load);
</script>

<template>
  <section class="mx-auto w-full max-w-2xl p-4 md:p-6">
    <RouterLink
      :to="{ name: 'front-office.appointments' }"
      class="text-sm font-semibold text-heading underline"
    >
      ← Back to appointments
    </RouterLink>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      error-message="We couldn’t load this appointment."
      @retry="load"
    >
      <SvCard
        v-if="appointment"
        as="div"
        padding="lg"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-bold text-heading">
            {{ appointment.client?.full_name ?? 'Appointment' }}
          </h1>
          <span
            class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
            data-testid="status-badge"
          >{{ appointmentStatusLabel(appointment.status) }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-text-muted">
              Service
            </dt>
            <dd class="font-medium text-text">
              {{ appointment.service?.name }} ({{ appointment.service?.duration_minutes }} min)
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              When
            </dt>
            <dd class="font-medium text-text">
              {{ fmt(appointment.starts_at) }} – {{ fmt(appointment.ends_at) }}
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Client contact
            </dt>
            <dd class="font-medium text-text">
              {{ appointment.client?.phone_masked }}
            </dd>
          </div>
          <div>
            <dt class="text-text-muted">
              Assigned personnel
            </dt>
            <dd class="font-medium text-text">
              {{ appointment.assigned_personnel?.display_name ?? 'Unassigned' }}
            </dd>
          </div>
          <div v-if="appointment.cancellation_reason">
            <dt class="text-text-muted">
              Reason
            </dt>
            <dd class="font-medium text-text">
              {{ appointment.cancellation_reason }}
            </dd>
          </div>
        </dl>

        <div class="mt-6 flex flex-wrap gap-2">
          <SvButton
            v-if="can?.assign"
            variant="primary"
            data-testid="action-assign"
            @click="openPersonnelDialog('assign')"
          >
            Assign personnel
          </SvButton>
          <SvButton
            v-if="can?.check_in"
            variant="primary"
            :loading="working"
            data-testid="action-check-in"
            @click="submitCheckIn"
          >
            Check in
          </SvButton>
          <SvButton
            v-if="can?.transfer"
            variant="secondary"
            data-testid="action-transfer"
            @click="openPersonnelDialog('transfer')"
          >
            Transfer
          </SvButton>
          <SvButton
            v-if="can?.reschedule"
            variant="secondary"
            data-testid="action-reschedule"
            @click="openReschedule"
          >
            Reschedule
          </SvButton>
          <SvButton
            v-if="can?.mark_no_show"
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

    <!-- Assign / Transfer dialog -->
    <SvModal
      :open="activeDialog === 'assign' || activeDialog === 'transfer'"
      :title="activeDialog === 'transfer' ? 'Transfer appointment' : 'Assign personnel'"
      description="Only eligible, available personnel can take this appointment."
      @close="activeDialog = null"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="activeDialog === 'transfer' ? submitTransfer() : submitAssign()"
      >
        <SvSelect
          id="personnel-choice"
          v-model="personnelChoice"
          label="Personnel"
          placeholder="Select personnel"
          :options="personnelOptions"
          required
        />
        <SvTextarea
          v-if="activeDialog === 'transfer'"
          id="transfer-reason"
          v-model="reason"
          label="Reason (optional)"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="working"
          :disabled="personnelChoice === ''"
        >
          Confirm
        </SvButton>
      </form>
    </SvModal>

    <!-- Reschedule dialog -->
    <SvModal
      :open="activeDialog === 'reschedule'"
      title="Reschedule appointment"
      description="The end time is recalculated from the service duration."
      @close="activeDialog = null"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitReschedule"
      >
        <SvInput
          id="new-start"
          v-model="newStart"
          label="New start time"
          type="datetime-local"
          hint="Branch business time (Africa/Nairobi)."
          required
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="working"
          :disabled="newStart === ''"
        >
          Reschedule
        </SvButton>
      </form>
    </SvModal>

    <!-- Cancel dialog -->
    <SvModal
      :open="activeDialog === 'cancel'"
      title="Cancel appointment"
      description="A reason is required once a client has checked in."
      @close="activeDialog = null"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submitCancel"
      >
        <SvTextarea
          id="cancel-reason"
          v-model="reason"
          label="Reason"
        />
        <SvButton
          type="submit"
          variant="destructive"
          :loading="working"
        >
          Cancel appointment
        </SvButton>
      </form>
    </SvModal>
  </section>
</template>
