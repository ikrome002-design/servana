<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCashUpStore } from '@/stores/cashUpStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvMoney from '@/components/ui/SvMoney.vue';

/**
 * Finance cash-up detail + review (Plan §45; Phase 18B). Finance (checker) approves,
 * rejects, or requests correction of a SUBMITTED cash-up, and locks an approved one. The
 * server enforces maker/checker — the branch manager who submitted can never approve.
 * Expected totals are server-derived; the counted snapshot is never overwritten here.
 */
const route = useRoute();
const store = useCashUpStore();
const permissions = usePermissionStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
const reason = ref('');
type Verb = 'approve' | 'lock' | 'reject' | 'request-correction';
const deciding = ref<Verb | null>(null);

const canApprove = computed(() => permissions.can('cash_up.approve'));
const canReject = computed(() => permissions.can('cash_up.reject'));
const canRequestCorrection = computed(() => permissions.can('cash_up.request_correction'));
const status = computed(() => store.current?.status);
const needsReason = computed(() => deciding.value === 'reject' || deciding.value === 'request-correction');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.current === null) return 'loading';
  if (store.error) return 'error';
  if (store.current === null) return 'empty';
  return 'success';
});

const verbTitle: Record<Verb, string> = {
  approve: 'Approve cash-up',
  lock: 'Lock cash-up',
  reject: 'Reject cash-up',
  'request-correction': 'Request correction',
};

async function confirm(): Promise<void> {
  if (deciding.value === null || store.current?.id == null) return;
  if (needsReason.value && reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    if (deciding.value === 'approve') await store.action(store.current.id, 'approve');
    else if (deciding.value === 'lock') await store.action(store.current.id, 'lock');
    else await store.decide(store.current.id, deciding.value, reason.value.trim());
    deciding.value = null;
    reason.value = '';
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The action could not be completed.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchCashUp(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Cash-up review
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this cash-up."
      empty-message="Cash-up not found."
    >
      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-display text-lg font-semibold text-heading">
              {{ store.current?.business_date }}
            </p>
            <p class="text-sm text-text-muted">
              Status: {{ status }}
            </p>
            <p
              v-if="store.current?.review_note"
              class="mt-1 text-sm text-text-muted"
            >
              Note: {{ store.current?.review_note }}
            </p>
          </div>
          <div
            class="flex flex-wrap gap-2"
            data-testid="cash-up-review-actions"
          >
            <SvButton
              v-if="canApprove && status === 'submitted'"
              data-testid="cash-up-approve"
              @click="deciding = 'approve'"
            >
              Approve
            </SvButton>
            <SvButton
              v-if="canReject && status === 'submitted'"
              variant="secondary"
              data-testid="cash-up-reject"
              @click="deciding = 'reject'"
            >
              Reject
            </SvButton>
            <SvButton
              v-if="canRequestCorrection && status === 'submitted'"
              variant="ghost"
              data-testid="cash-up-request-correction"
              @click="deciding = 'request-correction'"
            >
              Request correction
            </SvButton>
            <SvButton
              v-if="canApprove && status === 'approved'"
              data-testid="cash-up-lock"
              @click="deciding = 'lock'"
            >
              Lock
            </SvButton>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-lg bg-surface-alt px-3 py-2">
            <p class="text-xs text-text-muted">
              Expected
            </p>
            <p class="font-semibold text-heading">
              <SvMoney
                :formatted="store.current?.expected?.formatted ?? null"
                :minor-units="store.current?.expected_minor ?? null"
              />
            </p>
          </div>
          <div class="rounded-lg bg-surface-alt px-3 py-2">
            <p class="text-xs text-text-muted">
              Counted
            </p>
            <p class="font-semibold text-heading">
              <SvMoney
                :formatted="store.current?.counted?.formatted ?? null"
                :minor-units="store.current?.counted_minor ?? null"
              />
            </p>
          </div>
          <div class="rounded-lg bg-surface-alt px-3 py-2">
            <p class="text-xs text-text-muted">
              Variance
            </p>
            <p class="font-semibold text-heading">
              <SvMoney
                :formatted="store.current?.variance?.formatted ?? null"
                :minor-units="store.current?.variance_minor ?? null"
                signed
              />
            </p>
          </div>
        </div>

        <!--
          A horizontally scrollable region must be reachable by keyboard (WCAG 2.1.1): the table is
          wider than a mobile viewport, so a pointer user can scroll it and a keyboard user could
          not. Same contract SvDataTable applies to its own scroll container.
        -->
        <div
          class="mt-4 overflow-x-auto"
          tabindex="0"
          role="region"
          aria-label="Cash-up denomination breakdown (scrollable)"
        >
          <table class="w-full min-w-[24rem] text-sm">
            <thead>
              <tr class="text-left text-text-muted">
                <th class="py-1">
                  Method
                </th>
                <th class="py-1 text-right">
                  Expected
                </th>
                <th class="py-1 text-right">
                  Counted
                </th>
                <th class="py-1 text-right">
                  Variance
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="line in store.current?.lines ?? []"
                :key="line.method"
                class="border-t border-sv-border"
              >
                <td class="py-1 font-semibold text-heading">
                  {{ line.method }}
                </td>
                <td class="py-1 text-right">
                  {{ line.expected_minor }}
                </td>
                <td class="py-1 text-right">
                  {{ line.counted_minor }}
                </td>
                <td class="py-1 text-right">
                  {{ line.variance_minor }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p
          v-if="actionError"
          class="mt-3 text-sm text-sv-error-fg"
          role="alert"
        >
          {{ actionError }}
        </p>
      </SvCard>
    </SvStateBoundary>

    <SvDialog
      :open="deciding !== null"
      :title="deciding ? verbTitle[deciding] : ''"
      description="Approving requires that you are not the branch manager who submitted this cash-up. Reject / request-correction need a reason."
      @close="deciding = null"
    >
      <SvTextArea
        v-if="needsReason"
        id="cash-up-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="deciding = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="cash-up-decision-confirm"
          :loading="busy"
          :disabled="needsReason && reason.trim() === ''"
          @click="confirm"
        >
          Confirm
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
