<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePeriodLockStore, type PeriodLockView } from '@/stores/periodLockStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Merchant Administrator exceptional period-reopen approvals (Plan §46; ADR-0007;
 * Phase 18B). The Merchant Administrator holds ONLY exceptional-reopen approval
 * (`merchant.period_reopen.approve_exception`) — NO routine locking or reopen execution
 * (those are Finance). The approver must differ from the Finance requester, enforced by
 * the server. This screen lists exceptional locks with a pending reopen request.
 */
const store = usePeriodLockStore();
const permissions = usePermissionStore();

const busy = ref(false);
const actionError = ref<string | null>(null);

const canApprove = computed(() => permissions.can('merchant.period_reopen.approve_exception'));

const pending = computed<PeriodLockView[]>(() =>
  store.locks.filter(
    (l) => l.status === 'locked' && l.exception_required && l.reopen_requested_at !== null && l.reopen_approved_at === null,
  ),
);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (!canApprove.value) return 'empty';
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (pending.value.length === 0) return 'empty';
  return 'success';
});

async function approve(lock: PeriodLockView): Promise<void> {
  busy.value = true;
  actionError.value = null;
  try {
    await store.approveException(lock.id);
    await store.fetchLocks();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The approval could not be recorded.';
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
    <h1 class="font-display text-2xl font-bold text-heading">
      Exceptional period-reopen approvals
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      You can approve an exceptional reopen requested by Finance. You cannot create locks
      or execute reopens — those are Finance actions.
    </p>

    <p
      v-if="actionError"
      class="mt-3 text-sm text-sv-error-fg"
      role="alert"
    >
      {{ actionError }}
    </p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load approvals."
      empty-message="No exceptional reopens are awaiting your approval."
    >
      <ul class="flex flex-col gap-2">
        <li
          v-for="lock in pending"
          :key="lock.id"
        >
          <SvCard
            as="section"
            padding="md"
            data-testid="reopen-approval-row"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-display font-semibold text-heading">
                  {{ lock.period_start }} → {{ lock.period_end }}
                </p>
                <p class="text-sm text-text-muted">
                  {{ lock.scope === 'merchant' ? 'Merchant-wide' : `Branch: ${lock.branch?.name ?? '—'}` }}
                </p>
                <p
                  v-if="lock.reopen_reason"
                  class="mt-1 text-sm text-text-muted"
                >
                  Reason: {{ lock.reopen_reason }}
                </p>
              </div>
              <SvButton
                data-testid="reopen-approve"
                :loading="busy"
                @click="approve(lock)"
              >
                Approve reopen
              </SvButton>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>
  </section>
</template>
