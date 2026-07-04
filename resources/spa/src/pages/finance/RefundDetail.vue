<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvModal from '@/components/ui/SvModal.vue';
import { useRefundStore } from '@/stores/refundStore';
import { usePermissionStore } from '@/stores/permissionStore';

/**
 * Refund detail (Plan §44; Phase 18B). Shows the refunded component, masked external
 * reference, and maker/checker state. A DISTINCT Finance membership approves + finalizes
 * (both require a fresh MFA step-up, enforced by the server). Finalization is
 * IRREVERSIBLE and reduces the invoice recognised balance; the warning is explicit. A
 * period lock returns 423 and is surfaced verbatim.
 */
const route = useRoute();
const store = useRefundStore();
const permissions = usePermissionStore();

const busy = ref(false);
const actionError = ref<string | null>(null);
type Verb = 'approve' | 'reject' | 'finalize';
const confirming = ref<Verb | null>(null);

const canApprove = computed(() => permissions.can('refund.approve'));
const canFinalize = computed(() => permissions.can('refund.finalize'));
const status = computed(() => store.current?.status);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading && store.current === null) return 'loading';
  if (store.error) return 'error';
  if (store.current === null) return 'empty';
  return 'success';
});

const verbTitle: Record<Verb, string> = {
  approve: 'Approve refund',
  reject: 'Reject refund',
  finalize: 'Finalize refund (irreversible)',
};
const verbDescription: Record<Verb, string> = {
  approve: 'Approval requires a fresh step-up. The approver must differ from the requester.',
  reject: 'Rejection restores the prior paid state; no funds are moved.',
  finalize: 'Finalization is IRREVERSIBLE. It reduces the recognised invoice balance and records the reversal. A fresh step-up is required.',
};

async function confirm(): Promise<void> {
  if (confirming.value === null || store.current === null) return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.decide(store.current.id, confirming.value);
    confirming.value = null;
  } catch (e: unknown) {
    const err = e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The action could not be completed.';
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void store.fetchRefund(String(route.params.id));
});
</script>

<template>
  <section class="p-4 md:p-6">
    <h1 class="font-display text-2xl font-bold text-heading">
      Refund
    </h1>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this refund."
      empty-message="Refund not found."
    >
      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-display text-lg font-semibold text-heading">
              {{ store.current?.amount.formatted }} · {{ store.current?.method }}
            </p>
            <p class="text-sm text-text-muted">
              Invoice {{ store.current?.invoice?.invoice_number ?? '—' }} · component {{ store.current?.payment_record?.method ?? '—' }}
            </p>
            <p class="text-sm text-text-muted">
              External reference: {{ store.current?.reference_masked ?? 'None (cash)' }}
            </p>
            <p class="mt-1 text-sm">
              Status: <span class="font-semibold text-heading">{{ status }}</span>
            </p>
            <p class="mt-1 text-sm text-text-muted">
              {{ store.current?.reason }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <SvButton
              v-if="canApprove && status === 'requested'"
              data-testid="refund-approve"
              @click="confirming = 'approve'"
            >
              Approve
            </SvButton>
            <SvButton
              v-if="canApprove && status === 'requested'"
              variant="secondary"
              data-testid="refund-reject"
              @click="confirming = 'reject'"
            >
              Reject
            </SvButton>
            <SvButton
              v-if="canFinalize && status === 'approved'"
              data-testid="refund-finalize"
              @click="confirming = 'finalize'"
            >
              Finalize
            </SvButton>
          </div>
        </div>

        <p
          v-if="actionError"
          class="mt-3 text-sm text-[color:var(--color-danger,#dc2626)]"
          role="alert"
        >
          {{ actionError }}
        </p>
      </SvCard>
    </SvStateBoundary>

    <SvModal
      :open="confirming !== null"
      :title="confirming ? verbTitle[confirming] : ''"
      :description="confirming ? verbDescription[confirming] : ''"
      @close="confirming = null"
    >
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="confirming = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="refund-confirm"
          :variant="confirming === 'finalize' ? 'primary' : 'primary'"
          :loading="busy"
          @click="confirm"
        >
          Confirm
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
