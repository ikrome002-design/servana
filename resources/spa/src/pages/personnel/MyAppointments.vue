<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePersonnelAppointmentStore } from '@/stores/appointmentStore';
import { appointmentStatusLabel } from '@/utils/appointment';

// Personnel own-scope appointments (Plan §36, §19.3; Phase 16A). Mobile-first,
// READ-ONLY: only appointments assigned to the authenticated personnel member,
// with the minimum masked client info needed to perform them. No other personnel's
// schedule, no branch-wide search, no mutation, no contact export.
const mine = usePersonnelAppointmentStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (mine.loading) return 'loading';
  if (mine.error) return 'error';
  if (mine.appointments.length === 0) return 'empty';
  return 'success';
});

function when(iso: string): string {
  return new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

onMounted(() => {
  void mine.fetchMine();
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-brand-deep">
      My appointments
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      empty-message="You have no appointments assigned to you."
      error-message="We couldn’t load your appointments."
      @retry="() => mine.fetchMine()"
    >
      <ul
        class="flex flex-col gap-3"
        aria-label="My appointments"
      >
        <li
          v-for="appointment in mine.appointments"
          :key="appointment.id"
        >
          <SvCard
            as="article"
            padding="md"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 class="font-display text-base font-semibold text-brand-deep">
                  {{ appointment.service?.name }}
                </h2>
                <p class="mt-0.5 text-sm text-text-muted">
                  {{ when(appointment.starts_at) }}
                  · {{ appointment.client?.full_name }}
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
