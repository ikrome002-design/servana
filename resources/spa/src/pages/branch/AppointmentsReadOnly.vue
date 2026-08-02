<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAppointmentStore } from '@/stores/appointmentStore';
import { appointmentStatusLabel } from '@/utils/appointment';

// Branch Manager READ-ONLY appointment visibility (Plan §36; Phase 16A). Authorised
// by branch.dashboard.view — there are NO create/assign/transfer/reschedule/cancel/
// check-in/no-show controls (appointment operations are Front Office only and
// backend-enforced). Client contact is masked; nothing here mutates.
const appointments = useAppointmentStore();

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
    <h1 class="font-display text-2xl font-bold text-heading">
      Appointments
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      Read-only view for your branch. Appointment operations are handled by Front Office.
    </p>

    <form
      class="mt-4 flex items-end gap-3"
      novalidate
      @submit.prevent="appointments.fetchAppointments()"
    >
      <div class="w-44">
        <SvTextInput
          id="bm-appointment-date"
          v-model="appointments.filterDate"
          label="Date"
          type="date"
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
      empty-message="No appointments for this view."
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
                <h2 class="font-display text-base font-semibold text-heading">
                  {{ appointment.client?.full_name ?? 'Client' }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ time(appointment.starts_at) }}–{{ time(appointment.ends_at) }}
                  · {{ appointment.service?.name }}
                  <span v-if="appointment.assigned_personnel">
                    · {{ appointment.assigned_personnel.display_name }}</span>
                </p>
              </div>
              <span
                class="rounded-full bg-surface-alt px-2.5 py-1 text-xs font-semibold text-text"
                data-testid="status-badge"
              >{{ appointmentStatusLabel(appointment.status) }}</span>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
