<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconCalendar, SvIconLocked } from '@/design-system/icons';
import { usePersonnelAppointmentStore } from '@/stores/appointmentStore';
import type { PersonnelAppointment } from '@/types/models';
import { appointmentStatusLabel } from '@/utils/appointment';
import { nairobiDayKey, nairobiDayLabel, nairobiTime, personnelStatusTone } from '@/utils/personnelStatus';

// Personnel own-scope appointments (Plan §36, §19.3; Phase 16A; UI-14). Mobile-first agenda,
// READ-ONLY: only appointments assigned to the authenticated personnel member, with the
// server-masked client only. No create/reschedule/cancel/assign control — Front Office owns those.
const mine = usePersonnelAppointmentStore();

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (mine.loading) return 'loading';
  if (mine.error) return 'error';
  if (mine.appointments.length === 0) return 'empty';
  return 'success';
});

const todayKey = nairobiDayKey(new Date().toISOString());

/** Agenda groups in Africa/Nairobi business days; the server's ordering is preserved inside each. */
const groups = computed(() => {
  const today: PersonnelAppointment[] = [];
  const upcoming = new Map<string, PersonnelAppointment[]>();
  const past: PersonnelAppointment[] = [];
  for (const appointment of mine.appointments) {
    const key = nairobiDayKey(appointment.starts_at);
    if (key === todayKey) today.push(appointment);
    else if (key > todayKey) upcoming.set(key, [...(upcoming.get(key) ?? []), appointment]);
    else past.push(appointment);
  }
  return {
    today,
    upcoming: [...upcoming.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([key, items]) => ({ key, label: nairobiDayLabel(items[0].starts_at), items })),
    past,
  };
});
const upcomingCount = computed(() => groups.value.upcoming.reduce((sum, group) => sum + group.items.length, 0));
const minutes = (appointment: PersonnelAppointment) => Math.round((new Date(appointment.ends_at).getTime() - new Date(appointment.starts_at).getTime()) / 60000);

onMounted(() => {
  void mine.fetchMine();
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="personnel-my-appointments"
  >
    <SvTonalPageHeader
      title="My appointments"
      eyebrow="My work"
      tone="teal"
      description="Your assigned schedule in Africa/Nairobi time. Booking, rescheduling and reassignment stay with Front Office."
    >
      <template #actions>
        <RouterLink
          :to="{ name: 'personnel.work-queue' }"
          class="sv-focus-ring inline-flex min-h-sv-touch items-center justify-center rounded-control bg-sv-brand px-4 text-sm font-bold text-sv-text-on-brand hover:bg-sv-brand-hover"
        >
          Open my queue
        </RouterLink>
      </template>
      <div class="grid grid-cols-3 gap-3">
        <SvStatTile
          label="Today"
          tone="brand"
        >
          <template #icon>
            <SvIconCalendar class="h-5 w-5" />
          </template>
          {{ groups.today.length }}
        </SvStatTile>
        <SvStatTile
          label="Upcoming"
          tone="teal"
        >
          {{ upcomingCount }}
        </SvStatTile>
        <SvStatTile
          label="Earlier"
          tone="neutral"
        >
          {{ groups.past.length }}
        </SvStatTile>
      </div>
    </SvTonalPageHeader>

    <SvStateBoundary
      :state="boundaryState"
      empty-message="You have no appointments assigned to you."
      error-message="We couldn’t load your appointments."
      @retry="() => mine.fetchMine()"
    >
      <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
        <div
          class="flex flex-col gap-6"
          aria-label="My appointments"
          role="list"
        >
          <section
            role="listitem"
            aria-labelledby="agenda-today"
          >
            <h2
              id="agenda-today"
              class="mb-3 flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading"
            >
              <span
                aria-hidden="true"
                class="h-2.5 w-2.5 rounded-full bg-sv-brand"
              />Today
            </h2>
            <p
              v-if="groups.today.length === 0"
              class="rounded-card border border-dashed border-sv-border-strong p-4 text-sm text-sv-text-muted"
            >
              Nothing booked with you today.
            </p>
            <ul
              v-else
              class="flex flex-col gap-3"
            >
              <li
                v-for="appointment in groups.today"
                :key="appointment.id"
              >
                <SvLeadRecord
                  :lead-value="nairobiTime(appointment.starts_at)"
                  :lead-label="`${minutes(appointment)} min`"
                  tone="brand"
                >
                  <p class="font-display text-base font-bold text-sv-text-heading">
                    {{ appointment.service?.name }}
                  </p>
                  <div class="mt-2">
                    <SvMaskedIdentity
                      size="sm"
                      :name="appointment.client?.full_name"
                      :phone-masked="appointment.client?.phone_masked"
                    />
                  </div>
                  <template #status>
                    <span data-testid="status-badge">
                      <SvStatusBadge
                        size="sm"
                        :label="appointmentStatusLabel(appointment.status)"
                        :tone="personnelStatusTone(appointment.status)"
                      />
                    </span>
                  </template>
                </SvLeadRecord>
              </li>
            </ul>
          </section>

          <section
            v-for="group in groups.upcoming"
            :key="group.key"
            role="listitem"
            :aria-label="group.label"
          >
            <h2 class="mb-3 flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading">
              <span
                aria-hidden="true"
                class="h-2.5 w-2.5 rounded-full bg-sv-brand-secondary"
              />{{ group.label }}
            </h2>
            <ul class="flex flex-col gap-3">
              <li
                v-for="appointment in group.items"
                :key="appointment.id"
              >
                <SvLeadRecord
                  :lead-value="nairobiTime(appointment.starts_at)"
                  :lead-label="`${minutes(appointment)} min`"
                  tone="teal"
                >
                  <p class="font-display text-base font-bold text-sv-text-heading">
                    {{ appointment.service?.name }}
                  </p>
                  <div class="mt-2">
                    <SvMaskedIdentity
                      size="sm"
                      :name="appointment.client?.full_name"
                      :phone-masked="appointment.client?.phone_masked"
                    />
                  </div>
                  <template #status>
                    <span data-testid="status-badge">
                      <SvStatusBadge
                        size="sm"
                        :label="appointmentStatusLabel(appointment.status)"
                        :tone="personnelStatusTone(appointment.status)"
                      />
                    </span>
                  </template>
                </SvLeadRecord>
              </li>
            </ul>
          </section>

          <section
            v-if="groups.past.length"
            role="listitem"
            aria-labelledby="agenda-past"
          >
            <h2
              id="agenda-past"
              class="mb-3 font-display text-lg font-bold text-sv-text-heading"
            >
              Earlier
            </h2>
            <ul class="flex flex-col gap-3">
              <li
                v-for="appointment in groups.past"
                :key="appointment.id"
              >
                <SvLeadRecord
                  :lead-value="nairobiTime(appointment.starts_at)"
                  :lead-label="nairobiDayLabel(appointment.starts_at)"
                  tone="neutral"
                >
                  <p class="font-display text-base font-bold text-sv-text-heading">
                    {{ appointment.service?.name }}
                  </p>
                  <div class="mt-2">
                    <SvMaskedIdentity
                      size="sm"
                      :name="appointment.client?.full_name"
                      :phone-masked="appointment.client?.phone_masked"
                    />
                  </div>
                  <template #status>
                    <span data-testid="status-badge">
                      <SvStatusBadge
                        size="sm"
                        :label="appointmentStatusLabel(appointment.status)"
                        :tone="personnelStatusTone(appointment.status)"
                      />
                    </span>
                  </template>
                </SvLeadRecord>
              </li>
            </ul>
          </section>
        </div>

        <aside class="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
          <div class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card">
            <div class="flex items-center gap-2">
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-control bg-sv-info-bg text-sv-info-fg">
                <SvIconLocked
                  aria-hidden="true"
                  class="h-5 w-5"
                />
              </span>
              <h2 class="font-display text-base font-bold text-sv-text-heading">
                Private schedule
              </h2>
            </div>
            <p class="mt-3 text-sm leading-6 text-sv-text-secondary">
              Only your own assignments appear here. Client contact is masked, and no branch-wide calendar is exposed.
            </p>
          </div>
          <div class="rounded-card border border-sv-border bg-sv-surface-warm p-5">
            <h2 class="font-display text-base font-bold text-sv-text-heading">
              Need a change?
            </h2>
            <p class="mt-2 text-sm leading-6 text-sv-text-secondary">
              Ask Front Office to reschedule or reassign. Your working hours are set by Human Resource.
            </p>
            <RouterLink
              :to="{ name: 'personnel.availability' }"
              class="sv-focus-ring mt-3 inline-flex min-h-sv-touch items-center text-sm font-semibold text-sv-link underline"
            >
              View my availability
            </RouterLink>
          </div>
        </aside>
      </div>
    </SvStateBoundary>
  </section>
</template>
