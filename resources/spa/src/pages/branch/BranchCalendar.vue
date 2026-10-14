<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useBranchCalendarStore } from '@/stores/branchCalendarStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePermissionStore } from '@/stores/permissionStore';
import type { BranchCalendarException, CalendarExceptionType } from '@/types/models';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

/**
 * Branch calendar — date-specific closures and modified hours (REM-SCR-002B; Plan §7.2, §27.3
 * Branch Manager "branch profile/calendar", Scope §3.3).
 *
 * These exceptions are already honoured by the appointment scheduling gate; this screen is the
 * operator surface that was missing. Frontend gating is UX only — `branch.calendar.manage`,
 * EnsureBranchScope, EnsureBillingMutable and BranchCalendarExceptionPolicy are the boundary.
 */
const route = useRoute();
const auth = useAuthStore();
const permissions = usePermissionStore();
const store = useBranchCalendarStore();
const notifications = useNotificationStore();

const TYPE_LABELS: Record<CalendarExceptionType, string> = {
  public_holiday: 'Public holiday',
  special_closure: 'Special closure',
  emergency_closure: 'Emergency closure',
  modified_hours: 'Modified hours',
};

const loadFailed = ref(false);
const editing = ref<string | null>(null);

/** The branch this screen operates on: the route param when present, else the resolved context. */
const branchId = computed(
  () => String(route.params.id ?? '') || (auth.branchIds[0] ?? ''),
);

const canManage = computed(() => permissions.can('branch.calendar.manage'));

const boundaryState = computed<'loading' | 'error' | 'empty' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (loadFailed.value) return 'error';
  if (store.exceptions.length === 0) return 'empty';
  return 'success';
});

const form = reactive<{
  date: string;
  type: CalendarExceptionType;
  opens_at: string;
  closes_at: string;
  reason: string;
}>({
  date: '',
  type: 'public_holiday',
  opens_at: '',
  closes_at: '',
  reason: '',
});

const needsWindow = computed(() => form.type === 'modified_hours');

/** Inline editor state for the row currently open (see `editing`). */
const editForm = reactive({ opens_at: '', closes_at: '', reason: '' });

onMounted(load);

async function load(): Promise<void> {
  loadFailed.value = false;
  try {
    await store.fetchExceptions(branchId.value);
  } catch {
    loadFailed.value = true;
  }
}

function errorsFor(field: string): string[] {
  return store.fieldErrors[field] ?? [];
}

const conflictMessage = computed(() => {
  if (store.errorCode === 'calendar_exception_exists') {
    return 'That date already has an exception. Edit or remove it first.';
  }
  if (store.errorCode === 'billing_read_only') {
    return 'Billing access is read-only, so the calendar cannot be changed right now.';
  }
  return null;
});

async function create(): Promise<void> {
  try {
    await store.createException(branchId.value, {
      date: form.date,
      type: form.type,
      opens_at: needsWindow.value ? form.opens_at : null,
      closes_at: needsWindow.value ? form.closes_at : null,
      reason: form.reason === '' ? null : form.reason,
    });
    form.date = '';
    form.opens_at = '';
    form.closes_at = '';
    form.reason = '';
    notifications.addToast({ type: 'success', message: 'Calendar exception saved.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Could not save the calendar exception.' });
  }
}

async function remove(exception: BranchCalendarException): Promise<void> {
  try {
    await store.removeException(branchId.value, exception.date);
    notifications.addToast({ type: 'success', message: 'Calendar exception removed.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Could not remove the calendar exception.' });
  }
}

/** Open the inline editor for one date. `(date, type)` is the row identity and is never editable. */
function startEdit(exception: BranchCalendarException): void {
  editing.value = exception.date;
  editForm.opens_at = exception.opens_at ?? '';
  editForm.closes_at = exception.closes_at ?? '';
  editForm.reason = exception.reason ?? '';
}

async function saveEdit(exception: BranchCalendarException): Promise<void> {
  try {
    await store.updateException(branchId.value, exception.date, {
      // A closure type has no window — the API rejects times on one, so none are sent.
      ...(exception.closes_branch
        ? {}
        : { opens_at: editForm.opens_at, closes_at: editForm.closes_at }),
      reason: editForm.reason === '' ? null : editForm.reason,
    });
    editing.value = null;
    notifications.addToast({ type: 'success', message: 'Calendar exception updated.' });
  } catch {
    notifications.addToast({ type: 'error', message: 'Could not update the calendar exception.' });
  }
}

/** Month abbreviations for the calendar tiles, read from the ISO date string (no time-zone shift). */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const;
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — the operating calendar as dated TILES. Each exception is a calendar
    tile (day and month set large, the exception type, and a timing cue: closed all day, or the
    special opening window) so the schedule reads at a glance; the add-exception form sits in the
    rail, first in the DOM so it stays the page's primary form, and the inline editor keeps the
    row identity (date and type) read-only. Weekly hours still apply wherever no tile exists.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Branch calendar"
      eyebrow="Branch setup"
      description="Close the branch for a date, or open it on different hours. These dates override the weekly operating hours and block appointments accordingly."
    />

    <p
      v-if="conflictMessage"
      role="alert"
      class="mb-4 rounded-control border border-sv-error-border bg-sv-error-bg p-3 text-sm text-sv-error-fg"
    >
      {{ conflictMessage }}
    </p>

    <div class="sv-r-grid sv-r-split">
      <aside
        v-if="canManage"
        class="sv-r-panel sv-r-panel--tonal sv-r-tone-green sv-r-sticky lg:order-last"
        aria-label="Add an exception"
      >
        <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-green">
          Override the weekly hours
        </p>
        <h2 class="sv-r-title mt-1">
          Add an exception
        </h2>
        <form
          class="mt-3 flex flex-col gap-4"
          novalidate
          @submit.prevent="create"
        >
          <div class="flex flex-col gap-1">
            <label
              for="bc-date"
              class="text-sm font-medium text-text"
            >Date</label>
            <input
              id="bc-date"
              v-model="form.date"
              type="date"
              required
              :aria-invalid="errorsFor('date').length > 0"
              :aria-describedby="errorsFor('date').length ? 'bc-date-error' : undefined"
              class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
            >
            <p
              v-if="errorsFor('date').length"
              id="bc-date-error"
              class="text-sm text-sv-error-fg"
            >
              {{ errorsFor('date')[0] }}
            </p>
          </div>

          <div class="flex flex-col gap-1">
            <label
              for="bc-type"
              class="text-sm font-medium text-text"
            >Type</label>
            <select
              id="bc-type"
              v-model="form.type"
              :aria-invalid="errorsFor('type').length > 0"
              :aria-describedby="errorsFor('type').length ? 'bc-type-error' : undefined"
              class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
            >
              <option
                v-for="(label, value) in TYPE_LABELS"
                :key="value"
                :value="value"
              >
                {{ label }}
              </option>
            </select>
            <p
              v-if="errorsFor('type').length"
              id="bc-type-error"
              class="text-sm text-sv-error-fg"
            >
              {{ errorsFor('type')[0] }}
            </p>
          </div>

          <div
            v-if="needsWindow"
            class="flex flex-wrap gap-4"
          >
            <div class="flex min-w-[8rem] flex-1 flex-col gap-1">
              <label
                for="bc-opens-at"
                class="text-sm font-medium text-text"
              >Opens at</label>
              <input
                id="bc-opens-at"
                v-model="form.opens_at"
                type="time"
                :aria-invalid="errorsFor('opens_at').length > 0"
                :aria-describedby="errorsFor('opens_at').length ? 'bc-opens-at-error' : undefined"
                class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
              >
              <p
                v-if="errorsFor('opens_at').length"
                id="bc-opens-at-error"
                class="text-sm text-sv-error-fg"
              >
                {{ errorsFor('opens_at')[0] }}
              </p>
            </div>
            <div class="flex min-w-[8rem] flex-1 flex-col gap-1">
              <label
                for="bc-closes-at"
                class="text-sm font-medium text-text"
              >Closes at</label>
              <input
                id="bc-closes-at"
                v-model="form.closes_at"
                type="time"
                :aria-invalid="errorsFor('closes_at').length > 0"
                :aria-describedby="errorsFor('closes_at').length ? 'bc-closes-at-error' : undefined"
                class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
              >
              <p
                v-if="errorsFor('closes_at').length"
                id="bc-closes-at-error"
                class="text-sm text-sv-error-fg"
              >
                {{ errorsFor('closes_at')[0] }}
              </p>
            </div>
          </div>

          <div class="flex flex-col gap-1">
            <label
              for="bc-reason"
              class="text-sm font-medium text-text"
            >Reason (optional)</label>
            <input
              id="bc-reason"
              v-model="form.reason"
              type="text"
              :aria-invalid="errorsFor('reason').length > 0"
              class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
            >
            <p
              v-if="errorsFor('reason').length"
              class="text-sm text-sv-error-fg"
            >
              {{ errorsFor('reason')[0] }}
            </p>
          </div>

          <div class="flex justify-end">
            <SvButton
              type="submit"
              :disabled="store.saving"
            >
              {{ store.saving ? 'Saving…' : 'Add exception' }}
            </SvButton>
          </div>
        </form>
      </aside>

      <div class="min-w-0">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
          <h2 class="sv-r-title">
            Scheduled exceptions
            <span
              v-if="store.range"
              class="sv-r-chip ml-2 align-middle font-semibold"
            >({{ store.range.from }} to {{ store.range.to }})</span>
          </h2>
        </div>

        <SvStateBoundary
          :state="boundaryState"
          empty-message="No calendar exceptions in this period — the weekly operating hours apply."
          error-message="We could not load the branch calendar."
          @retry="load"
        >
          <div>
            <!--
              The tiles keep TABLE semantics (role=table/row/cell, with a visually hidden header
              row): each exception is one row whose date, type, hours, reason and actions are cells,
              exactly as the REM-SCR-002B contract reads them. Only generic elements sit between
              a row and its cells, so ownership holds.
            -->
            <div
              role="table"
              class="grid gap-3 md:grid-cols-2"
              aria-label="Branch calendar exceptions with their type, hours and reason"
            >
              <div
                role="row"
                class="sr-only"
              >
                <span role="columnheader">Date</span>
                <span role="columnheader">Type</span>
                <span role="columnheader">Hours</span>
                <span role="columnheader">Reason</span>
                <span
                  v-if="canManage"
                  role="columnheader"
                >Actions</span>
              </div>
              <div
                v-for="exception in store.exceptions"
                :key="exception.date"
                role="row"
                class="sv-r-record flex gap-4"
              >
                <span
                  aria-hidden="true"
                  class="flex w-16 shrink-0 flex-col items-center justify-center rounded-card py-2"
                  :class="exception.closes_branch ? 'bg-sv-error-bg text-sv-error-fg' : 'bg-sv-warning-bg text-sv-warning-fg'"
                >
                  <span class="font-display text-2xl font-extrabold leading-none">{{ exception.date.slice(8, 10) }}</span>
                  <span class="mt-1 text-[0.6875rem] font-bold uppercase tracking-wider">{{ MONTHS[Number(exception.date.slice(5, 7)) - 1] }}</span>
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-baseline gap-x-1 text-sm font-semibold text-heading">
                    <span role="cell">{{ exception.date }}</span>
                    <span aria-hidden="true">—</span>
                    <span role="cell">{{ TYPE_LABELS[exception.type] }}</span>
                  </div>
                  <div class="mt-1">
                    <span
                      role="cell"
                      class="sv-r-chip sv-r-chip--tone sv-r-chip--dot"
                      :class="exception.closes_branch ? 'sv-r-tone-alert' : 'sv-r-tone-sun'"
                    >{{ exception.closes_branch ? 'Closed all day' : `${exception.opens_at} – ${exception.closes_at}` }}</span>
                  </div>
                  <div
                    role="cell"
                    class="mt-2 text-sm text-text"
                  >
                    <span class="text-sv-text-muted">Reason: </span>{{ exception.reason ?? '—' }}
                  </div>
                  <div
                    v-if="canManage"
                    role="cell"
                    class="mt-3 flex flex-wrap gap-2"
                  >
                    <SvButton
                      variant="secondary"
                      size="sm"
                      :disabled="store.saving"
                      @click="startEdit(exception)"
                    >
                      Edit
                    </SvButton>
                    <SvButton
                      variant="secondary"
                      size="sm"
                      :disabled="store.saving"
                      @click="remove(exception)"
                    >
                      Remove
                    </SvButton>
                  </div>
                </div>
              </div>
            </div>
            <!-- Inline editor: the date and type are the row identity and stay read-only. -->
            <form
              v-for="exception in store.exceptions.filter((e) => e.date === editing)"
              :key="`edit-${exception.date}`"
              class="mt-3 flex flex-wrap items-end gap-3 rounded-card border-2 border-sv-brand bg-sv-surface-raised p-4"
              novalidate
              @submit.prevent="saveEdit(exception)"
            >
              <p class="w-full text-sm font-semibold text-heading">
                Editing {{ exception.date }} — {{ TYPE_LABELS[exception.type] }}
              </p>
              <template v-if="!exception.closes_branch">
                <div class="flex min-w-[8rem] flex-1 flex-col gap-1">
                  <label
                    :for="`bc-edit-opens-${exception.date}`"
                    class="text-sm font-medium text-text"
                  >Opens at</label>
                  <input
                    :id="`bc-edit-opens-${exception.date}`"
                    v-model="editForm.opens_at"
                    type="time"
                    class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
                  >
                </div>
                <div class="flex min-w-[8rem] flex-1 flex-col gap-1">
                  <label
                    :for="`bc-edit-closes-${exception.date}`"
                    class="text-sm font-medium text-text"
                  >Closes at</label>
                  <input
                    :id="`bc-edit-closes-${exception.date}`"
                    v-model="editForm.closes_at"
                    type="time"
                    class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
                  >
                </div>
              </template>
              <div class="flex min-w-[8rem] flex-1 flex-col gap-1">
                <label
                  :for="`bc-edit-reason-${exception.date}`"
                  class="text-sm font-medium text-text"
                >Reason</label>
                <input
                  :id="`bc-edit-reason-${exception.date}`"
                  v-model="editForm.reason"
                  type="text"
                  class="min-h-[44px] w-full rounded-control border border-border bg-surface px-3 text-text"
                >
              </div>
              <p
                v-if="errorsFor('closes_at').length || errorsFor('opens_at').length"
                role="alert"
                class="w-full text-sm text-sv-error-fg"
              >
                {{ errorsFor('closes_at')[0] ?? errorsFor('opens_at')[0] }}
              </p>
              <SvButton
                type="submit"
                :disabled="store.saving"
              >
                Save
              </SvButton>
              <SvButton
                variant="ghost"
                @click="editing = null"
              >
                Cancel
              </SvButton>
            </form>
          </div>
        </SvStateBoundary>

        <p
          v-if="!canManage"
          class="mt-4 text-sm text-sv-text-muted"
        >
          You have view-only access to the branch calendar.
        </p>
      </div>
    </div>
  </section>
</template>
