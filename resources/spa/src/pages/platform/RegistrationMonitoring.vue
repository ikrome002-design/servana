<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { usePlatformMerchantStore, type PlatformMerchant } from '@/stores/platformMerchantStore';

/**
 * Platform registration monitoring + merchant governance (Plan §22, §24.1; Phase 20B). Super-Admin
 * consolidated surface: an onboarding-funnel monitor and a merchant directory with detail. The
 * OPERATIONAL status and BILLING status are shown SEPARATELY; governance suspend/reactivate/
 * deactivate each require a mandatory reason + explicit confirmation, and a fresh `merchant_governance`
 * step-up is enforced by the server (surfaced here). Operational reactivation never clears a billing
 * suspension. There is NO merchant-create, first-admin, impersonation, manual-payment, or Wallet
 * action. Controls are UX-gated by the server-derived `can` map; the backend is authoritative.
 */
type Tab = 'monitoring' | 'directory';
type GovernanceAction = 'suspend' | 'reactivate' | 'deactivate';

const store = usePlatformMerchantStore();
const notifications = useNotificationStore();
const { can } = useCan();

const tab = ref<Tab>('monitoring');
const reason = ref('');
const pendingAction = ref<GovernanceAction | null>(null);
const submitting = ref(false);
const actionError = ref<string | null>(null);
let triggerEl: HTMLElement | null = null;

const canMonitor = computed(() => can('platform.registration_monitor.view'));
const canViewMerchants = computed(() => can('platform.merchant.view'));
const selected = computed(() => store.selected);

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  const empty = tab.value === 'monitoring' ? store.registrations.length === 0 : store.merchants.length === 0;
  return empty ? 'empty' : 'success';
});

const modalTitle = computed(() => {
  switch (pendingAction.value) {
    case 'suspend': return 'Suspend merchant';
    case 'reactivate': return 'Reactivate merchant';
    case 'deactivate': return 'Deactivate merchant';
    default: return '';
  }
});

const STATUS_LABELS: Record<string, string> = {
  pending_setup: 'Pending setup',
  active: 'Active',
  suspended: 'Suspended',
  deactivated: 'Deactivated',
};

const BILLING_LABELS: Record<string, string> = {
  trialing: 'Trialing',
  active: 'Active',
  overdue: 'Overdue',
  read_only_grace: 'Read-only grace',
  suspended_billing: 'Suspended',
};

onMounted(() => {
  if (canMonitor.value) void store.fetchRegistrations();
});

async function switchTab(next: Tab): Promise<void> {
  tab.value = next;
  if (next === 'monitoring' && canMonitor.value) await store.fetchRegistrations();
  if (next === 'directory' && canViewMerchants.value) await store.fetchMerchants();
}

async function openMerchant(id: string): Promise<void> {
  actionError.value = null;
  await store.fetchMerchant(id);
}

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
  if (confirmDisabled.value || pendingAction.value === null || selected.value === null) return;
  submitting.value = true;
  actionError.value = null;
  const action = pendingAction.value;
  const id = selected.value.id;
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
  <div class="mx-auto flex max-w-5xl flex-col gap-6">
    <header>
      <h1 class="font-display text-2xl font-bold text-heading">
        Registration monitoring and merchant governance
      </h1>
      <p class="mt-1 text-sm text-text-muted">
        Monitor onboarding and govern merchant operational status. Operational and billing status
        are independent. There is no merchant-creation path here — merchants self-register.
      </p>
    </header>

    <p
      v-if="!canMonitor && !canViewMerchants"
      class="rounded-control bg-surface-alt px-4 py-3 text-sm text-text-muted"
      role="note"
    >
      You do not have access to platform merchant governance.
    </p>

    <template v-else>
      <!-- Tabs -->
      <div
        role="tablist"
        aria-label="Merchant governance sections"
        class="flex gap-2 border-b border-border"
      >
        <button
          id="tab-monitoring"
          type="button"
          role="tab"
          :aria-selected="tab === 'monitoring'"
          aria-controls="panel-monitoring"
          class="min-h-[44px] px-4 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="tab === 'monitoring' ? 'border-b-2 border-primary text-heading' : 'text-text-muted'"
          @click="switchTab('monitoring')"
        >
          Registration monitoring
        </button>
        <button
          id="tab-directory"
          type="button"
          role="tab"
          :aria-selected="tab === 'directory'"
          aria-controls="panel-directory"
          class="min-h-[44px] px-4 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="tab === 'directory' ? 'border-b-2 border-primary text-heading' : 'text-text-muted'"
          @click="switchTab('directory')"
        >
          Merchant directory
        </button>
      </div>

      <SvStateBoundary
        :state="boundaryState"
        :error-message="store.error ?? undefined"
        :empty-message="tab === 'monitoring' ? 'No merchant registrations to monitor.' : 'No merchants found.'"
        @retry="tab === 'monitoring' ? store.fetchRegistrations() : store.fetchMerchants()"
      >
        <!-- Registration monitoring -->
        <section
          v-if="tab === 'monitoring'"
          id="panel-monitoring"
          role="tabpanel"
          aria-labelledby="tab-monitoring"
        >
          <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
              <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wide text-text-muted">
                  <th
                    scope="col"
                    class="py-2 pr-4"
                  >
                    Merchant
                  </th>
                  <th
                    scope="col"
                    class="py-2 pr-4"
                  >
                    Operational
                  </th>
                  <th
                    scope="col"
                    class="py-2 pr-4"
                  >
                    Billing
                  </th>
                  <th
                    scope="col"
                    class="py-2 pr-4"
                  >
                    Setup
                  </th>
                  <th
                    scope="col"
                    class="py-2"
                  >
                    Registered
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in store.registrations"
                  :key="row.id"
                  class="border-b border-border/60"
                >
                  <td class="py-2 pr-4 font-medium text-text">
                    {{ row.name }}
                  </td>
                  <td class="py-2 pr-4">
                    {{ STATUS_LABELS[row.operational_status] ?? row.operational_status }}
                  </td>
                  <td class="py-2 pr-4">
                    {{ BILLING_LABELS[row.billing_status] ?? row.billing_status }}
                  </td>
                  <td class="py-2 pr-4">
                    {{ row.pending_setup ? 'Pending' : 'Complete' }}
                  </td>
                  <td class="py-2">
                    {{ row.registered_at?.slice(0, 10) ?? '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Merchant directory + detail -->
        <section
          v-else
          id="panel-directory"
          role="tabpanel"
          aria-labelledby="tab-directory"
          class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
        >
          <SvCard>
            <h2 class="sr-only">
              Merchants
            </h2>
            <ul
              role="list"
              class="flex flex-col divide-y divide-border"
            >
              <li
                v-for="m in store.merchants"
                :key="m.id"
              >
                <button
                  type="button"
                  class="flex w-full items-center justify-between gap-3 px-1 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  :class="{ 'font-semibold text-heading': selected?.id === m.id }"
                  :aria-current="selected?.id === m.id ? 'true' : undefined"
                  :data-testid="`merchant-row-${m.id}`"
                  @click="openMerchant(m.id)"
                >
                  <span>{{ m.name }}</span>
                  <span class="text-xs text-text-muted">
                    {{ STATUS_LABELS[m.operational_status] ?? m.operational_status }}
                  </span>
                </button>
              </li>
            </ul>
          </SvCard>

          <SvCard v-if="selected">
            <h2
              class="font-display text-lg font-bold text-heading"
              data-testid="merchant-detail-name"
            >
              {{ selected.name }}
            </h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Operational status
                </dt>
                <dd
                  class="mt-1 font-semibold text-heading"
                  data-testid="operational-status"
                >
                  {{ STATUS_LABELS[selected.operational_status] ?? selected.operational_status }}
                </dd>
              </div>
              <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Billing status
                </dt>
                <dd
                  class="mt-1 font-semibold text-heading"
                  data-testid="detail-billing-status"
                >
                  {{ BILLING_LABELS[selected.billing_status] ?? selected.billing_status }}
                </dd>
              </div>
              <div v-if="selected.suspension_reason">
                <dt class="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Last governance reason
                </dt>
                <dd class="mt-1 text-text">
                  {{ selected.suspension_reason }}
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
                v-if="canGovern(selected, 'suspend')"
                variant="destructive"
                data-testid="action-suspend"
                @click="openGovernance('suspend', $event)"
              >
                Suspend
              </SvButton>
              <SvButton
                v-if="canGovern(selected, 'reactivate')"
                data-testid="action-reactivate"
                @click="openGovernance('reactivate', $event)"
              >
                Reactivate
              </SvButton>
              <SvButton
                v-if="canGovern(selected, 'deactivate')"
                variant="destructive"
                data-testid="action-deactivate"
                @click="openGovernance('deactivate', $event)"
              >
                Deactivate
              </SvButton>
            </div>
          </SvCard>
        </section>
      </SvStateBoundary>
    </template>

    <!-- Governance confirmation with mandatory reason -->
    <SvModal
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
        <SvTextarea
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
    </SvModal>
  </div>
</template>
