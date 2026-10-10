<script setup lang="ts">
import { computed, onMounted } from 'vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { SvIconCalendar, SvIconLocked } from '@/design-system/icons';
import { usePersonnelExperienceStore } from '@/stores/personnelExperienceStore';
import { humanizeStatus, nairobiDate, personnelStatusTone } from '@/utils/personnelStatus';

/*
 * Availability status — read-only (UI-14). Human Resource holds `personnel.availability.manage`;
 * Personnel has no self-status or shift mutation contract, so this page shows the HR-maintained
 * schedule and the server-derived live state only. No toggle, form control or "busy" state exists
 * here, by design rather than omission.
 */
const store = usePersonnelExperienceStore();
const state = computed(() => store.loading.availability ? 'loading' : store.errors.availability ? 'error' : store.availability ? 'success' : 'empty');
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const order = [1, 2, 3, 4, 5, 6, 0];
/** Today's weekday in Africa/Nairobi, used only to highlight the matching row. */
const todayIndex = new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Nairobi' })).getDay();

const week = computed(() => order.map((weekday) => ({
  weekday,
  name: weekdays[weekday],
  slots: (store.availability?.recurring ?? []).filter((slot) => slot.weekday === weekday),
})));
const hhmm = (value: string) => value.slice(0, 5);
onMounted(() => { void store.fetchAvailability(); });
</script>

<template>
  <section
    class="mx-auto max-w-7xl"
    data-testid="personnel-availability"
  >
    <SvTonalPageHeader
      title="Availability status"
      eyebrow="My work"
      tone="teal"
      description="Your schedule and service eligibility are maintained by Human Resource. You can see them here; changes go through HR."
    />
    <SvStateBoundary
      :state="state"
      :error-message="store.errors.availability ?? undefined"
      empty-message="No availability schedule is on file."
      @retry="store.fetchAvailability()"
    >
      <template v-if="store.availability">
        <div class="grid gap-6 lg:grid-cols-[0.75fr_1.25fr]">
          <div class="flex flex-col gap-4">
            <section
              aria-labelledby="current-state-heading"
              class="sv-availability-now rounded-card border border-sv-border p-6 shadow-raised"
            >
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sv-text-muted">
                Right now
              </p>
              <h2
                id="current-state-heading"
                class="mt-2 font-display text-3xl font-extrabold text-sv-text-heading"
              >
                {{ humanizeStatus(store.availability.current_state) }}
              </h2>
              <div class="mt-3">
                <SvStatusBadge
                  :label="`Derived by Servana from your HR schedule`"
                  :tone="personnelStatusTone(store.availability.current_state)"
                  sr-prefix="Availability:"
                />
              </div>
              <p class="mt-4 text-sm text-sv-text-secondary">
                Times are in {{ store.availability.timezone }}.
              </p>
            </section>
            <div class="flex items-start gap-3 rounded-card border border-sv-info-border bg-sv-info-bg p-4 text-sm text-sv-info-fg">
              <SvIconLocked
                aria-hidden="true"
                class="mt-0.5 h-5 w-5 shrink-0"
              />
              <p>Ask Human Resource to change recurring hours, date exceptions or eligible services. Front Office assigns clients from this schedule.</p>
            </div>
            <section
              aria-labelledby="services-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card"
            >
              <h2
                id="services-heading"
                class="font-display text-base font-bold text-sv-text-heading"
              >
                Services you can deliver
              </h2>
              <ul
                v-if="store.availability.eligible_services.length"
                class="mt-3 flex flex-wrap gap-2"
              >
                <li
                  v-for="service in store.availability.eligible_services"
                  :key="service.id"
                  class="rounded-full border border-sv-border bg-sv-surface-subtle px-3 py-1.5 text-sm text-sv-text"
                >
                  {{ service.name }}
                </li>
              </ul>
              <p
                v-else
                class="mt-3 text-sm text-sv-text-muted"
              >
                No active service eligibility is on file.
              </p>
            </section>
          </div>

          <div class="flex flex-col gap-4">
            <section
              aria-labelledby="weekly-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
            >
              <h2
                id="weekly-heading"
                class="flex items-center gap-2 font-display text-lg font-bold text-sv-text-heading"
              >
                <SvIconCalendar
                  aria-hidden="true"
                  class="h-5 w-5"
                />Weekly schedule
              </h2>
              <ul class="mt-4 grid gap-2 md:grid-cols-7">
                <li
                  v-for="day in week"
                  :key="day.weekday"
                  class="flex items-center justify-between gap-3 rounded-control border p-3 md:flex-col md:items-stretch md:justify-start"
                  :class="day.weekday === todayIndex ? 'border-sv-brand bg-sv-selected-bg' : 'border-sv-border bg-sv-surface-subtle'"
                >
                  <p class="text-sm font-semibold text-sv-text-heading">
                    <span class="md:hidden">{{ day.name }}</span><abbr
                      class="hidden no-underline md:inline"
                      :title="day.name"
                    >{{ day.name.slice(0, 3) }}</abbr><span
                      v-if="day.weekday === todayIndex"
                      class="ml-1 text-xs font-medium text-sv-selected-fg md:ml-0 md:block"
                    >Today</span>
                  </p>
                  <div class="text-right text-xs md:mt-2 md:text-left">
                    <p
                      v-if="day.slots.length === 0"
                      class="text-sv-text-muted"
                    >
                      Off
                    </p>
                    <p
                      v-for="(slot, index) in day.slots"
                      :key="index"
                      :class="slot.available ? 'text-sv-text' : 'text-sv-text-muted line-through'"
                    >
                      {{ hhmm(slot.start_time) }}–{{ hhmm(slot.end_time) }}<span class="sr-only">{{ slot.available ? ' available' : ' unavailable' }}</span>
                    </p>
                  </div>
                </li>
              </ul>
            </section>

            <section
              aria-labelledby="exceptions-heading"
              class="rounded-card border border-sv-border bg-sv-surface-raised p-5 shadow-card md:p-6"
            >
              <h2
                id="exceptions-heading"
                class="font-display text-lg font-bold text-sv-text-heading"
              >
                Date exceptions
              </h2>
              <ul
                v-if="store.availability.exceptions.length"
                class="mt-4 flex flex-col gap-2"
              >
                <li
                  v-for="(slot, index) in store.availability.exceptions"
                  :key="`${slot.date}-${index}`"
                  class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-sv-border p-3"
                >
                  <span class="font-semibold text-sv-text-heading">{{ nairobiDate(slot.date) }}</span>
                  <span class="flex items-center gap-2 text-sm text-sv-text-secondary">
                    {{ hhmm(slot.start_time) }}–{{ hhmm(slot.end_time) }}
                    <SvStatusBadge
                      size="sm"
                      :label="slot.available ? 'Available' : 'Unavailable'"
                      :tone="slot.available ? 'success' : 'error'"
                    />
                  </span>
                </li>
              </ul>
              <p
                v-else
                class="mt-3 text-sm text-sv-text-muted"
              >
                No date exceptions are on file.
              </p>
            </section>
          </div>
        </div>
      </template>
    </SvStateBoundary>
  </section>
</template>

<style scoped>
.sv-availability-now {
  background:
    radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--sv-color-brand-secondary) 20%, transparent), transparent 55%),
    var(--sv-color-surface-raised);
}
</style>
