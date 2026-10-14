<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconLocked } from '@/design-system/icons';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { usePeriodLockStore, type PeriodLockView } from '@/stores/periodLockStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

/**
 * Finance financial periods (Plan §46; ADR-0007; Phase 18B). Finance creates locks
 * (merchant-wide or branch) and executes a controlled reopen (fresh MFA). An
 * `exception_required` lock additionally needs a Merchant Administrator approval before
 * Finance may execute — surfaced here as a waiting state. Routine locking is NOT exposed
 * to the Merchant Administrator.
 */
const store = usePeriodLockStore();
const permissions = usePermissionStore();

const creating = ref(false);
const reopening = ref<PeriodLockView | null>(null);
const busy = ref(false);
const actionError = ref<string | null>(null);
const reason = ref('');
const form = ref({ period_start: '', period_end: '', exception_required: false });

const canCreate = computed(() => permissions.can('period_lock.create'));
const canReopen = computed(() => permissions.can('period_lock.reopen'));

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.locks.length === 0) return 'empty';
  return 'success';
});

async function submitCreate(): Promise<void> {
  if (form.value.period_start === '' || form.value.period_end === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.create({
      period_start: form.value.period_start,
      period_end: form.value.period_end,
      exception_required: form.value.exception_required,
    });
    creating.value = false;
    form.value = { period_start: '', period_end: '', exception_required: false };
    await store.fetchLocks();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The lock could not be created.';
  } finally {
    busy.value = false;
  }
}

async function submitReopenRequest(): Promise<void> {
  if (reopening.value === null || reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.requestReopen(reopening.value.id, reason.value.trim());
    reopening.value = null;
    reason.value = '';
    await store.fetchLocks();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The reopen request failed.';
  } finally {
    busy.value = false;
  }
}

async function executeReopen(lock: PeriodLockView): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.execute(lock.id);
    await store.fetchLocks();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The reopen could not be executed (a fresh step-up may be required).';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchLocks();
});
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — financial periods as SEALED RANGES on a timeline. Each lock shows its
    date range, scope and status beside a lock marker; a locked period carries its reopen trail
    (requested → owner approval when exceptional → executed with fresh step-up) so the next owner
    action is unmistakable. Controls and their gates are unchanged.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Financial periods"
      eyebrow="Controls & Close"
      description="Lock a closed period so its records cannot change. A reopen needs a recorded reason, and an exceptional reopen needs owner approval."
    >
      <template #actions>
        <SvButton
          v-if="canCreate"
          data-testid="period-lock-create-open"
          @click="creating = true"
        >
          Lock a period
        </SvButton>
      </template>
    </SvPageHeader>

    <p
      v-if="actionError"
      class="mb-4 rounded-field border border-sv-error-border bg-sv-error-bg px-4 py-3 text-sm text-sv-error-fg"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load financial periods."
      empty-message="No period locks yet."
    >
      <div class="sv-r-grid sv-r-split">
        <ol
          class="sv-r-steps min-w-0"
          aria-label="Financial period locks"
        >
          <li
            v-for="lock in store.locks"
            :key="lock.id"
            class="sv-r-step"
            :class="lock.status === 'locked' ? 'sv-r-step--done' : 'sv-r-step--future'"
          >
            <span
              aria-hidden="true"
              class="sv-r-step__marker"
            ><SvIconLocked class="h-4 w-4" /></span>
            <section
              class="sv-r-record"
              data-testid="period-lock-row"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="sv-r-eyebrow">
                    {{ lock.scope === 'merchant' ? 'Merchant-wide' : `Branch: ${lock.branch?.name ?? '—'}` }}
                  </p>
                  <h2 class="mt-1 font-display text-lg font-extrabold text-heading">
                    {{ lock.period_start }} → {{ lock.period_end }}
                  </h2>
                  <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-text-muted">
                    <span class="inline-flex rounded-pill border border-sv-border bg-surface-alt px-2.5 py-0.5 text-xs font-semibold capitalize text-text">{{ lock.status.replaceAll('_', ' ') }}</span>
                    <span v-if="lock.exception_required">Exceptional reopen</span>
                  </p>
                </div>
                <div
                  v-if="canReopen && lock.status === 'locked'"
                  class="flex flex-wrap gap-2"
                >
                  <SvButton
                    v-if="!lock.reopen_requested_at"
                    variant="secondary"
                    data-testid="period-reopen-request"
                    @click="reopening = lock"
                  >
                    Request reopen
                  </SvButton>
                  <SvButton
                    v-else
                    data-testid="period-reopen-execute"
                    :loading="busy"
                    @click="executeReopen(lock)"
                  >
                    Execute reopen
                  </SvButton>
                </div>
              </div>
              <ol
                v-if="lock.reopen_requested_at && lock.status === 'locked'"
                class="mt-4 flex flex-wrap items-center gap-2 text-xs font-semibold"
                aria-label="Reopen trail"
              >
                <li class="rounded-pill border border-sv-success-border bg-sv-success-bg px-2.5 py-1 text-sv-success-fg">
                  Reopen requested
                </li>
                <li
                  v-if="lock.exception_required"
                  class="rounded-pill border px-2.5 py-1"
                  :class="lock.reopen_approved_at ? 'border-sv-success-border bg-sv-success-bg text-sv-success-fg' : 'border-sv-warning-border bg-sv-warning-bg text-sv-warning-fg'"
                >
                  {{ lock.reopen_approved_at ? 'Owner approved' : 'Awaiting Merchant Administrator approval' }}
                </li>
                <li class="rounded-pill border border-sv-border bg-surface-alt px-2.5 py-1 text-text-muted">
                  Execute with fresh step-up
                </li>
              </ol>
            </section>
          </li>
        </ol>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Period lock rules"
        >
          <section class="sv-r-note sv-r-tone-teal">
            <h2 class="sv-r-note__title">
              What a lock does
            </h2>
            <p class="sv-r-note__body">
              Financial changes dated inside a locked period are refused. Reads, receipts, disputes and exports stay available.
            </p>
          </section>
          <section class="sv-r-note sv-r-tone-sun">
            <h2 class="sv-r-note__title">
              Reopening
            </h2>
            <p class="sv-r-note__body">
              Every reopen records a reason and needs a fresh step-up. An exceptional lock also needs a distinct Merchant Administrator approval.
            </p>
          </section>
        </aside>
      </div>
    </SvStateBoundary>

    <SvDialog
      :open="creating"
      title="Lock a financial period"
      description="A locked period blocks financial mutations dated inside it (423). Reads, receipts, disputes and exports remain available."
      @close="creating = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvTextInput
          id="lock-start"
          v-model="form.period_start"
          type="date"
          label="Period start"
        />
        <SvTextInput
          id="lock-end"
          v-model="form.period_end"
          type="date"
          label="Period end"
        />
        <label class="flex items-center gap-2 text-sm text-text">
          <input
            v-model="form.exception_required"
            type="checkbox"
            data-testid="lock-exception-required"
          >
          Require exceptional (Merchant Administrator) approval to reopen
        </label>
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="creating = false"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="period-lock-create-confirm"
          :loading="busy"
          :disabled="form.period_start === '' || form.period_end === ''"
          @click="submitCreate"
        >
          Lock period
        </SvButton>
      </div>
    </SvDialog>

    <SvDialog
      :open="reopening !== null"
      title="Request a period reopen"
      description="A reason is mandatory. Execution requires a fresh step-up; an exceptional lock also needs a distinct Merchant Administrator approval."
      @close="reopening = null"
    >
      <SvTextArea
        id="reopen-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="reopening = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="period-reopen-request-confirm"
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="submitReopenRequest"
        >
          Request reopen
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
