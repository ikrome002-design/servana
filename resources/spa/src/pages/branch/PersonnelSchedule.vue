<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import {
  type AvailabilityException,
  type AvailabilityRecurring,
  useAvailabilityStore,
} from '@/stores/availabilityStore';
import { usePermissionStore } from '@/stores/permissionStore';
import type { BranchPersonnelOption } from '@/types/models';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

// Branch Manager READ-ONLY personnel schedule (Plan §80 Phase 15B). The Branch
// Manager has branch-scoped visibility (`branch.dashboard.view`) into personnel
// working days, breaks, temporary unavailability, current state, and eligible
// services — but NEVER edits them. Mutation is HR-only and is rejected by the
// backend regardless of the UI (these checks are UX only).
//
// Phase 23 §14.1: the picker is populated from the NARROW
// `GET /api/v1/branch/personnel-options` ({id, display_name}) — never from the HR
// roster `GET /api/v1/staff`, which is now correctly gated by the HR-only
// `staff.view` and carries personnel phone numbers this screen must never receive.
const availability = useAvailabilityStore();
const permissions = usePermissionStore();
const auth = useAuthStore();

const WEEKDAY_LABELS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const STATE_LABELS: Record<string, string> = {
  suspended: 'Suspended',
  available: 'Available',
  on_break: 'On break',
  unavailable: 'Unavailable',
  offline: 'Offline',
};

const canView = computed(() => permissions.can('branch.dashboard.view'));
const hasBranch = computed(() => auth.branchIds.length > 0);

const staff = ref<BranchPersonnelOption[]>([]);
const selectedStaff = ref('');

const staffOptions = computed(() => staff.value.map((s) => ({ value: s.id, label: s.display_name })));

// "Today" in Africa/Nairobi business time (weekday 0=Sunday … 6=Saturday + date).
const nairobiDate = computed(() => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' }));
const todayWeekday = computed(() => new Date(`${nairobiDate.value}T00:00:00Z`).getUTCDay());

const todayWorking = computed<AvailabilityRecurring[]>(() =>
  (availability.schedule?.recurring ?? []).filter((r) => r.weekday === todayWeekday.value && r.available),
);
const todayBreaks = computed<AvailabilityRecurring[]>(() =>
  (availability.schedule?.recurring ?? []).filter((r) => r.weekday === todayWeekday.value && !r.available),
);
const todayUnavailable = computed<AvailabilityException[]>(() =>
  (availability.schedule?.exceptions ?? []).filter((e) => e.date === nairobiDate.value && !e.available),
);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (selectedStaff.value === '') return 'empty';
  if (availability.loading) return 'loading';
  if (availability.error) return 'error';
  return 'success';
});

watch(selectedStaff, async (id) => {
  if (id !== '') await availability.fetch(id);
});

async function loadPersonnelOptions(): Promise<void> {
  if (!canView.value || !hasBranch.value) return;
  const { data } = await apiClient.get<{ data: BranchPersonnelOption[] }>('/branch/personnel-options');
  staff.value = data.data;
}

onMounted(loadPersonnelOptions);

// Branch context is server-derived, so a branch change must never leave the previous
// branch's personnel selectable: clear the options AND the selection before reloading.
watch(
  () => auth.branchIds.join(','),
  async () => {
    staff.value = [];
    selectedStaff.value = '';
    await loadPersonnelOptions();
  },
);
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — branch staffing readiness, read-only. The personnel picker and the
    ownership boundary (HR manages schedules) sit in a narrow column; the selected person reads as a
    roster card — initials, name, live state, eligible services as chips — then TODAY's working
    intervals, breaks and unavailability, and the week as seven day tiles. No control here edits a
    schedule; mutation stays HR-only and server-enforced.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Personnel schedule"
      eyebrow="Branch operations"
    >
      <template #description>
        View personnel availability for your branch. Times are in
        <span class="font-medium">Africa/Nairobi</span>. Schedules are managed by HR.
      </template>
    </SvPageHeader>

    <div
      v-if="!canView"
      class="sv-r-panel sv-r-panel--dashed"
      data-testid="no-permission"
    >
      <p class="text-sm text-text-muted">
        You do not have permission to view personnel schedules.
      </p>
    </div>

    <div
      v-else-if="!hasBranch"
      class="sv-r-panel sv-r-panel--dashed"
      data-testid="no-branch"
    >
      <p class="text-sm text-text-muted">
        You are not assigned to a branch yet.
      </p>
    </div>

    <div
      v-else
      class="sv-r-grid lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] lg:items-start"
    >
      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Choose personnel"
      >
        <div class="sv-r-panel sv-r-panel--tonal sv-r-tone-green">
          <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green mb-3">
            Branch roster
          </p>
          <SvSelect
            id="bm-staff"
            v-model="selectedStaff"
            label="Personnel"
            placeholder="Select personnel"
            :options="staffOptions"
          />
        </div>
        <div class="sv-r-note sv-r-tone-teal">
          <p class="sv-r-note__title">
            Managed by Human Resource
          </p>
          <p class="sv-r-note__body">
            Working days, breaks and unavailability are set by Human Resource. This view is read-only.
          </p>
        </div>
      </aside>

      <SvStateBoundary
        :state="boundaryState"
        empty-message="Select a personnel member to view their schedule."
      >
        <div
          v-if="availability.schedule"
          class="sv-r-stack"
        >
          <div class="sv-r-record flex flex-wrap items-center gap-4">
            <span
              aria-hidden="true"
              class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-sv-surface-warm font-display text-lg font-extrabold text-sv-text-heading"
            >{{ availability.schedule.staff.display_name.slice(0, 2).toUpperCase() }}</span>
            <div class="min-w-0 flex-1">
              <p class="sv-r-title">
                {{ availability.schedule.staff.display_name }}
              </p>
              <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-text-muted">
                Eligible services:
                <template v-if="availability.schedule.eligible_services.length > 0">
                  <span
                    v-for="service in availability.schedule.eligible_services"
                    :key="service.name"
                    class="sv-r-chip"
                  >{{ service.name }}</span>
                </template>
                <template v-else>
                  none
                </template>
              </p>
            </div>
            <span
              class="sv-r-chip sv-r-chip--tone sv-r-chip--dot !text-sm"
              :class="availability.schedule.current_state === 'available' ? 'sv-r-tone-green' : availability.schedule.current_state === 'on_break' ? 'sv-r-tone-sun' : 'sv-r-tone-neutral'"
              data-testid="bm-current-state"
              aria-live="polite"
            >
              {{ STATE_LABELS[availability.schedule.current_state] }}
            </span>
          </div>

          <section
            class="sv-r-panel"
            data-testid="bm-today"
          >
            <h2 class="sv-r-title">
              Today ({{ WEEKDAY_LABELS[todayWeekday] }})
            </h2>
            <dl class="mt-4 grid gap-3 md:grid-cols-3">
              <div class="rounded-card bg-sv-success-bg p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-sv-success-fg">
                  Working intervals
                </dt>
                <dd class="mt-2 text-sm font-semibold text-text">
                  <template v-if="todayWorking.length > 0">
                    <span
                      v-for="(r, i) in todayWorking"
                      :key="`tw-${i}`"
                    >{{ r.start_time }}–{{ r.end_time }}<span v-if="i < todayWorking.length - 1">, </span></span>
                  </template>
                  <template v-else>
                    Not scheduled today
                  </template>
                </dd>
              </div>
              <div class="rounded-card bg-sv-warning-bg p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-sv-warning-fg">
                  Breaks
                </dt>
                <dd class="mt-2 text-sm font-semibold text-text">
                  <template v-if="todayBreaks.length > 0">
                    <span
                      v-for="(r, i) in todayBreaks"
                      :key="`tb-${i}`"
                    >{{ r.start_time }}–{{ r.end_time }}<span v-if="i < todayBreaks.length - 1">, </span></span>
                  </template>
                  <template v-else>
                    None
                  </template>
                </dd>
              </div>
              <div class="rounded-card bg-sv-surface-subtle p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Temporary unavailability
                </dt>
                <dd class="mt-2 text-sm font-semibold text-text">
                  <template v-if="todayUnavailable.length > 0">
                    <span
                      v-for="(r, i) in todayUnavailable"
                      :key="`tu-${i}`"
                    >{{ r.start_time }}–{{ r.end_time }}<span v-if="i < todayUnavailable.length - 1">, </span></span>
                  </template>
                  <template v-else>
                    None
                  </template>
                </dd>
              </div>
            </dl>
          </section>

          <section class="sv-r-panel">
            <h2 class="sv-r-title">
              Weekly schedule
            </h2>
            <ul class="mt-4 grid grid-cols-1 gap-2 md:grid-cols-7">
              <li
                v-for="(label, weekday) in WEEKDAY_LABELS"
                :key="weekday"
                class="flex items-center justify-between gap-2 rounded-card border p-3 text-sm md:flex-col md:items-start"
                :class="weekday === todayWeekday ? 'border-sv-brand bg-sv-surface-warm' : 'border-sv-border bg-sv-surface-raised'"
              >
                <span class="font-semibold text-text">{{ label }}</span>
                <span class="text-text-muted">
                  <template
                    v-for="(r, i) in availability.schedule.recurring.filter((row) => row.weekday === weekday && row.available)"
                    :key="`r-${weekday}-${i}`"
                  >
                    <span v-if="i > 0">, </span>{{ r.start_time }}–{{ r.end_time }}
                  </template>
                  <template
                    v-if="availability.schedule.recurring.filter((row) => row.weekday === weekday && row.available).length === 0"
                  >
                    Day off
                  </template>
                </span>
              </li>
            </ul>
          </section>
        </div>
      </SvStateBoundary>
    </div>
  </section>
</template>