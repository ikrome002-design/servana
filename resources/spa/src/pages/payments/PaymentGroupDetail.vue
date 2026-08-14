<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { usePaymentStore, type PaymentRecordingGroupView } from '@/stores/paymentStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvMoney from '@/components/ui/SvMoney.vue';

/**
 * Finance payment-recording detail (Plan §41–§42; Phase 18A + 18B). Shows the group and
 * its masked components. Phase 18B adds the Finance checker workflow — WHOLE-group
 * validate (issues one original receipt), reject and request-correction (mandatory
 * reason, NO receipt), per-component reference correction, and resubmit — plus the
 * Phase-18A held-duplicate override. Every control is capability-gated (UX only; the
 * server enforces maker/checker, period locks, idempotency and step-up). There is NO
 * partial-component validation and NO manual receipt-issue.
 */
interface DuplicateCheck {
  id: string;
  method: string;
  reference_masked: string | null;
}

const route = useRoute();
const store = usePaymentStore();
const permissions = usePermissionStore();

const group = ref<(PaymentRecordingGroupView & { duplicate_checks?: DuplicateCheck[] }) | null>(null);
const loadError = ref(false);
const overriding = ref<DuplicateCheck | null>(null);
const reason = ref('');
const busy = ref(false);
const actionError = ref<string | null>(null);
const receiptIssued = ref(false);
type Decision = 'validate' | 'reject' | 'request-correction' | 'resubmit';
const deciding = ref<Decision | null>(null);
const correcting = ref<{ id: string; reference: string } | null>(null);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (group.value === null && !loadError.value) return 'loading';
  if (loadError.value) return 'error';
  return 'success';
});

const canOverride = computed(() => permissions.can('customer_payment.duplicate_override'));
const canValidate = computed(() => permissions.can('customer_payment.validate'));
const canReject = computed(() => permissions.can('customer_payment.reject'));
const canCorrect = computed(() => permissions.can('customer_payment.reference_correct'));
const duplicates = computed<DuplicateCheck[]>(() => group.value?.duplicate_checks ?? []);
const isPending = computed(() => group.value?.status === 'pending_validation');
const isCorrectable = computed(() => group.value?.status === 'correction_required');
const needsReason = computed(() => deciding.value === 'reject' || deciding.value === 'request-correction');

const decisionTitle: Record<Decision, string> = {
  validate: 'Validate this payment group',
  reject: 'Reject this payment group',
  'request-correction': 'Request correction',
  resubmit: 'Resubmit for validation',
};

async function load(): Promise<void> {
  try {
    group.value = (await store.fetchGroup(String(route.params.groupUlid ?? route.params.id))) as PaymentRecordingGroupView & {
      duplicate_checks?: DuplicateCheck[];
    };
  } catch {
    loadError.value = true;
  }
}

function openDecision(decision: Decision): void {
  actionError.value = null;
  reason.value = '';
  receiptIssued.value = false;
  deciding.value = decision;
}

async function confirmDecision(): Promise<void> {
  if (group.value === null || deciding.value === null) return;
  if (needsReason.value && reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    const id = group.value.id;
    if (deciding.value === 'validate') {
      await store.validateGroup(id);
      receiptIssued.value = true;
    } else if (deciding.value === 'reject') {
      await store.rejectGroup(id, reason.value.trim());
    } else if (deciding.value === 'request-correction') {
      await store.requestCorrection(id, reason.value.trim());
    } else if (deciding.value === 'resubmit') {
      await store.resubmitGroup(id);
    }
    deciding.value = null;
    reason.value = '';
    await load();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The action could not be completed.';
  } finally {
    busy.value = false;
  }
}

async function confirmCorrectReference(): Promise<void> {
  if (correcting.value === null || correcting.value.reference.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.correctReference(correcting.value.id, correcting.value.reference.trim());
    correcting.value = null;
    await load();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The reference could not be corrected.';
  } finally {
    busy.value = false;
  }
}

async function confirmOverride(): Promise<void> {
  if (overriding.value === null || reason.value.trim() === '') return;
  busy.value = true;
  actionError.value = null;
  try {
    await store.overrideDuplicate(overriding.value.id, reason.value.trim());
    overriding.value = null;
    reason.value = '';
    await load();
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { message?: string } } } };
    actionError.value = err.response?.data?.error?.message ?? 'The override could not be completed.';
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="mx-auto max-w-6xl" data-testid="finance-payment-validation-detail">
    <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">Pending validations / group detail</p>
    <h1 class="mt-1 font-display text-2xl font-bold text-heading">Payment validation detail</h1>
    <p class="mt-1 text-sm text-text-muted">Review every component, then decide the server-defined group atomically. No component-only validation is available.</p>

    <SvStateBoundary
      class="mt-6"
      :state="boundaryState"
      error-message="We couldn’t load this recording."
      empty-message="Recording not found."
    >
      <p
        v-if="receiptIssued"
        class="mb-4 rounded-lg bg-surface-alt px-3 py-2 text-sm text-heading"
        role="status"
        data-testid="receipt-issued"
      >
        Validated. One original receipt has been issued for this group.
      </p>

      <SvCard
        as="section"
        padding="md"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-xs text-text-muted">
              Invoice {{ group?.invoice?.invoice_number ?? '—' }}
            </p>
            <p class="font-display text-lg font-semibold text-heading">
              <SvMoney :formatted="group?.total?.formatted ?? null" />
            </p>
            <p class="mt-0.5 text-sm text-text-muted">
              Recorded by {{ group?.maker?.name ?? '—' }} · Status: {{ group?.status }}
            </p>
          </div>

          <!-- Finance checker whole-group decisions (no partial component validation) -->
          <div
            v-if="isPending"
            class="flex flex-wrap gap-2"
            data-testid="validation-actions"
          >
            <SvButton
              v-if="canValidate"
              data-testid="validate-open"
              @click="openDecision('validate')"
            >
              Validate group
            </SvButton>
            <SvButton
              v-if="canReject"
              variant="secondary"
              data-testid="reject-open"
              @click="openDecision('reject')"
            >
              Reject
            </SvButton>
            <SvButton
              v-if="canReject"
              variant="ghost"
              data-testid="request-correction-open"
              @click="openDecision('request-correction')"
            >
              Request correction
            </SvButton>
          </div>
          <div
            v-else-if="isCorrectable && canCorrect"
            data-testid="resubmit-actions"
          >
            <SvButton
              data-testid="resubmit-open"
              @click="openDecision('resubmit')"
            >
              Resubmit for validation
            </SvButton>
          </div>
        </div>

        <ul
          class="mt-4 flex flex-col gap-2"
          aria-label="Payment components"
        >
          <li
            v-for="component in group?.components ?? []"
            :key="component.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-alt px-3 py-2 text-sm"
          >
            <span class="font-semibold text-heading">{{ component.method }}</span>
            <span class="text-text-muted">{{ component.reference_masked ?? 'No reference' }}</span>
            <span class="font-semibold text-heading"><SvMoney :formatted="component.amount?.formatted ?? null" /></span>
            <SvButton
              v-if="isCorrectable && canCorrect"
              variant="ghost"
              data-testid="correct-reference-open"
              @click="correcting = { id: component.id, reference: '' }"
            >
              Correct reference
            </SvButton>
          </li>
        </ul>
      </SvCard>

      <!-- Held duplicate references awaiting override (Phase 18A) -->
      <SvCard
        v-if="duplicates.length > 0"
        as="section"
        padding="md"
        class="mt-4 border-l-4 border-l-sv-warning-border"
        data-testid="duplicate-review"
      >
        <h2 class="font-display text-base font-semibold text-heading">
          Duplicate references held for review
        </h2>
        <ul class="mt-2 flex flex-col gap-2">
          <li
            v-for="check in duplicates"
            :key="check.id"
            class="flex flex-wrap items-center justify-between gap-2 text-sm"
          >
            <span>{{ check.method }} · ends {{ check.reference_masked ?? '—' }}</span>
            <SvButton
              v-if="canOverride"
              variant="secondary"
              data-testid="override-open"
              @click="overriding = check"
            >
              Override
            </SvButton>
          </li>
        </ul>
        <p
          v-if="!canOverride"
          class="mt-2 text-sm text-text-muted"
        >
          Only Finance with the override capability can release a suspected duplicate.
        </p>
      </SvCard>
    </SvStateBoundary>

    <!-- Whole-group decision confirmation -->
    <SvDialog
      :open="deciding !== null"
      :title="deciding ? decisionTitle[deciding] : ''"
      description="This decision applies to the whole group. Rejection or a correction request issues NO receipt; validation issues exactly one original receipt."
      @close="deciding = null"
    >
      <SvTextArea
        v-if="needsReason"
        id="decision-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-sv-error-fg"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="deciding = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="decision-confirm"
          :loading="busy"
          :disabled="needsReason && reason.trim() === ''"
          @click="confirmDecision"
        >
          Confirm
        </SvButton>
      </div>
    </SvDialog>

    <!-- Per-component reference correction -->
    <SvDialog
      :open="correcting !== null"
      title="Correct payment reference"
      description="Replace the recorded reference on this component before resubmitting for validation."
      @close="correcting = null"
    >
      <SvTextInput
        v-if="correcting"
        id="corrected-reference"
        v-model="correcting.reference"
        label="Corrected reference"
        class="mt-2"
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-sv-error-fg"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="correcting = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="correct-reference-confirm"
          :loading="busy"
          :disabled="!correcting || correcting.reference.trim() === ''"
          @click="confirmCorrectReference"
        >
          Save reference
        </SvButton>
      </div>
    </SvDialog>

    <!-- Held-duplicate override (Phase 18A) -->
    <SvDialog
      :open="overriding !== null"
      title="Override duplicate reference"
      description="This releases the held payment for validation. A reason is required and a fresh step-up may be requested. The original reference is never edited."
      @close="overriding = null"
    >
      <SvTextArea
        id="override-reason"
        v-model="reason"
        label="Reason"
        class="mt-2"
      />
      <p
        v-if="actionError"
        class="mt-2 text-sm text-sv-error-fg"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-2">
        <SvButton
          variant="ghost"
          @click="overriding = null"
        >
          Cancel
        </SvButton>
        <SvButton
          data-testid="override-confirm"
          :loading="busy"
          :disabled="reason.trim() === ''"
          @click="confirmOverride"
        >
          Override and release
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
