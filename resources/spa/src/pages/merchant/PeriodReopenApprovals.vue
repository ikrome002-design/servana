<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import { SvIconLocked } from '@/design-system/icons';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { usePeriodLockStore, type PeriodLockView } from '@/stores/periodLockStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

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
  <!--
    COR-UI08-UI12-VIS-001 — a deliberately serious approval surface. Each exceptional reopen is a
    locked-period card on a clay spine: the period set large, its scope, Finance's recorded reason
    quoted, and the consequence of approving stated beside the one action. The rail restates the
    separation the server enforces: the approver must differ from the Finance requester, and the
    owner never creates locks or executes reopens.
  -->
  <section class="sv-r-page">
    <SvPageHeader
      title="Exceptional period-reopen approvals"
      eyebrow="Compensation & Approvals"
      description="You can approve an exceptional reopen requested by Finance. You cannot create locks or execute reopens — those are Finance actions."
    />

    <p
      v-if="actionError"
      class="mb-4 rounded-control bg-sv-error-bg px-4 py-3 text-sm text-sv-error-fg"
      role="alert"
    >
      {{ actionError }}
    </p>

    <div class="sv-r-grid sv-r-split">
      <SvStateBoundary
        :state="boundaryState"
        error-message="We couldn’t load approvals."
        empty-message="No exceptional reopens are awaiting your approval."
      >
        <ul class="flex flex-col gap-3">
          <li
            v-for="lock in pending"
            :key="lock.id"
          >
            <section
              class="sv-r-record sv-r-record--spine sv-r-tone-alert pl-6"
              data-testid="reopen-approval-row"
            >
              <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-4">
                  <span
                    aria-hidden="true"
                    class="sv-r-icon sv-r-icon--lg sv-r-tone-alert"
                  >
                    <SvIconLocked />
                  </span>
                  <div class="min-w-0">
                    <p class="sv-r-eyebrow">
                      Locked period
                    </p>
                    <p class="sv-r-title-lg mt-1">
                      {{ lock.period_start }} → {{ lock.period_end }}
                    </p>
                    <p class="mt-2">
                      <span class="sv-r-chip">{{ lock.scope === 'merchant' ? 'Merchant-wide' : `Branch: ${lock.branch?.name ?? '—'}` }}</span>
                    </p>
                  </div>
                </div>
                <SvButton
                  data-testid="reopen-approve"
                  :loading="busy"
                  @click="approve(lock)"
                >
                  Approve reopen
                </SvButton>
              </div>
              <blockquote
                v-if="lock.reopen_reason"
                class="mt-4 rounded-card border-l-4 border-sv-border-strong bg-sv-surface-subtle px-4 py-3 text-sm text-text"
              >
                Reason: {{ lock.reopen_reason }}
              </blockquote>
              <p class="sv-r-muted mt-3">
                Approving lets Finance execute this reopen. The approval is recorded on the append-only audit trail.
              </p>
            </section>
          </li>
        </ul>
      </SvStateBoundary>

      <aside
        class="sv-r-stack-sm sv-r-sticky"
        aria-label="Approval separation"
      >
        <div class="sv-r-note sv-r-tone-alert">
          <p class="sv-r-note__title">
            Separation of duties
          </p>
          <ul class="sv-r-note__body mt-2 list-disc space-y-1 pl-5">
            <li>Finance requests the reopen; you approve it; Finance executes it.</li>
            <li>The approver must be a different person from the Finance requester — the server refuses otherwise.</li>
            <li>You cannot create period locks or execute a reopen here.</li>
          </ul>
        </div>
      </aside>
    </div>
  </section>
</template>