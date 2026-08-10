<script setup lang="ts">
/**
 * Merchant detail and governance — the shared lifecycle concern (Phase UI-08; contract page
 * §5.4.12). Extracted in Increment 9D from the consolidated `RegistrationMonitoring.vue`, which
 * nested it inside the directory tab and so gave it no address of its own.
 *
 * It is ONE component used by two callers — the canonical `/merchants/:merchantUlid` page and, until
 * Increment 7B retires it, the legacy consolidated screen. That is the point of the extraction: the
 * suspend / reactivate / deactivate handlers, the mandatory-reason dialog, the step-up error
 * translation and the capability check exist once, so the two surfaces cannot drift into two
 * different lifecycle behaviours.
 *
 * What it deliberately does NOT do:
 *
 *  - it never decides authorization. `canGovern` reads the server-derived `can` map AND the
 *    permission map, and the API re-checks both plus a FRESH `merchant_governance` step-up;
 *  - it never models the lifecycle. An illegal transition is refused by the server state machine
 *    and surfaced verbatim as `invalid_state_transition`; there is no client-side state machine to
 *    disagree with it;
 *  - it never touches billing. Operational status and billing status are rendered as two separate,
 *    prominently labelled cards, and reactivation is stated as NOT a billing-recovery path — the
 *    backend enforces the same separation (`SuspendMerchant` mutates `merchants.status` only);
 *  - it offers no impersonation, no merchant setup completion, no branch/staff creation, no invoice,
 *    payment, receipt or queue action.
 */
import axios from 'axios';
import { computed, nextTick, ref } from 'vue';
import SvAlert from '@/components/ui/SvAlert.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePlatformMerchantStore, type PlatformMerchant } from '@/stores/platformMerchantStore';
import {
  billingLabel,
  billingTone,
  operationalLabel,
  operationalTone,
} from '@/components/platform/merchants/merchantStatus';

type GovernanceAction = 'suspend' | 'reactivate' | 'deactivate';

const props = defineProps<{
  merchant: PlatformMerchant;
  /** `h2` on the dedicated page, where `SvPageHeader` already owns the `h1`. */
  headingLevel?: 'h2' | 'h3';
}>();

const store = usePlatformMerchantStore();
const notifications = useNotificationStore();
const { can } = useCan();

const reason = ref('');
const pendingAction = ref<GovernanceAction | null>(null);
const submitting = ref(false);
const actionError = ref<string | null>(null);
let triggerEl: HTMLElement | null = null;

const heading = computed(() => props.headingLevel ?? 'h2');

const modalTitle = computed(() => {
  switch (pendingAction.value) {
    case 'suspend': return 'Suspend merchant';
    case 'reactivate': return 'Reactivate merchant';
    case 'deactivate': return 'Deactivate merchant';
    default: return '';
  }
});

/**
 * The impact preview the contract requires. Every line is a fact about what the SERVER does:
 * `EnsureMerchantActive` refuses a suspended or deactivated merchant's operational routes with
 * `merchant_suspended` on the next request, and none of the three actions writes
 * `merchants.billing_status`. It does not claim session revocation, because the shipped actions do
 * not revoke sessions — an impact preview that overstates is worse than none.
 */
const IMPACT: Record<GovernanceAction, string[]> = {
  suspend: [
    'Everyone signed in to this merchant is refused on their next operational request.',
    'Billing status is not changed, and an outstanding balance stays outstanding.',
    'Records, history and audit evidence are preserved.',
  ],
  reactivate: [
    'Operational access is restored for this merchant’s users.',
    'This is not a billing recovery: a billing suspension is cleared only by the billing lifecycle.',
    'The previous suspension stays in the audit record.',
  ],
  deactivate: [
    'Operational access ends for this merchant’s users.',
    'Billing status is not changed by this action.',
    'Records, history and audit evidence are preserved — nothing is deleted.',
  ],
};

const impactLines = computed(() => (pendingAction.value === null ? [] : IMPACT[pendingAction.value]));

function openGovernance(action: GovernanceAction, event: MouseEvent): void {
  triggerEl = event.currentTarget as HTMLElement;
  pendingAction.value = action;
  reason.value = '';
  actionError.value = null;
}

function closeModal(): void {
  pendingAction.value = null;
  // Restore focus to the control that opened the dialog (accessibility).
  void nextTick(() => triggerEl?.focus());
}

const confirmDisabled = computed(() => submitting.value || reason.value.trim().length < 3);

async function confirm(): Promise<void> {
  if (confirmDisabled.value || pendingAction.value === null) return;
  submitting.value = true;
  actionError.value = null;
  const action = pendingAction.value;
  const id = props.merchant.id;
  try {
    if (action === 'suspend') await store.suspend(id, reason.value.trim());
    else if (action === 'reactivate') await store.reactivate(id, reason.value.trim());
    else await store.deactivate(id, reason.value.trim());
    notifications.addToast({ type: 'success', message: `Merchant ${action}d.` });
    closeModal();
  } catch (err) {
    actionError.value = resolveError(err);
  } finally {
    submitting.value = false;
  }
}

function resolveError(err: unknown): string {
  if (axios.isAxiosError(err) && err.apiError) {
    if (err.apiError.code === 'mfa_challenge_required' || err.apiError.code === 'mfa_enrollment_required') {
      return 'A fresh security step-up is required. Please re-verify your identity and try again.';
    }
    if (err.apiError.code === 'invalid_state_transition') {
      return 'That change is not allowed from the merchant’s current status.';
    }
    return err.apiError.message ?? 'The governance action could not be completed (a fresh step-up may be required).';
  }
  return 'Something went wrong.';
}

function canGovern(m: PlatformMerchant, action: GovernanceAction): boolean {
  return can(`platform.merchant.${action}`) && Boolean(m.can[action]);
}
</script>

<template>
  <!-- `SvCard` sets its own `data-testid`, so the panel's identity lives on its own wrapper. -->
  <div data-testid="merchant-governance-panel">
    <SvCard>
      <component
        :is="heading"
        class="font-display text-lg font-bold text-heading"
        data-testid="merchant-detail-name"
      >
        {{ merchant.name }}
      </component>

      <!--
        Operational and billing status are two SEPARATE labelled cards, not two rows of one status
        block. Conflating them is what makes an operator believe a payment reopened a merchant that
        was suspended for policy reasons.
      -->
      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div
          class="rounded-control border border-border p-4"
          data-testid="operational-status-card"
        >
          <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Operational status
          </p>
          <p
            class="mt-2 font-semibold text-heading"
            data-testid="operational-status"
          >
            {{ operationalLabel(merchant.operational_status) }}
          </p>
          <SvStatusBadge
            class="mt-2"
            :label="operationalLabel(merchant.operational_status)"
            :tone="operationalTone(merchant.operational_status)"
            size="sm"
            sr-prefix="Operational status:"
          />
          <p class="mt-2 text-xs text-text-muted">
            Set by platform governance.
          </p>
        </div>

        <div
          class="rounded-control border border-border p-4"
          data-testid="billing-status-card"
        >
          <p class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Billing status
          </p>
          <p
            class="mt-2 font-semibold text-heading"
            data-testid="detail-billing-status"
          >
            {{ billingLabel(merchant.billing_status) }}
          </p>
          <SvStatusBadge
            class="mt-2"
            :label="billingLabel(merchant.billing_status)"
            :tone="billingTone(merchant.billing_status)"
            size="sm"
            sr-prefix="Billing status:"
          />
          <p class="mt-2 text-xs text-text-muted">
            Set by the billing lifecycle. Governance actions here never change it.
          </p>
        </div>
      </div>

      <dl class="mt-4 grid gap-3 sm:grid-cols-2">
        <div v-if="merchant.billing_status_reason">
          <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Billing status reason
          </dt>
          <dd class="mt-1 text-text">
            {{ merchant.billing_status_reason }}
          </dd>
        </div>
        <div v-if="merchant.suspension_reason">
          <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Last governance reason
          </dt>
          <dd class="mt-1 text-text">
            {{ merchant.suspension_reason }}
          </dd>
        </div>
        <div v-if="merchant.registered_at">
          <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Registered
          </dt>
          <dd class="mt-1 text-text">
            {{ merchant.registered_at.slice(0, 10) }}
          </dd>
        </div>
        <div v-if="merchant.setup_completed_at">
          <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Setup completed
          </dt>
          <dd class="mt-1 text-text">
            {{ merchant.setup_completed_at.slice(0, 10) }}
          </dd>
        </div>
      </dl>

      <p
        v-if="actionError && pendingAction === null"
        class="mt-4 text-sm text-error"
        role="alert"
      >
        {{ actionError }}
      </p>

      <div class="mt-6 flex flex-wrap gap-3">
        <SvButton
          v-if="canGovern(merchant, 'suspend')"
          variant="destructive"
          data-testid="action-suspend"
          @click="openGovernance('suspend', $event)"
        >
          Suspend
        </SvButton>
        <SvButton
          v-if="canGovern(merchant, 'reactivate')"
          data-testid="action-reactivate"
          @click="openGovernance('reactivate', $event)"
        >
          Reactivate
        </SvButton>
        <SvButton
          v-if="canGovern(merchant, 'deactivate')"
          variant="destructive"
          data-testid="action-deactivate"
          @click="openGovernance('deactivate', $event)"
        >
          Deactivate
        </SvButton>
      </div>

      <p
        class="mt-4 text-xs text-text-muted"
        data-testid="governance-preservation-notice"
      >
        Governance actions change operational status only. Nothing is deleted: records, invoices and
        the append-only audit trail are preserved, and a billing suspension is cleared by the billing
        lifecycle rather than by reactivation here.
      </p>

      <!-- Governance confirmation: mandatory reason + impact preview -->
      <SvDialog
        :open="pendingAction !== null"
        :title="modalTitle"
        description="This changes the merchant’s operational status only — it never affects billing. A reason is required and a fresh security step-up may be requested."
        @close="closeModal"
      >
        <form
          class="flex flex-col gap-4"
          novalidate
          @submit.prevent="confirm"
        >
          <SvAlert
            severity="warning"
            title="What this will do"
            data-testid="governance-impact-preview"
          >
            <ul class="list-disc space-y-1 pl-5">
              <li
                v-for="line in impactLines"
                :key="line"
              >
                {{ line }}
              </li>
            </ul>
          </SvAlert>

          <SvTextArea
            id="governance-reason"
            label="Reason"
            :model-value="reason"
            placeholder="Why is this action being taken?"
            required
            @update:model-value="reason = $event"
          />
          <p
            v-if="actionError"
            class="text-sm text-error"
            role="alert"
          >
            {{ actionError }}
          </p>
          <div class="flex justify-end gap-3">
            <SvButton
              variant="secondary"
              type="button"
              @click="closeModal"
            >
              Cancel
            </SvButton>
            <SvButton
              type="submit"
              :variant="pendingAction === 'reactivate' ? 'primary' : 'destructive'"
              :loading="submitting"
              :disabled="confirmDisabled"
              data-testid="confirm-governance"
            >
              Confirm
            </SvButton>
          </div>
        </form>
      </SvDialog>
    </SvCard>
  </div>
</template>
