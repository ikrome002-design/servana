<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import SvCard from '@/components/ui/SvCard.vue';
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
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Personnel schedule
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      View personnel availability for your branch. Times are in
      <span class="font-medium">Africa/Nairobi</span>. Schedules are managed by HR.
    </p>

    <SvCard
      v-if="!canView"
      as="div"
      padding="md"
      class="mt-6"
      data-testid="no-permission"
    >
      <p class="text-sm text-text-muted">
        You do not have permission to view personnel schedules.
      </p>
    </SvCard>

    <SvCard
      v-else-if="!hasBranch"
      as="div"
      padding="md"
      class="mt-6"
      data-testid="no-branch"
    >
      <p class="text-sm text-text-muted">
        You are not assigned to a branch yet.
      </p>
    </SvCard>

    <template v-else>
      <SvCard
        as="div"
        padding="md"
        class="mt-6"
      >
        <SvSelect
          id="bm-staff"
          v-model="selectedStaff"
          label="Personnel"
          placeholder="Select personnel"
          :options="staffOptions"
        />
      </SvCard>

      <SvStateBoundary
        class="mt-6"
        :state="boundaryState"
        empty-message="Select a personnel member to view their schedule."
      >
        <div
          v-if="availability.schedule"
          class="flex flex-col gap-4"
        >
          <SvCard
            as="div"
            padding="md"
            class="flex flex-wrap items-center justify-between gap-2"
          >
            <div>
              <p class="text-base font-semibold text-text">
                {{ availability.schedule.staff.display_name }}
              </p>
              <p class="text-xs text-text-muted">
                Eligible services:
                <template v-if="availability.schedule.eligible_services.length > 0">
                  {{ availability.schedule.eligible_services.map((s) => s.name).join(', ') }}
                </template>
                <template v-else>
                  none
                </template>
              </p>
            </div>
            <span
              class="rounded-full bg-surface-alt px-3 py-1 text-xs font-semibold text-text"
              data-testid="bm-current-state"
              aria-live="polite"
            >
              {{ STATE_LABELS[availability.schedule.current_state] }}
            </span>
          </SvCard>

          <SvCard
            as="div"
            padding="md"
            data-testid="bm-today"
          >
            <h2 class="font-display text-lg font-semibold text-heading">
              Today ({{ WEEKDAY_LABELS[todayWeekday] }})
            </h2>
            <dl class="mt-2 flex flex-col gap-2 text-sm">
              <div>
                <dt class="font-medium text-text">
                  Working intervals
                </dt>
                <dd class="text-text-muted">
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
              <div>
                <dt class="font-medium text-text">
                  Breaks
                </dt>
                <dd class="text-text-muted">
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
              <div>
                <dt class="font-medium text-text">
                  Temporary unavailability
                </dt>
                <dd class="text-text-muted">
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
          </SvCard>

          <SvCard
            as="div"
            padding="md"
          >
            <h2 class="font-display text-lg font-semibold text-heading">
              Weekly schedule
            </h2>
            <ul class="mt-2 flex flex-col gap-1 text-sm">
              <li
                v-for="(label, weekday) in WEEKDAY_LABELS"
                :key="weekday"
                class="flex justify-between"
              >
                <span class="font-medium text-text">{{ label }}</span>
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
          </SvCard>
        </div>
      </SvStateBoundary>
    </template>
  </section>
</template>
