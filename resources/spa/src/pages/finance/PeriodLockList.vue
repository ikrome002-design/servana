<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { usePeriodLockStore, type PeriodLockView } from '@/stores/periodLockStore';
import { usePermissionStore } from '@/stores/permissionStore';

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
  <section class="p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="font-display text-2xl font-bold text-heading">
        Financial periods
      </h1>
      <SvButton
        v-if="canCreate"
        data-testid="period-lock-create-open"
        @click="creating = true"
      >
        Lock a period
      </SvButton>
    </div>

    <p
      v-if="actionError"
      class="mt-3 text-sm text-[color:var(--color-danger,#dc2626)]"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load financial periods."
      empty-message="No period locks yet."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="lock in store.locks"
          :key="lock.id"
        >
          <SvCard
            as="section"
            padding="md"
            data-testid="period-lock-row"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ lock.period_start }} → {{ lock.period_end }}
                </p>
                <p class="text-sm text-text-muted">
                  {{ lock.scope === 'merchant' ? 'Merchant-wide' : `Branch: ${lock.branch?.name ?? '—'}` }} · {{ lock.status }}
                  <span v-if="lock.exception_required"> · exceptional reopen</span>
                </p>
                <p
                  v-if="lock.reopen_requested_at && lock.status === 'locked'"
                  class="mt-1 text-xs text-text-muted"
                >
                  Reopen requested{{ lock.exception_required && !lock.reopen_approved_at ? ' — awaiting Merchant Administrator approval' : '' }}
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
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <SvModal
      :open="creating"
      title="Lock a financial period"
      description="A locked period blocks financial mutations dated inside it (423). Reads, receipts, disputes and exports remain available."
      @close="creating = false"
    >
      <div class="mt-2 flex flex-col gap-3">
        <SvInput
          id="lock-start"
          v-model="form.period_start"
          type="date"
          label="Period start"
        />
        <SvInput
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
    </SvModal>

    <SvModal
      :open="reopening !== null"
      title="Request a period reopen"
      description="A reason is mandatory. Execution requires a fresh step-up; an exceptional lock also needs a distinct Merchant Administrator approval."
      @close="reopening = null"
    >
      <SvTextarea
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
    </SvModal>
  </section>
</template>
