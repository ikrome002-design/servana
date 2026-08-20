<script setup lang="ts">
import { computed, onMounted } from 'vue';
import PermissionGate from '@/components/auth/PermissionGate.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge, { type SvStatusTone } from '@/components/ui/SvStatusBadge.vue';
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

function appointmentTone(status: string): SvStatusTone {
  if (status === 'checked_in' || status === 'queued') return 'success';
  if (status === 'cancelled' || status === 'cancelled_with_reason') return 'error';
  if (status === 'no_show') return 'warning';
  return status === 'confirmed' ? 'info' : 'neutral';
}

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
  <section class="mx-auto max-w-6xl">
    <SvOperationalHero
      eyebrow="Scheduled arrivals"
      title="Appointments"
      description="See who is due, keep arrival states distinct and move only eligible work into the assigned branch queue."
    >
      <template #actions>
        <PermissionGate permission="appointment.create">
          <RouterLink
            class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control bg-primary px-4 py-2 text-sm font-bold text-brand-deep"
            :to="{ name: 'front-office.appointment-create' }"
            data-testid="add-appointment"
          >
            Book appointment
          </RouterLink>
        </PermissionGate>
      </template>
    </SvOperationalHero>

    <SvCard
      as="form"
      class="mt-5 flex flex-wrap items-end gap-3"
      padding="md"
      novalidate
      @submit.prevent="appointments.fetchAppointments()"
    >
      <div class="w-44">
        <SvTextInput
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
    </SvCard>

    <div class="mt-5">
      <SvStateBoundary
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
                <div class="flex items-center gap-3">
                  <SvStatusBadge
                    :label="appointmentStatusLabel(appointment.status)"
                    :tone="appointmentTone(appointment.status)"
                    sr-prefix="Appointment status:"
                    data-testid="status-badge"
                  />
                  <RouterLink
                    :to="{ name: 'front-office.appointment-detail', params: { appointmentUlid: appointment.id } }"
                    class="sv-focus-ring inline-flex min-h-sv-touch items-center rounded-control px-3 text-sm font-semibold text-heading underline"
                  >
                    View
                  </RouterLink>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>
    </div>
  </section>
</template>
