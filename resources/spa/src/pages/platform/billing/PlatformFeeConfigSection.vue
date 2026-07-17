<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import {
  usePlatformFeeConfigStore,
  type PlatformFeeConfigPayload,
  type PlatformFeeConfiguration,
} from '@/stores/platformFeeConfigStore';
import {
  PLATFORM_FEE_BASIS_OPTIONS,
  PLATFORM_FEE_BILLING_MODE_OPTIONS,
  PLATFORM_FEE_STATUS_LABELS,
  PLATFORM_FEE_TIER_OPTIONS,
  platformFeeConfigTermsLabel,
} from '@/content/platformFee';

// Phase 20E — percentage platform-fee CONFIGURATION governance (Plan §51, §52). Super-Admin only,
// under `platform.platform_fee.configure`; platform scope, MFA + a fresh billing-configuration step-up
// on every mutation (server-enforced — this UI is a UX-only gate). Approved monetary terms are IMMUTABLE:
// a change is a supersede (new version), never an in-place edit. Named transitions only — no generic
// status selector. The `shared` tier is always shown by that canonical label (never the persisted
// `split_tier` value).
const store = usePlatformFeeConfigStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canManage = computed(() => can('platform.platform_fee.configure'));

type Mode = 'create' | 'edit' | 'supersede';
const modalOpen = ref(false);
const mode = ref<Mode>('create');
const targetId = ref<string | null>(null);
const cancelTarget = ref<PlatformFeeConfiguration | null>(null);
const actionError = ref<string | null>(null);
const submitting = ref(false);

const form = reactive({
  billing_mode: 'percentage_on_merchant_client_invoice',
  percentage_basis_points: '',
  fixed_component_major: '',
  tier_behavior: 'customer_centric',
  shared_split_basis_points: '',
  fee_basis_type: 'merchant_client_invoice_service_subtotal',
  currency: 'KES',
  effective_from: '',
  effective_to: '',
  change_reason: '',
});
const errors = reactive<Record<string, string[]>>({});

const isShared = computed(() => form.tier_behavior === 'shared');
const isCustomerCentric = computed(() => form.tier_behavior === 'customer_centric');

const statusFilterOptions = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'active', label: 'Active' },
  { value: 'superseded', label: 'Superseded' },
  { value: 'cancelled', label: 'Cancelled' },
];

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.configurations.length === 0) return 'empty';
  return 'success';
});

const modalTitle = computed(() =>
  mode.value === 'create' ? 'New draft configuration' : mode.value === 'edit' ? 'Edit draft configuration' : 'Supersede with a new version',
);

function statusLabel(status: string): string {
  return PLATFORM_FEE_STATUS_LABELS[status] ?? status;
}

onMounted(() => store.fetchConfigurations());

async function reload(): Promise<void> {
  await store.fetchConfigurations();
}

function resetForm(): void {
  form.billing_mode = 'percentage_on_merchant_client_invoice';
  form.percentage_basis_points = '';
  form.fixed_component_major = '';
  form.tier_behavior = 'customer_centric';
  form.shared_split_basis_points = '';
  form.fee_basis_type = 'merchant_client_invoice_service_subtotal';
  form.currency = 'KES';
  form.effective_from = '';
  form.effective_to = '';
  form.change_reason = '';
  Object.keys(errors).forEach((k) => delete errors[k]);
  actionError.value = null;
}

function prefill(config: PlatformFeeConfiguration): void {
  form.billing_mode = config.billing_mode;
  form.percentage_basis_points = config.percentage_basis_points === null ? '' : String(config.percentage_basis_points);
  form.fixed_component_major = config.fixed_component_minor === null ? '' : String(config.fixed_component_minor / 100);
  // A legacy configuration can carry a null tier/basis. Prefill blank rather than the
  // create-time default so editing it cannot silently propose a different fee behaviour —
  // the admin must re-select, and the Form Request rejects a blank.
  form.tier_behavior = config.tier_behavior ?? '';
  form.shared_split_basis_points = config.shared_split_basis_points === null ? '' : String(config.shared_split_basis_points);
  form.fee_basis_type = config.fee_basis_type ?? '';
  form.currency = config.currency;
  form.effective_from = config.effective_from;
  form.effective_to = config.effective_to ?? '';
  form.change_reason = '';
}

function openCreate(): void {
  mode.value = 'create';
  targetId.value = null;
  resetForm();
  modalOpen.value = true;
}

function openEdit(config: PlatformFeeConfiguration): void {
  mode.value = 'edit';
  targetId.value = config.id;
  resetForm();
  prefill(config);
  modalOpen.value = true;
}

function openSupersede(config: PlatformFeeConfiguration): void {
  mode.value = 'supersede';
  targetId.value = config.id;
  resetForm();
  prefill(config);
  modalOpen.value = true;
}

/** Client-side coherence mirrors the server rules; the backend remains authoritative. */
function validate(): boolean {
  Object.keys(errors).forEach((k) => delete errors[k]);
  const bp = form.percentage_basis_points.trim();
  if (bp !== '' && !/^\d+$/.test(bp)) errors.percentage_basis_points = ['Enter whole basis points (0–10000).'];
  if (bp !== '' && Number(bp) > 10000) errors.percentage_basis_points = ['Basis points cannot exceed 10000 (100%).'];
  if (isShared.value) {
    const split = form.shared_split_basis_points.trim();
    if (split === '' || !/^\d+$/.test(split)) errors.shared_split_basis_points = ['A shared split (basis points) is required for the shared tier.'];
    else if (Number(split) > 10000) errors.shared_split_basis_points = ['Shared split cannot exceed 10000.'];
  }
  if (form.fee_basis_type === 'validated_paid_amount' && !isCustomerCentric.value) {
    errors.fee_basis_type = ['The validated-paid-amount basis is only available for the customer-centric tier.'];
  }
  if (form.effective_to !== '' && form.effective_from !== '' && form.effective_to <= form.effective_from) {
    errors.effective_to = ['The end date must be after the start date.'];
  }
  if (form.change_reason.trim().length < 2) errors.change_reason = ['A change reason is required.'];
  return Object.keys(errors).length === 0;
}

function buildPayload(): PlatformFeeConfigPayload {
  const fixedMinor = form.fixed_component_major.trim() === '' ? null : Math.round(Number(form.fixed_component_major) * 100);
  return {
    billing_mode: form.billing_mode,
    percentage_basis_points: form.percentage_basis_points.trim() === '' ? null : Number(form.percentage_basis_points),
    fixed_component_minor: fixedMinor,
    tier_behavior: form.tier_behavior,
    shared_split_basis_points: isShared.value ? Number(form.shared_split_basis_points) : null,
    fee_basis_type: form.fee_basis_type,
    currency: form.currency.toUpperCase(),
    effective_from: form.effective_from,
    effective_to: form.effective_to === '' ? null : form.effective_to,
    change_reason: form.change_reason.trim(),
  };
}

function mapError(err: unknown): void {
  if (axios.isAxiosError(err) && err.apiError) {
    Object.assign(errors, err.apiError.fields);
    actionError.value =
      err.apiError.code === 'platform_fee_configuration_overlap'
        ? 'This configuration overlaps an existing approved configuration for the same currency and window.'
        : err.apiError.message ?? 'The configuration could not be saved (a fresh step-up may be required).';
  } else {
    actionError.value = 'Something went wrong.';
  }
}

async function submit(): Promise<void> {
  if (submitting.value || !canManage.value || !validate()) return;
  submitting.value = true;
  actionError.value = null;
  const payload = buildPayload();
  try {
    if (mode.value === 'edit' && targetId.value !== null) {
      await store.updateDraft(targetId.value, payload);
      notifications.addToast({ type: 'success', message: 'Draft configuration updated.' });
    } else if (mode.value === 'supersede' && targetId.value !== null) {
      await store.transition(targetId.value, 'supersede', payload);
      notifications.addToast({ type: 'success', message: 'Configuration superseded with a new version.' });
    } else {
      await store.createConfiguration(payload);
      notifications.addToast({ type: 'success', message: 'Draft configuration created.' });
    }
    modalOpen.value = false;
    await store.fetchConfigurations();
  } catch (err) {
    mapError(err);
  } finally {
    submitting.value = false;
  }
}

async function approve(config: PlatformFeeConfiguration): Promise<void> {
  try {
    await store.transition(config.id, 'approve', { change_reason: 'Approved from the configuration surface.' });
    notifications.addToast({ type: 'success', message: 'Configuration approved.' });
    await store.fetchConfigurations();
  } catch (err) {
    notifications.addToast({
      type: 'error',
      message: axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The configuration could not be approved (a fresh step-up may be required).',
    });
  }
}

async function confirmCancel(): Promise<void> {
  if (cancelTarget.value === null) return;
  actionError.value = null;
  try {
    await store.transition(cancelTarget.value.id, 'cancel', { change_reason: 'Cancelled from the configuration surface.' });
    notifications.addToast({ type: 'success', message: 'Configuration cancelled.' });
    cancelTarget.value = null;
    await store.fetchConfigurations();
  } catch (err) {
    actionError.value = axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The configuration could not be cancelled.';
  }
}
</script>

<template>
  <section aria-labelledby="platform-fee-config-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2
          id="platform-fee-config-heading"
          class="font-display text-lg font-bold text-heading"
        >
          Percentage platform-fee configuration
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          The percentage fee Servana charges merchants on validated merchant-client activity. Approved terms
          are immutable — change a configuration by superseding it with a new version. Every change requires a
          fresh step-up.
        </p>
      </div>
      <SvButton
        v-if="canManage"
        @click="openCreate"
      >
        New draft configuration
      </SvButton>
    </div>

    <div class="mt-4 max-w-xs">
      <SvSelect
        id="platform-fee-status-filter"
        label="Status"
        :model-value="store.filterStatus"
        :options="statusFilterOptions"
        @update:model-value="(store.filterStatus = $event), reload()"
      />
    </div>

    <SvStateBoundary
      class="mt-4"
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No platform-fee configurations match this filter."
      @retry="store.fetchConfigurations()"
    >
      <ul class="mt-2 flex flex-col gap-3">
        <li
          v-for="config in store.configurations"
          :key="config.id"
          data-testid="platform-fee-config-row"
        >
          <SvCard padding="sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-semibold text-text">
                  {{ platformFeeConfigTermsLabel(config) }}
                  <span class="ml-2 rounded-control bg-surface-alt px-2 py-0.5 text-xs text-text-muted">
                    {{ config.currency }}
                  </span>
                  <span
                    class="ml-2 rounded-control px-2 py-0.5 text-xs"
                    :class="config.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' : 'bg-surface-alt text-text-muted'"
                  >
                    {{ statusLabel(config.status) }}
                  </span>
                </p>
                <p class="mt-1 text-xs text-text-muted">
                  Effective {{ config.effective_from }}<span v-if="config.effective_to"> – {{ config.effective_to }}</span>
                  · {{ config.change_reason }}
                </p>
                <p
                  v-if="config.approved_at"
                  class="mt-0.5 text-xs text-text-muted"
                >
                  Approved {{ config.approved_at }}
                </p>
              </div>
              <div
                v-if="canManage"
                class="flex flex-wrap gap-2"
              >
                <SvButton
                  v-if="config.capabilities.editable"
                  variant="secondary"
                  @click="openEdit(config)"
                >
                  Edit draft
                </SvButton>
                <SvButton
                  v-if="config.capabilities.approvable"
                  @click="approve(config)"
                >
                  Approve
                </SvButton>
                <SvButton
                  v-if="config.capabilities.supersedable"
                  variant="secondary"
                  @click="openSupersede(config)"
                >
                  Supersede
                </SvButton>
                <SvButton
                  v-if="config.capabilities.cancellable"
                  variant="destructive"
                  @click="cancelTarget = config"
                >
                  Cancel
                </SvButton>
              </div>
            </div>
          </SvCard>
        </li>
      </ul>
    </SvStateBoundary>

    <!-- Create / edit / supersede modal -->
    <SvModal
      :open="modalOpen"
      :title="modalTitle"
      @close="modalOpen = false"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="submit"
      >
        <p
          v-if="actionError"
          class="rounded-control bg-red-50 px-3 py-2 text-sm text-error dark:bg-red-900/20"
          role="alert"
        >
          {{ actionError }}
        </p>

        <SvSelect
          id="pf-billing-mode"
          label="Applies to billing mode"
          :model-value="form.billing_mode"
          :options="PLATFORM_FEE_BILLING_MODE_OPTIONS"
          :errors="errors.billing_mode"
          @update:model-value="form.billing_mode = $event"
        />
        <SvSelect
          id="pf-tier"
          label="Tier behaviour"
          :model-value="form.tier_behavior"
          :options="PLATFORM_FEE_TIER_OPTIONS"
          :errors="errors.tier_behavior"
          @update:model-value="form.tier_behavior = $event"
        />
        <SvInput
          id="pf-bps"
          label="Percentage (basis points, 0–10000)"
          type="number"
          :model-value="form.percentage_basis_points"
          :errors="errors.percentage_basis_points"
          @update:model-value="form.percentage_basis_points = $event"
        />
        <SvInput
          v-if="isShared"
          id="pf-split"
          label="Shared split (basis points)"
          type="number"
          required
          :model-value="form.shared_split_basis_points"
          :errors="errors.shared_split_basis_points"
          @update:model-value="form.shared_split_basis_points = $event"
        />
        <SvInput
          id="pf-fixed"
          label="Fixed component (optional)"
          type="number"
          :model-value="form.fixed_component_major"
          :errors="errors.fixed_component_minor"
          @update:model-value="form.fixed_component_major = $event"
        />
        <SvSelect
          id="pf-basis"
          label="Fee basis"
          :model-value="form.fee_basis_type"
          :options="PLATFORM_FEE_BASIS_OPTIONS"
          :errors="errors.fee_basis_type"
          @update:model-value="form.fee_basis_type = $event"
        />
        <SvInput
          id="pf-currency"
          label="Currency"
          :model-value="form.currency"
          :errors="errors.currency"
          @update:model-value="form.currency = $event"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <SvInput
            id="pf-eff-from"
            label="Effective from"
            type="date"
            required
            :model-value="form.effective_from"
            :errors="errors.effective_from"
            @update:model-value="form.effective_from = $event"
          />
          <SvInput
            id="pf-eff-to"
            label="Effective to (optional)"
            type="date"
            :model-value="form.effective_to"
            :errors="errors.effective_to"
            @update:model-value="form.effective_to = $event"
          />
        </div>
        <SvTextarea
          id="pf-reason"
          label="Change reason"
          required
          :model-value="form.change_reason"
          :errors="errors.change_reason"
          @update:model-value="form.change_reason = $event"
        />

        <div class="flex justify-end gap-3">
          <SvButton
            variant="secondary"
            type="button"
            @click="modalOpen = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            :loading="submitting"
          >
            Save
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- Cancel confirmation -->
    <SvModal
      :open="cancelTarget !== null"
      title="Cancel configuration"
      @close="cancelTarget = null"
    >
      <p class="text-sm text-text">
        Cancel this draft/scheduled configuration? This cannot be undone. Approved active configurations are
        superseded, not cancelled.
      </p>
      <p
        v-if="actionError"
        class="mt-3 text-sm text-error"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="mt-4 flex justify-end gap-3">
        <SvButton
          variant="secondary"
          @click="cancelTarget = null"
        >
          Keep
        </SvButton>
        <SvButton
          variant="destructive"
          @click="confirmCancel"
        >
          Cancel configuration
        </SvButton>
      </div>
    </SvModal>
  </section>
</template>
