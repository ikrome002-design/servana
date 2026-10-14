<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAppointmentStore } from '@/stores/appointmentStore';
import { appointmentStatusLabel } from '@/utils/appointment';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import { formatTime } from '@/utils/dates';

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

/** Presentation toggle only — both views render the same server list in the same order. */
const view = ref<'timeline' | 'list'>('timeline');

function time(iso: string): string {
  return formatTime(iso);
}

onMounted(() => {
  void appointments.fetchAppointments();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — appointments with a LIST / TIMELINE toggle. The timeline sets each real
    start time as the row's anchor beside the appointment; the list is the compact scan. Status is
    restrained (a quiet chip), contact stays masked, and there is no create, assign, reschedule,
    check-in or cancel control: Front Office handles appointment operations.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Appointments"
      eyebrow="Operational visibility"
      description="Read-only view for your branch. Appointment operations are handled by Front Office."
    />

    <div class="sv-r-panel sv-r-panel--sunken sv-r-panel--compact flex flex-wrap items-end justify-between gap-3">
      <form
        class="flex items-end gap-3"
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
      <div
        class="inline-flex gap-1 rounded-pill bg-sv-surface-raised p-1 shadow-card"
        role="group"
        aria-label="Appointment view"
      >
        <button
          type="button"
          class="sv-focus-ring min-h-sv-touch rounded-pill px-4 text-sm font-semibold"
          :class="view === 'timeline' ? 'bg-sv-nav-active-bg text-sv-nav-active-fg' : 'text-sv-text-muted'"
          :aria-pressed="view === 'timeline'"
          @click="view = 'timeline'"
        >
          Timeline
        </button>
        <button
          type="button"
          class="sv-focus-ring min-h-sv-touch rounded-pill px-4 text-sm font-semibold"
          :class="view === 'list' ? 'bg-sv-nav-active-bg text-sv-nav-active-fg' : 'text-sv-text-muted'"
          :aria-pressed="view === 'list'"
          @click="view = 'list'"
        >
          List
        </button>
      </div>
    </div>

    <SvStateBoundary
      class="mt-5"
      :state="boundaryState"
      empty-message="No appointments for this view."
      error-message="We couldn’t load appointments."
      @retry="() => appointments.fetchAppointments()"
    >
      <ol
        v-if="view === 'timeline'"
        class="sv-r-steps"
        aria-label="Appointments"
      >
        <li
          v-for="appointment in appointments.appointments"
          :key="appointment.id"
          class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-4 pb-3"
        >
          <span class="pt-3 text-right font-display text-sm font-extrabold text-sv-text-heading">{{ time(appointment.starts_at) }}</span>
          <article class="sv-r-record sv-r-record--spine sv-r-tone-green pl-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-bold text-heading">
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
                class="sv-r-chip"
                data-testid="status-badge"
              >{{ appointmentStatusLabel(appointment.status) }}</span>
            </div>
          </article>
        </li>
      </ol>
      <ul
        v-else
        class="sv-r-ledger"
        aria-label="Appointments"
      >
        <li
          v-for="appointment in appointments.appointments"
          :key="appointment.id"
          class="sv-r-ledger__row"
          style="--sv-r-ledger-cols: 8rem minmax(0, 1fr) auto"
        >
          <span class="font-display text-sm font-extrabold text-sv-text-heading">{{ time(appointment.starts_at) }}–{{ time(appointment.ends_at) }}</span>
          <span class="min-w-0">
            <span class="block font-semibold text-heading">{{ appointment.client?.full_name ?? 'Client' }}</span>
            <span class="block text-sm text-text-muted">{{ appointment.service?.name }}<span v-if="appointment.assigned_personnel"> · {{ appointment.assigned_personnel.display_name }}</span></span>
          </span>
          <span
            class="sv-r-chip justify-self-start"
            data-testid="status-badge"
          >{{ appointmentStatusLabel(appointment.status) }}</span>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>