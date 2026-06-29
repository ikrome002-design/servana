<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAppointmentStore } from '@/stores/appointmentStore';
import { appointmentStatusLabel } from '@/utils/appointment';

// Front Office appointment list for a chosen business date (Plan §36; Phase 16A).
// Branch-scoped; client contact is ALWAYS masked by the server. The API is the
// boundary — the create button is a UX-only permission gate.
const appointments = useAppointmentStore();

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'checked_in', label: 'Checked in' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'cancelled_with_reason', label: 'Cancelled (with reason)' },
  { value: 'no_show', label: 'No-show' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (appointments.loading) return 'loading';
  if (appointments.error) return 'error';
  if (appointments.appointments.length === 0) return 'empty';
  return 'success';
});

function time(iso: string): string {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

onMounted(() => {
  void appointments.fetchAppointments();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-brand-deep">
        Appointments
      </h1>
      <PermissionGate permission="appointment.create">
        <RouterLink :to="{ name: 'front-office.appointments.create' }">
          <SvButton
            variant="primary"
            data-testid="add-appointment"
          >
            Book appointment
          </SvButton>
        </RouterLink>
      </PermissionGate>
    </div>

    <form
      class="mt-4 flex flex-wrap items-end gap-3"
      novalidate
      @submit.prevent="appointments.fetchAppointments()"
    >
      <div class="w-44">
        <SvInput
          id="appointment-date"
          v-model="appointments.filterDate"
          label="Date"
          type="date"
        />
      </div>
      <div class="w-56">
        <SvSelect
          id="appointment-status"
          v-model="appointments.filterStatus"
          label="Status"
          :options="statusOptions"
        />
      </div>
      <SvButton
        type="submit"
        variant="secondary"
        data-testid="filter-appointments"
      >
        Apply
      </SvButton>
    </form>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="No appointments for this view. Book one to get started."
      error-message="We couldn’t load appointments."
      @retry="() => appointments.fetchAppointments()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="Appointments"
      >
        <li
          v-for="appointment in appointments.appointments"
          :key="appointment.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-brand-deep">
                  {{ appointment.client?.full_name ?? 'Client' }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ time(appointment.starts_at) }}–{{ time(appointment.ends_at) }}
                  · {{ appointment.service?.name }}
                  <span v-if="appointment.assigned_personnel">
                    · {{ appointment.assigned_personnel.display_name }}</span>
                </p>
              </div>
              <div class="flex items-center gap-3">
                <span
                  class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                  data-testid="status-badge"
                >{{ appointmentStatusLabel(appointment.status) }}</span>
                <RouterLink
                  :to="{ name: 'front-office.appointments.detail', params: { id: appointment.id } }"
                  class="text-sm font-semibold text-brand-deep underline"
                >
                  View
                </RouterLink>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
