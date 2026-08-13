<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, RouterLink } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { apiClient } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import {
  type AvailabilityException,
  type AvailabilityRecurring,
  useAvailabilityStore,
} from '@/stores/availabilityStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePermissionStore } from '@/stores/permissionStore';
import type { StaffProfile } from '@/types/models';

// HR personnel availability (Plan §13.7, §80 Phase 15B). HR sets recurring working
// days, split shifts, breaks, date exceptions, days off, and emergency
// unavailability for personnel in its own branch. The API
// (`personnel.availability.manage` + same-branch policy) is the security boundary;
// these checks are UX only.
const availability = useAvailabilityStore();
const notifications = useNotificationStore();
const permissions = usePermissionStore();
const auth = useAuthStore();

const WEEKDAYS = [
  { value: 0, label: 'Sunday' },
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
];

const STATE_LABELS: Record<string, string> = {
  suspended: 'Suspended',
  available: 'Available',
  on_break: 'On break',
  unavailable: 'Unavailable',
  offline: 'Offline',
};

const canManage = computed(() => permissions.can('personnel.availability.manage'));
const hasBranch = computed(() => auth.branchIds.length > 0);

const staff = ref<StaffProfile[]>([]);
const selectedStaff = ref('');
const recurring = ref<AvailabilityRecurring[]>([]);
const exceptions = ref<AvailabilityException[]>([]);
const changeReason = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const baseline = ref('');

const emergencyOpen = ref(false);
const emergency = ref({ date: '', start_time: '', end_time: '', change_reason: '' });

const staffOptions = computed(() => staff.value.map((s) => ({ value: s.id, label: s.display_name })));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (selectedStaff.value === '') return 'empty';
  if (availability.loading) return 'loading';
  if (availability.error) return 'error';
  return 'success';
});

function snapshot(): string {
  return JSON.stringify({ recurring: recurring.value, exceptions: exceptions.value, changeReason: changeReason.value });
}

const dirty = computed(() => baseline.value !== '' && snapshot() !== baseline.value);
const canSave = computed(() => canManage.value && dirty.value && changeReason.value.trim() !== '' && !availability.saving);

function workingFor(weekday: number): AvailabilityRecurring[] {
  return recurring.value.filter((r) => r.weekday === weekday && r.available);
}

function breaksFor(weekday: number): AvailabilityRecurring[] {
  return recurring.value.filter((r) => r.weekday === weekday && !r.available);
}

function addInterval(weekday: number, available: boolean): void {
  recurring.value = [...recurring.value, { weekday, start_time: '09:00', end_time: '17:00', available }];
}

function removeRecurring(row: AvailabilityRecurring): void {
  recurring.value = recurring.value.filter((r) => r !== row);
}

function clearWeekday(weekday: number): void {
  recurring.value = recurring.value.filter((r) => r.weekday !== weekday);
}

function addException(): void {
  exceptions.value = [...exceptions.value, { date: '', start_time: '09:00', end_time: '17:00', available: false }];
}

function removeException(row: AvailabilityException): void {
  exceptions.value = exceptions.value.filter((e) => e !== row);
}

async function loadStaff(): Promise<void> {
  const { data } = await apiClient.get<{ data: StaffProfile[] }>('/staff', { params: { per_page: 100, status: 'active', employment_status: 'employed' } });
  staff.value = data.data;
}

function hydrate(): void {
  const schedule = availability.schedule;
  recurring.value = schedule ? schedule.recurring.map((r) => ({ ...r })) : [];
  exceptions.value = schedule ? schedule.exceptions.map((e) => ({ ...e })) : [];
  changeReason.value = '';
  fieldErrors.value = {};
  baseline.value = snapshot();
}

watch(selectedStaff, async (id) => {
  if (id === '') return;
  await availability.fetch(id);
  hydrate();
});

async function save(): Promise<void> {
  if (!canSave.value) return;
  fieldErrors.value = {};
  try {
    await availability.replace(selectedStaff.value, {
      recurring: recurring.value,
      exceptions: exceptions.value,
      change_reason: changeReason.value.trim(),
    });
    hydrate();
    notifications.addToast({ type: 'success', message: 'Availability saved.' });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      fieldErrors.value = err.apiError.fields ?? {};
      notifications.addToast({ type: 'error', message: err.apiError.message });
    } else {
      notifications.addToast({ type: 'error', message: 'Unable to save availability.' });
    }
  }
}

async function submitEmergency(): Promise<void> {
  try {
    await availability.emergencyUnavailable(selectedStaff.value, { ...emergency.value });
    hydrate();
    emergencyOpen.value = false;
    emergency.value = { date: '', start_time: '', end_time: '', change_reason: '' };
    notifications.addToast({ type: 'success', message: 'Personnel marked unavailable.' });
  } catch (err: unknown) {
    const message = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'Unable to mark unavailable.';
    notifications.addToast({ type: 'error', message });
  }
}

const fieldErrorList = computed(() => Object.entries(fieldErrors.value).flatMap(([, messages]) => messages));

onMounted(async () => {
  if (canManage.value && hasBranch.value) {
    await loadStaff();
  }
});

onBeforeRouteLeave(() => {
  if (dirty.value && !window.confirm('You have unsaved availability changes. Leave without saving?')) {
    return false;
  }
  return true;
});
</script>

<template>
  <section
    class="mx-auto max-w-6xl"
    data-testid="hr-availability"
  >
    <SvPageHeader
      title="Availability and shifts"
      eyebrow="Workforce readiness"
      description="Set recurring shifts, breaks, date exceptions and emergency unavailability for personnel in your assigned branch. Times use Africa/Nairobi."
    />

    <!-- No-permission state (UX only; the API is the boundary). -->
    <SvCard
      v-if="!canManage"
      as="div"
      padding="md"
      class="mt-6"
      data-testid="no-permission"
    >
      <p class="text-sm text-text-muted">
        You do not have permission to manage personnel availability.
      </p>
    </SvCard>

    <!-- No-branch state. -->
    <SvCard
      v-else-if="!hasBranch"
      as="div"
      padding="md"
      class="mt-6"
      data-testid="no-branch"
    >
      <p class="text-sm text-text-muted">
        You are not assigned to a branch yet, so there is no personnel to manage.
      </p>
    </SvCard>

    <template v-else>
      <SvCard
        as="div"
        padding="md"
        class="mt-6"
      >
        <SvSelect
          id="staff"
          v-model="selectedStaff"
          label="Personnel"
          placeholder="Select personnel"
          :options="staffOptions"
        />
      </SvCard>

      <SvStateBoundary
        class="mt-6"
        :state="boundaryState"
        empty-message="Select a personnel member to manage their availability."
      >
        <div
          v-if="availability.schedule"
          class="flex flex-col gap-6"
        >
          <!-- Identity + derived state + eligible services. -->
          <SvCard
            as="div"
            padding="md"
            class="flex flex-col gap-3"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="text-base font-semibold text-text">
                  {{ availability.schedule.staff.display_name }}
                </p>
                <p class="text-xs text-text-muted">
                  Lifecycle: {{ availability.schedule.staff.employment_status }}
                </p>
              </div>
              <span
                class="rounded-full bg-surface-alt px-3 py-1 text-xs font-semibold text-text"
                data-testid="current-state"
                aria-live="polite"
              >
                {{ STATE_LABELS[availability.schedule.current_state] }}
              </span>
            </div>
            <div class="text-xs text-text-muted">
              <span class="font-medium">Eligible services:</span>
              <template v-if="availability.schedule.eligible_services.length > 0">
                {{ availability.schedule.eligible_services.map((s) => s.name).join(', ') }}
              </template>
              <template v-else>
                none yet
              </template>
              —
              <RouterLink
                :to="{ name: 'hr.eligibility' }"
                class="font-semibold text-brand-deep dark:text-text underline"
              >
                Manage eligibility
              </RouterLink>
            </div>
          </SvCard>

          <!-- Validation summary. -->
          <SvCard
            v-if="fieldErrorList.length > 0"
            as="div"
            padding="sm"
            class="border border-error"
            data-testid="validation-summary"
            role="alert"
          >
            <p class="text-sm font-semibold text-error">
              Please fix the following:
            </p>
            <ul class="mt-1 list-disc pl-5 text-sm text-error">
              <li
                v-for="(message, i) in fieldErrorList"
                :key="i"
              >
                {{ message }}
              </li>
            </ul>
          </SvCard>

          <!-- Weekly recurring editor. -->
          <div class="flex flex-col gap-4">
            <h2 class="font-display text-lg font-semibold text-brand-deep dark:text-text">
              Weekly schedule
            </h2>
            <SvCard
              v-for="day in WEEKDAYS"
              :key="day.value"
              as="div"
              padding="sm"
              :data-testid="`weekday-${day.value}`"
            >
              <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-text">{{ day.label }}</span>
                <button
                  type="button"
                  class="text-xs font-semibold text-text-muted underline"
                  :data-testid="`day-off-${day.value}`"
                  @click="clearWeekday(day.value)"
                >
                  Day off
                </button>
              </div>

              <div class="mt-2 flex flex-col gap-2">
                <p class="text-xs font-medium uppercase tracking-wide text-text-muted">
                  Working intervals
                </p>
                <div
                  v-for="(row, i) in workingFor(day.value)"
                  :key="`w-${day.value}-${i}`"
                  class="flex flex-wrap items-center gap-2"
                  :data-testid="`working-${day.value}`"
                >
                  <SvTextInput
                    :id="`w-start-${day.value}-${i}`"
                    v-model="row.start_time"
                    type="time"
                    label="Start"
                  />
                  <span class="text-text-muted">–</span>
                  <SvTextInput
                    :id="`w-end-${day.value}-${i}`"
                    v-model="row.end_time"
                    type="time"
                    label="End"
                  />
                  <button
                    type="button"
                    class="text-sm font-semibold text-error underline"
                    @click="removeRecurring(row)"
                  >
                    Remove
                  </button>
                </div>
                <SvButton
                  variant="ghost"
                  :data-testid="`add-working-${day.value}`"
                  @click="addInterval(day.value, true)"
                >
                  Add working interval
                </SvButton>

                <p class="mt-2 text-xs font-medium uppercase tracking-wide text-text-muted">
                  Breaks
                </p>
                <div
                  v-for="(row, i) in breaksFor(day.value)"
                  :key="`b-${day.value}-${i}`"
                  class="flex flex-wrap items-center gap-2"
                  :data-testid="`break-${day.value}`"
                >
                  <SvTextInput
                    :id="`b-start-${day.value}-${i}`"
                    v-model="row.start_time"
                    type="time"
                    label="Break start"
                  />
                  <span class="text-text-muted">–</span>
                  <SvTextInput
                    :id="`b-end-${day.value}-${i}`"
                    v-model="row.end_time"
                    type="time"
                    label="Break end"
                  />
                  <button
                    type="button"
                    class="text-sm font-semibold text-error underline"
                    @click="removeRecurring(row)"
                  >
                    Remove
                  </button>
                </div>
                <SvButton
                  variant="ghost"
                  :data-testid="`add-break-${day.value}`"
                  @click="addInterval(day.value, false)"
                >
                  Add break
                </SvButton>
              </div>
            </SvCard>
          </div>

          <!-- Date-specific exceptions. -->
          <div class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
              <h2 class="font-display text-lg font-semibold text-brand-deep dark:text-text">
                Date exceptions
              </h2>
              <SvButton
                variant="ghost"
                data-testid="add-exception"
                @click="addException"
              >
                Add exception
              </SvButton>
            </div>
            <SvCard
              v-for="(row, i) in exceptions"
              :key="`ex-${i}`"
              as="div"
              padding="sm"
              class="flex flex-wrap items-end gap-2"
              :data-testid="`exception-${i}`"
            >
              <SvTextInput
                :id="`ex-date-${i}`"
                v-model="row.date"
                type="date"
                label="Date"
              />
              <SvTextInput
                :id="`ex-start-${i}`"
                v-model="row.start_time"
                type="time"
                label="Start"
              />
              <SvTextInput
                :id="`ex-end-${i}`"
                v-model="row.end_time"
                type="time"
                label="End"
              />
              <label
                class="flex flex-col gap-1 text-sm font-medium text-text"
                :for="`ex-available-${i}`"
              >
                Type
                <select
                  :id="`ex-available-${i}`"
                  class="min-h-[44px] rounded-control border border-border bg-surface px-3 py-2 text-sm text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  :value="row.available ? 'available' : 'unavailable'"
                  @change="row.available = ($event.target as HTMLSelectElement).value === 'available'"
                >
                  <option value="unavailable">
                    Unavailable
                  </option>
                  <option value="available">
                    Available
                  </option>
                </select>
              </label>
              <button
                type="button"
                class="pb-2 text-sm font-semibold text-error underline"
                @click="removeException(row)"
              >
                Remove
              </button>
            </SvCard>
            <p
              v-if="exceptions.length === 0"
              class="text-sm text-text-muted"
            >
              No date-specific exceptions.
            </p>
          </div>

          <!-- Reason + save + emergency. -->
          <SvCard
            as="div"
            padding="md"
            class="flex flex-col gap-3"
          >
            <SvTextArea
              id="change-reason"
              v-model="changeReason"
              label="Reason for change"
              placeholder="Why is the schedule changing?"
              :rows="2"
            />
            <div class="flex flex-wrap items-center gap-2">
              <SvButton
                variant="primary"
                data-testid="save-availability"
                :disabled="!canSave"
                :loading="availability.saving"
                @click="save"
              >
                Save availability
              </SvButton>
              <SvButton
                variant="secondary"
                data-testid="open-emergency"
                @click="emergencyOpen = true"
              >
                Emergency unavailable
              </SvButton>
              <span
                v-if="dirty"
                class="text-xs text-text-muted"
                data-testid="unsaved-indicator"
              >
                Unsaved changes
              </span>
            </div>
          </SvCard>
        </div>
      </SvStateBoundary>

      <!-- Emergency unavailable modal. -->
      <SvDialog
        :open="emergencyOpen"
        title="Emergency unavailable"
        @close="emergencyOpen = false"
      >
        <div class="flex flex-col gap-3">
          <SvTextInput
            id="em-date"
            v-model="emergency.date"
            type="date"
            label="Date"
          />
          <div class="flex gap-2">
            <SvTextInput
              id="em-start"
              v-model="emergency.start_time"
              type="time"
              label="Start"
            />
            <SvTextInput
              id="em-end"
              v-model="emergency.end_time"
              type="time"
              label="End"
            />
          </div>
          <SvTextArea
            id="em-reason"
            v-model="emergency.change_reason"
            label="Reason"
            :rows="2"
          />
          <SvButton
            variant="primary"
            data-testid="submit-emergency"
            :disabled="emergency.date === '' || emergency.change_reason.trim() === '' || availability.saving"
            @click="submitEmergency"
          >
            Mark unavailable
          </SvButton>
        </div>
      </SvDialog>
    </template>
  </section>
</template>
