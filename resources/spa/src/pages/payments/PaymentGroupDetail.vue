<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { usePaymentStore, type PaymentRecordingGroupView } from '@/stores/paymentStore';
import { usePermissionStore } from '@/stores/permissionStore';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';

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
/** COR-UI08-UI12-VIS-001: whether this user has any decision open on this group (UX only). */
const canDecide = computed(() => (isPending.value && (canValidate.value || canReject.value)) || (isCorrectable.value && canCorrect.value));
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
  <!--
    COR-UI08-UI12-VIS-001 — a VERDICT DESK. The group reads as one statement: a fact strip (invoice,
    whole-group total, maker, server status), then every component in a ledger with its masked
    reference and amount, then any held duplicate references. The decision rail beside it carries
    the only whole-group controls (capability-gated; the server enforces maker/checker, locks,
    idempotency and step-up) and states what each decision does to receipts. No component-only
    validation and no manual receipt issue exist.
  -->
  <section
    class="sv-r-page"
    data-testid="finance-payment-validation-detail"
  >
    <SvPageHeader
      title="Payment validation detail"
      eyebrow="Pending validations / group detail"
      description="Review every component, then decide the server-defined group atomically. No component-only validation is available."
    />

    <SvStateBoundary
      :state="boundaryState"
      error-message="We couldn’t load this recording."
      empty-message="Recording not found."
    >
      <p
        v-if="receiptIssued"
        class="mb-4 rounded-field border border-sv-success-border bg-sv-success-bg px-4 py-3 text-sm font-semibold text-sv-success-fg"
        role="status"
        data-testid="receipt-issued"
      >
        Validated. One original receipt has been issued for this group.
      </p>

      <div class="sv-r-grid sv-r-split">
        <div class="sv-r-stack min-w-0">
          <dl class="sv-r-strip">
            <div>
              <dt>Invoice</dt>
              <dd>{{ group?.invoice?.invoice_number ?? '—' }}</dd>
            </div>
            <div>
              <dt>Group total</dt>
              <dd class="sv-r-figure-md">
                <SvMoney :formatted="group?.total?.formatted ?? null" />
              </dd>
            </div>
            <div>
              <dt>Recorded by</dt>
              <dd>{{ group?.maker?.name ?? '—' }}</dd>
            </div>
            <div>
              <dt>Status</dt>
              <dd class="capitalize">
                {{ group?.status?.replaceAll('_', ' ') }}
              </dd>
            </div>
          </dl>

          <section
            class="sv-r-ledger"
            style="--sv-r-ledger-cols: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) auto"
            aria-labelledby="payment-components-heading"
          >
            <div class="sv-r-ledger__head">
              <h2
                id="payment-components-heading"
                class="sv-r-title"
              >
                Components
              </h2>
              <span class="sv-r-chip">{{ (group?.components ?? []).length }} in this group · decided together</span>
            </div>
            <div
              class="sv-r-ledger__colhead"
              aria-hidden="true"
            >
              <span>Method</span><span>Reference</span><span class="text-right">Amount</span><span />
            </div>
            <ul aria-label="Payment components">
              <li
                v-for="component in group?.components ?? []"
                :key="component.id"
                class="sv-r-ledger__row text-sm"
              >
                <span class="font-semibold capitalize text-heading">{{ component.method.replaceAll('_', ' ') }}</span>
                <span class="sv-numeric text-text-muted">{{ component.reference_masked ?? 'No reference' }}</span>
                <span class="sv-numeric font-display font-extrabold text-heading md:text-right"><SvMoney :formatted="component.amount?.formatted ?? null" /></span>
                <span class="md:text-right">
                  <SvButton
                    v-if="isCorrectable && canCorrect"
                    variant="ghost"
                    data-testid="correct-reference-open"
                    @click="correcting = { id: component.id, reference: '' }"
                  >
                    Correct reference
                  </SvButton>
                </span>
              </li>
            </ul>
          </section>

          <!-- Held duplicate references awaiting override (Phase 18A) -->
          <section
            v-if="duplicates.length > 0"
            class="sv-r-panel sv-r-panel--tonal sv-r-tone-sun"
            data-testid="duplicate-review"
          >
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-sun">
              Held
            </p>
            <h2 class="sv-r-title mt-1">
              Duplicate references held for review
            </h2>
            <ul class="sv-r-queue mt-4">
              <li
                v-for="check in duplicates"
                :key="check.id"
                class="sv-r-queue__row sv-r-tone-sun"
              >
                <span class="sv-r-queue__label capitalize">
                  {{ check.method.replaceAll('_', ' ') }}
                  <span class="sv-r-queue__detail normal-case">Reference ends {{ check.reference_masked ?? '—' }}</span>
                </span>
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
              class="sv-r-muted mt-3"
            >
              Only Finance with the override capability can release a suspected duplicate.
            </p>
          </section>
        </div>

        <aside
          class="sv-r-stack-sm sv-r-sticky"
          aria-label="Group decision"
        >
          <section class="sv-r-panel">
            <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-teal">
              Checker decision
            </p>
            <h2 class="sv-r-title mt-1">
              Decide the whole group
            </h2>
            <!-- Finance checker whole-group decisions (no partial component validation) -->
            <div
              v-if="isPending"
              class="mt-4 flex flex-col gap-2"
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
              <p
                v-if="!canValidate && !canReject"
                class="sv-r-muted"
              >
                Your role can view this group but cannot decide it.
              </p>
            </div>
            <div
              v-else-if="isCorrectable && canCorrect"
              class="mt-4"
              data-testid="resubmit-actions"
            >
              <SvButton
                data-testid="resubmit-open"
                @click="openDecision('resubmit')"
              >
                Resubmit for validation
              </SvButton>
            </div>
            <p
              v-else
              class="sv-r-muted mt-3"
            >
              No decision is open for a group in this status.
            </p>
          </section>
          <!-- Decision consequences only for someone who can decide; a viewer sees no checker vocabulary. -->
          <section
            v-if="canDecide"
            class="sv-r-note sv-r-tone-teal"
          >
            <h2 class="sv-r-note__title">
              What each decision does
            </h2>
            <ul class="sv-r-note__body list-disc space-y-1 pl-4">
              <li>Validate issues exactly one original receipt for the group.</li>
              <li>Reject or request correction issues no receipt and needs a reason.</li>
              <li>The recording maker can never validate their own group.</li>
            </ul>
          </section>
        </aside>
      </div>
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
