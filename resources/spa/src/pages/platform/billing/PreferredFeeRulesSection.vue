<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import { useCan } from '@/composables/useCan';
import { useNotificationStore } from '@/stores/notificationStore';
import { formatDateTime } from '@/utils/dates';
import {
  FEE_CALCULATION_BASES,
  FEE_CALCULATION_TYPES,
  FEE_SCOPES,
  FEE_STATUSES,
  isTerminalFeeStatus,
  usePreferredPersonnelFeeStore,
  type FeeRule,
} from '@/stores/preferredPersonnelFeeStore';

// Phase 20A — preferred-personnel fee rules (platform administration). Read/manage require
// `platform.preferred_personnel_fee.manage`; create/approve/supersede/cancel need a fresh
// step-up (server-enforced). Active monetary terms are immutable — a change SUPERSEDES with a
// new version. Fixed and percentage are mutually exclusive; platform_default forbids a
// service, service scope requires one. Only draft/scheduled rules may be cancelled.
const store = usePreferredPersonnelFeeStore();
const notifications = useNotificationStore();
const { can } = useCan();

const canManage = computed(() => can('platform.preferred_personnel_fee.manage'));

type Mode = 'create' | 'supersede';
const modalOpen = ref(false);
const mode = ref<Mode>('create');
const supersedeTarget = ref<FeeRule | null>(null);
const cancelTarget = ref<FeeRule | null>(null);
const actionError = ref<string | null>(null);

const form = reactive({
  calculation_type: 'fixed_amount',
  fixed_amount_major: '',
  currency: 'KES',
  percentage_basis_points: '',
  calculation_basis: 'service_item_net_amount',
  scope: 'platform_default',
  service_id: '',
  effective_from: '',
  effective_to: '',
  change_reason: '',
});
const errors = reactive<Record<string, string[]>>({});

const isFixed = computed(() => form.calculation_type === 'fixed_amount');
const isServiceScope = computed(() => form.scope === 'service');

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.rules.length === 0) return 'empty';
  return 'success';
});

const scopeFilterOptions = [{ value: '', label: 'All scopes' }, ...FEE_SCOPES];
const statusFilterOptions = [{ value: '', label: 'All statuses' }, ...FEE_STATUSES];

function money(minor: number | null, currency: string | null): string {
  if (minor === null) return '—';
  return `${currency ?? ''} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

function terms(rule: FeeRule): string {
  return rule.calculation_type === 'fixed_amount'
    ? money(rule.fixed_amount_minor, rule.currency)
    : `${((rule.percentage_basis_points ?? 0) / 100).toFixed(2)}%`;
}

onMounted(() => store.fetchRules());

async function reload(): Promise<void> {
  await store.fetchRules();
}

function resetForm(): void {
  form.calculation_type = 'fixed_amount';
  form.fixed_amount_major = '';
  form.currency = 'KES';
  form.percentage_basis_points = '';
  form.calculation_basis = 'service_item_net_amount';
  form.scope = 'platform_default';
  form.service_id = '';
  form.effective_from = '';
  form.effective_to = '';
  form.change_reason = '';
  Object.keys(errors).forEach((k) => delete errors[k]);
  actionError.value = null;
}

function openCreate(): void {
  mode.value = 'create';
  supersedeTarget.value = null;
  resetForm();
  modalOpen.value = true;
}

function openSupersede(rule: FeeRule): void {
  mode.value = 'supersede';
  supersedeTarget.value = rule;
  resetForm();
  // Prefill from the active rule; scope/service are fixed by the superseded rule.
  form.calculation_type = rule.calculation_type;
  form.calculation_basis = rule.calculation_basis;
  form.scope = rule.scope;
  form.service_id = rule.service_id ?? '';
  if (rule.calculation_type === 'fixed_amount') {
    form.fixed_amount_major = rule.fixed_amount_minor === null ? '' : String(rule.fixed_amount_minor / 100);
    form.currency = rule.currency ?? 'KES';
  } else {
    form.percentage_basis_points = String(rule.percentage_basis_points ?? '');
  }
  modalOpen.value = true;
}

const submitting = ref(false);

async function submit(): Promise<void> {
  if (submitting.value || !canManage.value) return;
  submitting.value = true;
  actionError.value = null;
  Object.keys(errors).forEach((k) => delete errors[k]);

  const fixedMinor = form.fixed_amount_major.trim() === '' ? null : Math.round(Number(form.fixed_amount_major) * 100);
  const bp = form.percentage_basis_points.trim() === '' ? null : Number(form.percentage_basis_points);

  const base = {
    calculation_type: form.calculation_type,
    fixed_amount_minor: isFixed.value ? fixedMinor : null,
    currency: isFixed.value ? form.currency.toUpperCase() : null,
    percentage_basis_points: isFixed.value ? null : bp,
    calculation_basis: form.calculation_basis,
    effective_from: form.effective_from,
    effective_to: form.effective_to === '' ? null : form.effective_to,
    change_reason: form.change_reason,
  };

  try {
    if (mode.value === 'supersede' && supersedeTarget.value !== null) {
      await store.supersedeRule(supersedeTarget.value.id, base);
      notifications.addToast({ type: 'success', message: 'Rule superseded with a new version.' });
    } else {
      await store.createRule({
        ...base,
        scope: form.scope,
        service_id: isServiceScope.value ? form.service_id : null,
      });
      notifications.addToast({ type: 'success', message: 'Draft fee rule created.' });
    }
    modalOpen.value = false;
    await store.fetchRules();
  } catch (err) {
    if (axios.isAxiosError(err) && err.apiError) {
      Object.assign(errors, err.apiError.fields);
      actionError.value =
        err.apiError.code === 'invalid_state_transition' || err.apiError.code === 'duplicate_reference'
          ? 'This rule overlaps an existing active or scheduled rule for the same scope.'
          : err.apiError.message ?? 'The rule could not be saved (a fresh step-up may be required).';
    } else {
      actionError.value = 'Something went wrong.';
    }
  } finally {
    submitting.value = false;
  }
}

async function approve(rule: FeeRule): Promise<void> {
  actionError.value = null;
  try {
    await store.approveRule(rule.id);
    notifications.addToast({ type: 'success', message: 'Rule approved.' });
    await store.fetchRules();
  } catch (err) {
    notifications.addToast({
      type: 'error',
      message:
        axios.isAxiosError(err) && err.apiError
          ? err.apiError.message
          : 'The rule could not be approved (a fresh step-up may be required).',
    });
  }
}

async function confirmCancel(): Promise<void> {
  if (cancelTarget.value === null) return;
  actionError.value = null;
  try {
    await store.cancelRule(cancelTarget.value.id);
    notifications.addToast({ type: 'success', message: 'Rule cancelled.' });
    cancelTarget.value = null;
    await store.fetchRules();
  } catch (err) {
    actionError.value =
      axios.isAxiosError(err) && err.apiError ? err.apiError.message : 'The rule could not be cancelled.';
  }
}
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001: a fee-rules workspace — the filters and the draft action share the panel
    header; each rule is a fee-tier card whose TERMS are its figure, with scope, basis and the
    effective window as facts and a spine in the rule's lifecycle tone. Active terms still say they
    are read-only; only draft/scheduled rules offer approve/cancel.
  -->
  <section
    aria-labelledby="fee-rules-heading"
    class="sv-r-panel"
  >
    <div class="sv-r-panel__head">
      <div class="min-w-0">
        <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
          Effective-dated fee tiers
        </p>
        <h2
          id="fee-rules-heading"
          class="sv-r-title mt-1"
        >
          Preferred-personnel fee rules
        </h2>
        <p class="sv-r-panel__desc">
          Platform-default and service-scoped fee rules. Active terms are immutable — change a
          rule by superseding it with a new version.
        </p>
      </div>
      <SvButton
        v-if="canManage"
        @click="openCreate"
      >
        New draft rule
      </SvButton>
    </div>

    <div class="mb-5 grid gap-4 rounded-card bg-sv-surface-subtle p-4 md:grid-cols-2">
      <SvSelect
        id="fee-scope-filter"
        label="Scope"
        :model-value="store.filterScope"
        :options="scopeFilterOptions"
        @update:model-value="(store.filterScope = $event), reload()"
      />
      <SvSelect
        id="fee-status-filter"
        label="Status"
        :model-value="store.filterStatus"
        :options="statusFilterOptions"
        @update:model-value="(store.filterStatus = $event), reload()"
      />
    </div>

    <SvStateBoundary
      :state="boundaryState"
      :error-message="store.error ?? undefined"
      empty-message="No preferred-personnel fee rules match this filter."
      @retry="store.fetchRules()"
    >
      <ul class="flex flex-col gap-3">
        <li
          v-for="rule in store.rules"
          :key="rule.id"
          data-testid="fee-rule-row"
        >
          <div
            class="sv-r-record sv-r-record--spine pl-6"
            :class="{
              'sv-r-tone-warm': rule.status === 'active',
              'sv-r-tone-sun': rule.status === 'scheduled' || rule.status === 'draft',
              'sv-r-tone-neutral': isTerminalFeeStatus(rule.status),
            }"
          >
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="sv-r-chip">
                    {{ rule.scope === 'service' ? 'Service' : 'Platform default' }}
                  </span>
                  <span
                    class="sv-r-chip sv-r-chip--tone sv-r-chip--dot"
                    :class="{
                      'sv-r-tone-warm': rule.status === 'active',
                      'sv-r-tone-sun': rule.status === 'scheduled' || rule.status === 'draft',
                      'sv-r-tone-neutral': isTerminalFeeStatus(rule.status),
                    }"
                  >{{ rule.status }}</span>
                </div>
                <p class="sv-r-figure-md mt-2">
                  {{ terms(rule) }}
                </p>
                <p class="sv-r-muted mt-1">
                  {{ rule.calculation_basis === 'service_item_net_amount' ? 'Net basis' : 'Gross basis' }} ·
                  from {{ formatDateTime(rule.effective_from) }}<span v-if="rule.effective_to"> to {{ formatDateTime(rule.effective_to) }}</span>
                </p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <SvButton
                  v-if="canManage && (rule.status === 'draft' || rule.status === 'scheduled')"
                  variant="secondary"
                  @click="approve(rule)"
                >
                  Approve
                </SvButton>
                <SvButton
                  v-if="canManage && rule.status === 'active'"
                  variant="ghost"
                  @click="openSupersede(rule)"
                >
                  Supersede
                </SvButton>
                <SvButton
                  v-if="canManage && (rule.status === 'draft' || rule.status === 'scheduled')"
                  variant="destructive"
                  @click="cancelTarget = rule"
                >
                  Cancel
                </SvButton>
                <span
                  v-if="rule.status === 'active'"
                  class="sv-r-chip"
                >Active terms are read-only</span>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </SvStateBoundary>

    <SvDialog
      :open="modalOpen"
      :title="mode === 'supersede' ? 'Supersede fee rule' : 'New draft fee rule'"
      :description="mode === 'supersede'
        ? 'Creates a new version that supersedes the active rule. Scope and service are inherited.'
        : 'Fixed and percentage terms are mutually exclusive. A service-scoped rule requires a service.'"
      @close="modalOpen = false"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvSelect
          id="fee-calc-type"
          label="Calculation type"
          :model-value="form.calculation_type"
          :options="[...FEE_CALCULATION_TYPES]"
          :errors="errors.calculation_type"
          required
          @update:model-value="form.calculation_type = $event"
        />

        <div
          v-if="isFixed"
          class="grid gap-4 md:grid-cols-2"
        >
          <SvTextInput
            id="fee-fixed-amount"
            label="Fixed amount (major units)"
            type="number"
            :model-value="form.fixed_amount_major"
            :errors="errors.fixed_amount_minor"
            required
            @update:model-value="form.fixed_amount_major = $event"
          />
          <SvTextInput
            id="fee-currency"
            label="Currency"
            :model-value="form.currency"
            :errors="errors.currency"
            required
            @update:model-value="form.currency = $event"
          />
        </div>
        <SvTextInput
          v-else
          id="fee-basis-points"
          label="Percentage (basis points, 0–10000)"
          type="number"
          :model-value="form.percentage_basis_points"
          :errors="errors.percentage_basis_points"
          help="10000 basis points = 100%."
          required
          @update:model-value="form.percentage_basis_points = $event"
        />

        <SvSelect
          id="fee-basis"
          label="Calculation basis"
          :model-value="form.calculation_basis"
          :options="[...FEE_CALCULATION_BASES]"
          :errors="errors.calculation_basis"
          required
          @update:model-value="form.calculation_basis = $event"
        />

        <template v-if="mode === 'create'">
          <SvSelect
            id="fee-scope"
            label="Scope"
            :model-value="form.scope"
            :options="[...FEE_SCOPES]"
            :errors="errors.scope"
            required
            @update:model-value="form.scope = $event"
          />
          <SvTextInput
            v-if="isServiceScope"
            id="fee-service"
            label="Service (ULID)"
            :model-value="form.service_id"
            :errors="errors.service_id"
            help="The 26-character service identifier this rule overrides."
            required
            @update:model-value="form.service_id = $event"
          />
        </template>

        <div class="grid gap-4 md:grid-cols-2">
          <SvTextInput
            id="fee-from"
            label="Effective from"
            type="date"
            :model-value="form.effective_from"
            :errors="errors.effective_from"
            required
            @update:model-value="form.effective_from = $event"
          />
          <SvTextInput
            id="fee-to"
            label="Effective to (optional)"
            type="date"
            :model-value="form.effective_to"
            :errors="errors.effective_to"
            @update:model-value="form.effective_to = $event"
          />
        </div>

        <SvTextArea
          id="fee-reason"
          label="Change reason"
          :model-value="form.change_reason"
          :errors="errors.change_reason"
          required
          @update:model-value="form.change_reason = $event"
        />

        <p
          v-if="actionError"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="modalOpen = false"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            :loading="submitting"
          >
            {{ mode === 'supersede' ? 'Supersede rule' : 'Create draft' }}
          </SvButton>
        </div>
      </form>
    </SvDialog>

    <SvDialog
      :open="cancelTarget !== null"
      title="Cancel fee rule?"
      description="Only a draft or scheduled rule can be cancelled. Active and terminal rules are never affected."
      @close="cancelTarget = null"
    >
      <p
        v-if="actionError"
        class="text-sm text-error"
        role="alert"
      >
        {{ actionError }}
      </p>
      <div class="flex justify-end gap-2">
        <SvButton
          variant="secondary"
          @click="cancelTarget = null"
        >
          Keep rule
        </SvButton>
        <SvButton
          variant="destructive"
          @click="confirmCancel"
        >
          Cancel rule
        </SvButton>
      </div>
    </SvDialog>
  </section>
</template>
