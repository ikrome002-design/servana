<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import SvModal from '@/components/ui/SvModal.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextarea from '@/components/ui/SvTextarea.vue';
import { useCan } from '@/composables/useCan';
import { useAuthStore } from '@/stores/authStore';
import {
  COMMISSION_APPLIES_TO,
  COMMISSION_CALCULATION_BASES,
  COMMISSION_CALCULATION_TYPES,
  COMPENSATION_MODELS,
  PLAN_STATUSES,
  SALARY_PERIODS,
  modelRequiresCommissionRule,
  modelRequiresSalary,
  useCompensationStore,
  type CompensationPlan,
  type PlanTransition,
} from '@/stores/compensationStore';
import { useStaffStore } from '@/stores/staffStore';

/**
 * HR Compensation — compensation-plan and commission-rule CONFIGURATION (Plan §59, §80; Scope
 * §12.1-§12.9, §18.3; Phase 20F). Branch-scoped, HR-only. Every control here is UX: the API
 * (EnsureBranchScope + EnsurePermission + policy + RequireFreshMfa) is the security boundary and
 * re-authorizes each mutation regardless of what this screen renders.
 *
 * Configuration only. Nothing on this screen is earned, accrued, payable or paid: there is no
 * salary ledger, no commission ledger, no payout, no earnings statement and no liability surface
 * (Phases 20G/20H own those, and no endpoint exists). Salary/commission amounts are DECLARED terms
 * in integer minor units, formatted for display only — never computed here.
 *
 * Approval requires a fresh step-up (server-enforced) and maker/checker (the submitter can never
 * approve). Supersede is a CONSEQUENCE of approving a successor — there is no supersede control.
 */
const store = useCompensationStore();
const staff = useStaffStore();
const auth = useAuthStore();
const { can } = useCan();

const canView = computed(() => can('compensation.plan.view'));
const canCreate = computed(() => can('compensation.plan.create'));
const canUpdateDraft = computed(() => can('compensation.plan.update_draft'));
const canSubmit = computed(() => can('compensation.plan.submit'));
const canApprove = computed(() => can('compensation.plan.approve'));
const canReject = computed(() => can('compensation.plan.reject'));
const canCancel = computed(() => can('compensation.plan.cancel'));
const canViewHistory = computed(() => can('compensation.history.view'));

const statusRegion = ref<HTMLElement | null>(null);
const statusMessage = ref<string>('');
const lastFocused = ref<HTMLElement | null>(null);

/** Remember the invoking control so a closed dialog returns focus where it came from. */
function rememberFocus(): void {
  lastFocused.value = document.activeElement instanceof HTMLElement ? document.activeElement : null;
}

function restoreFocus(): void {
  void nextTick(() => lastFocused.value?.focus());
}

async function announce(message: string): Promise<void> {
  statusMessage.value = message;
  await nextTick();
  statusRegion.value?.focus();
}

/* ------------------------------------------------------------------ list + filters */

const boundaryState = computed<'loading' | 'empty' | 'error' | 'success'>(() => {
  if (store.loading) return 'loading';
  if (store.error) return 'error';
  if (store.plans.length === 0) return 'empty';
  return 'success';
});

const statusFilterOptions = [{ value: '', label: 'All statuses' }, ...PLAN_STATUSES];
const modelFilterOptions = [{ value: '', label: 'All compensation models' }, ...COMPENSATION_MODELS];
const staffFilterOptions = computed(() => [
  { value: '', label: 'All staff' },
  ...staff.staff.map((s) => ({ value: s.id, label: s.display_name })),
]);
const staffOptions = computed(() => staff.staff.map((s) => ({ value: s.id, label: s.display_name })));

async function reload(): Promise<void> {
  await store.fetchPlans();
}

onMounted(async () => {
  if (!canView.value) return;
  // The roster supplies display names for the subject selector; contact data is never shown.
  await Promise.all([staff.fetchStaff().catch(() => undefined), store.fetchPlans(), store.fetchCommissionRules().catch(() => undefined)]);
});

// A branch/tenant context change invalidates every plan, rule and history row on screen.
watch(
  () => auth.branchIds,
  () => {
    store.$reset();
    if (canView.value) void store.fetchPlans();
  },
);

/* ------------------------------------------------------------------ display helpers */

function money(minor: number | null | undefined, currency: string | null | undefined): string {
  if (minor === null || minor === undefined) return '—';
  return `${currency ?? ''} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`.trim();
}

function periodLabel(period: string | null | undefined): string {
  return SALARY_PERIODS.find((p) => p.value === period)?.label ?? '—';
}

/** Declared salary terms — a cadence and an amount, never an accrual. */
function salaryTerms(plan: CompensationPlan): string {
  if (plan.salary_amount_minor === null || plan.salary_amount_minor === undefined) return 'No salary terms';
  return `${money(plan.salary_amount_minor, plan.salary_currency)} · ${periodLabel(plan.salary_period)}`;
}

function ruleTerms(plan: CompensationPlan): string {
  const rule = plan.commission_rule;
  if (!rule) return 'No commission rule';
  const value =
    rule.calculation_type === 'percentage'
      ? `${((rule.percentage_basis_points ?? 0) / 100).toFixed(2)}%`
      : money(rule.fixed_amount_minor, rule.currency);
  const basis = COMMISSION_CALCULATION_BASES.find((b) => b.value === rule.calculation_basis)?.label ?? rule.calculation_basis;
  return `${value} of ${basis}`;
}

function preferredFeeCopy(plan: CompensationPlan): string {
  return plan.commission_rule?.applies_to_preferred_personnel_fee === true
    ? 'Preferred-personnel fee included in commission basis'
    : 'Preferred-personnel fee excluded from commission basis';
}

// `--color-warning` (#f59e0b) is the one status token NOT overridden for dark mode, and it fails AA
// on a light surface at badge text size (2.14:1). The amber tint carries the semantics; the label
// text carries the status (never colour alone), so the foreground uses the adaptive text token —
// which reads AA against the tinted background in BOTH themes.
// `draft` keeps `text-brand-deep` because `--color-cream` is not overridden for dark either, so that
// pair stays brand-dark-on-cream (AA) in both themes. The tinted-surface badges cannot: a /15 tint
// over the dark surface IS dark, so they take the adaptive text token.
const statusClass: Record<string, string> = {
  active: 'bg-success/15 text-success',
  scheduled: 'bg-primary/15 text-text',
  pending_approval: 'bg-warning/15 text-text',
  draft: 'bg-cream text-brand-deep',
};

function badgeClass(status: string): string {
  return statusClass[status] ?? 'bg-surface-alt text-text-muted';
}

/* ------------------------------------------------------------------ detail + history */

const detail = ref<CompensationPlan | null>(null);

async function openDetail(plan: CompensationPlan): Promise<void> {
  rememberFocus();
  detail.value = plan;
  actionError.value = null;
  try {
    detail.value = await store.fetchPlan(plan.id);
  } catch {
    actionError.value = 'Unable to load this compensation plan.';
  }
  if (canViewHistory.value) {
    await store.fetchHistory(plan.id).catch(() => undefined);
  }
}

function closeDetail(): void {
  detail.value = null;
  store.history = [];
  restoreFocus();
}

/* ------------------------------------------------------------------ commission-rule draft */

const ruleModalOpen = ref(false);
const ruleEditing = ref<string | null>(null);
const ruleForm = reactive({
  calculation_type: 'percentage',
  percentage_basis_points: '',
  fixed_amount_major: '',
  currency: 'KES',
  calculation_basis: 'service_price',
  applies_to: 'all_services',
  service_category_id: '',
  applies_to_preferred_personnel_fee: false,
  effective_from: '',
  effective_to: '',
  change_reason: '',
});
const ruleErrors = reactive<Record<string, string[]>>({});
const isPercentage = computed(() => ruleForm.calculation_type === 'percentage');
const ruleNeedsCategory = computed(() => ruleForm.applies_to === 'service_category');

function resetRuleForm(): void {
  ruleForm.calculation_type = 'percentage';
  ruleForm.percentage_basis_points = '';
  ruleForm.fixed_amount_major = '';
  ruleForm.currency = 'KES';
  ruleForm.calculation_basis = 'service_price';
  ruleForm.applies_to = 'all_services';
  ruleForm.service_category_id = '';
  ruleForm.applies_to_preferred_personnel_fee = false;
  ruleForm.effective_from = '';
  ruleForm.effective_to = '';
  ruleForm.change_reason = '';
  Object.keys(ruleErrors).forEach((k) => delete ruleErrors[k]);
  actionError.value = null;
}

function openRuleCreate(): void {
  rememberFocus();
  ruleEditing.value = null;
  resetRuleForm();
  ruleModalOpen.value = true;
}

function closeRuleModal(): void {
  ruleModalOpen.value = false;
  restoreFocus();
}

/** Mirrors the server's F4 value shape so an obviously invalid draft is caught before the call. */
function validateRule(): boolean {
  Object.keys(ruleErrors).forEach((k) => delete ruleErrors[k]);
  if (isPercentage.value) {
    const bp = Number(ruleForm.percentage_basis_points);
    if (ruleForm.percentage_basis_points.trim() === '' || Number.isNaN(bp)) {
      ruleErrors.percentage_basis_points = ['A percentage commission rule requires a rate in basis points.'];
    } else if (!Number.isInteger(bp) || bp < 0 || bp > 10000) {
      ruleErrors.percentage_basis_points = ['Enter whole basis points between 0 and 10000.'];
    }
  } else {
    const major = Number(ruleForm.fixed_amount_major);
    if (ruleForm.fixed_amount_major.trim() === '' || Number.isNaN(major) || major < 0) {
      ruleErrors.fixed_amount_minor = ['A fixed commission rule requires a non-negative amount.'];
    }
    if (ruleForm.currency.trim() === '') ruleErrors.currency = ['A fixed commission rule requires a currency.'];
  }
  if (ruleNeedsCategory.value && ruleForm.service_category_id.trim() === '') {
    ruleErrors.service_category_id = ['A category-scoped commission rule requires a service category.'];
  }
  if (ruleForm.effective_from === '') ruleErrors.effective_from = ['An effective-from date is required.'];
  if (ruleForm.effective_to !== '' && ruleForm.effective_to <= ruleForm.effective_from) {
    ruleErrors.effective_to = ['Effective to must be after effective from.'];
  }
  if (ruleForm.change_reason.trim() === '') ruleErrors.change_reason = ['A change reason is required.'];
  return Object.keys(ruleErrors).length === 0;
}

const savingRule = ref(false);

async function saveRule(): Promise<void> {
  if (savingRule.value || !validateRule()) return;
  savingRule.value = true;
  actionError.value = null;
  const payload = {
    calculation_type: ruleForm.calculation_type,
    calculation_basis: ruleForm.calculation_basis,
    applies_to: ruleForm.applies_to,
    service_category_id: ruleNeedsCategory.value ? ruleForm.service_category_id : null,
    // Percentage and fixed terms are mutually exclusive — only ever send one.
    percentage_basis_points: isPercentage.value ? Number(ruleForm.percentage_basis_points) : null,
    fixed_amount_minor: isPercentage.value ? null : Math.round(Number(ruleForm.fixed_amount_major) * 100),
    currency: isPercentage.value ? null : ruleForm.currency.toUpperCase(),
    applies_to_preferred_personnel_fee: ruleForm.applies_to_preferred_personnel_fee,
    effective_from: ruleForm.effective_from,
    effective_to: ruleForm.effective_to === '' ? null : ruleForm.effective_to,
    change_reason: ruleForm.change_reason,
  };
  try {
    if (ruleEditing.value === null) {
      await store.createCommissionRule(payload);
      await announce('Commission rule draft created.');
    } else {
      await store.updateCommissionRuleDraft(ruleEditing.value, payload);
      await announce('Commission rule draft updated.');
    }
    ruleModalOpen.value = false;
    restoreFocus();
  } catch (err) {
    applyApiError(err, ruleErrors, 'The commission rule could not be saved.');
  } finally {
    savingRule.value = false;
  }
}

function openRuleEdit(id: string): void {
  const rule = store.rules.find((r) => r.id === id);
  if (!rule) return;
  rememberFocus();
  resetRuleForm();
  ruleEditing.value = id;
  ruleForm.calculation_type = rule.calculation_type;
  ruleForm.calculation_basis = rule.calculation_basis;
  ruleForm.applies_to = rule.applies_to;
  ruleForm.applies_to_preferred_personnel_fee = rule.applies_to_preferred_personnel_fee;
  ruleForm.effective_from = rule.effective_from;
  ruleForm.effective_to = rule.effective_to ?? '';
  if (rule.calculation_type === 'percentage') {
    ruleForm.percentage_basis_points = String(rule.percentage_basis_points ?? '');
  } else {
    ruleForm.fixed_amount_major = rule.fixed_amount_minor === null ? '' : String(rule.fixed_amount_minor / 100);
    ruleForm.currency = rule.currency ?? 'KES';
  }
  ruleModalOpen.value = true;
}

const draftRuleOptions = computed(() => [
  { value: '', label: 'No commission rule' },
  ...store.rules
    .filter((r) => r.is_editable || r.status === 'active' || r.status === 'scheduled')
    .map((r) => ({
      value: r.id,
      label: `${r.calculation_type === 'percentage' ? `${((r.percentage_basis_points ?? 0) / 100).toFixed(2)}%` : money(r.fixed_amount_minor, r.currency)} · ${r.status_label} · from ${r.effective_from}`,
    })),
]);

/* ------------------------------------------------------------------ compensation-plan draft */

const planModalOpen = ref(false);
const planEditing = ref<string | null>(null);
const planForm = reactive({
  staff_profile_id: '',
  compensation_model: 'salary_only',
  commission_rule_id: '',
  salary_amount_major: '',
  salary_currency: 'KES',
  salary_period: 'monthly',
  salary_payout_day: '',
  effective_from: '',
  effective_to: '',
  change_reason: '',
});
const planErrors = reactive<Record<string, string[]>>({});
const needsSalary = computed(() => modelRequiresSalary(planForm.compensation_model));
const needsRule = computed(() => modelRequiresCommissionRule(planForm.compensation_model));

function resetPlanForm(): void {
  planForm.staff_profile_id = '';
  planForm.compensation_model = 'salary_only';
  planForm.commission_rule_id = '';
  planForm.salary_amount_major = '';
  planForm.salary_currency = 'KES';
  planForm.salary_period = 'monthly';
  planForm.salary_payout_day = '';
  planForm.effective_from = '';
  planForm.effective_to = '';
  planForm.change_reason = '';
  Object.keys(planErrors).forEach((k) => delete planErrors[k]);
  actionError.value = null;
}

function openPlanCreate(): void {
  rememberFocus();
  planEditing.value = null;
  resetPlanForm();
  planModalOpen.value = true;
}

function openPlanEdit(plan: CompensationPlan): void {
  rememberFocus();
  resetPlanForm();
  planEditing.value = plan.id;
  planForm.staff_profile_id = plan.staff_profile_id ?? '';
  planForm.compensation_model = plan.compensation_model;
  planForm.commission_rule_id = plan.commission_rule?.id ?? '';
  planForm.salary_amount_major = plan.salary_amount_minor === null ? '' : String((plan.salary_amount_minor ?? 0) / 100);
  planForm.salary_currency = plan.salary_currency ?? 'KES';
  planForm.salary_period = plan.salary_period ?? 'monthly';
  planForm.salary_payout_day = plan.salary_payout_day === null ? '' : String(plan.salary_payout_day ?? '');
  planForm.effective_from = plan.effective_from;
  planForm.effective_to = plan.effective_to ?? '';
  planModalOpen.value = true;
}

function closePlanModal(): void {
  planModalOpen.value = false;
  restoreFocus();
}

/** Mirrors the server's F1 model shape — the request rules and the DB CHECK stay authoritative. */
function validatePlan(): boolean {
  Object.keys(planErrors).forEach((k) => delete planErrors[k]);
  if (planForm.staff_profile_id === '') planErrors.staff_profile_id = ['Select the staff member this plan applies to.'];
  const model = COMPENSATION_MODELS.find((m) => m.value === planForm.compensation_model);
  if (needsSalary.value) {
    const major = Number(planForm.salary_amount_major);
    if (planForm.salary_amount_major.trim() === '' || Number.isNaN(major) || major <= 0) {
      planErrors.salary_amount_minor = [`A ${model?.label.toLowerCase()} plan requires a salary amount.`];
    }
    if (planForm.salary_currency.trim() === '') planErrors.salary_currency = ['A salary currency is required.'];
    if (planForm.salary_period === '') planErrors.salary_period = ['A salary period is required.'];
  }
  if (needsRule.value && planForm.commission_rule_id === '') {
    planErrors.commission_rule_id = [`A ${model?.label.toLowerCase()} plan requires a commission rule.`];
  }
  if (!needsRule.value && planForm.commission_rule_id !== '') {
    planErrors.commission_rule_id = ['A salary only plan cannot reference a commission rule.'];
  }
  if (planForm.effective_from === '') planErrors.effective_from = ['An effective-from date is required.'];
  if (planForm.effective_to !== '' && planForm.effective_to <= planForm.effective_from) {
    planErrors.effective_to = ['Effective to must be after effective from.'];
  }
  if (planForm.change_reason.trim() === '') planErrors.change_reason = ['A change reason is required.'];
  return Object.keys(planErrors).length === 0;
}

const savingPlan = ref(false);

async function savePlan(): Promise<void> {
  if (savingPlan.value || !validatePlan()) return;
  savingPlan.value = true;
  actionError.value = null;
  // Salary terms are omitted entirely for commission_only; a commission rule is omitted for
  // salary_only. Server-owned fields (status, branch, is_backdated, supersedes, actors) are never
  // sent — the action owns them.
  const payload = {
    staff_profile_id: planForm.staff_profile_id,
    compensation_model: planForm.compensation_model,
    commission_rule_id: needsRule.value ? planForm.commission_rule_id : null,
    salary_amount_minor: needsSalary.value ? Math.round(Number(planForm.salary_amount_major) * 100) : null,
    salary_currency: needsSalary.value ? planForm.salary_currency.toUpperCase() : null,
    salary_period: needsSalary.value ? planForm.salary_period : null,
    salary_payout_day: needsSalary.value && planForm.salary_payout_day !== '' ? Number(planForm.salary_payout_day) : null,
    effective_from: planForm.effective_from,
    effective_to: planForm.effective_to === '' ? null : planForm.effective_to,
    change_reason: planForm.change_reason,
  };
  try {
    if (planEditing.value === null) {
      await store.createPlan(payload);
      await announce('Compensation plan draft created.');
    } else {
      await store.updatePlanDraft(planEditing.value, payload);
      await announce('Compensation plan draft updated.');
    }
    planModalOpen.value = false;
    restoreFocus();
    await store.fetchPlans();
  } catch (err) {
    applyApiError(err, planErrors, 'The compensation plan could not be saved.');
  } finally {
    savingPlan.value = false;
  }
}

/* ------------------------------------------------------------------ transitions */

const actionError = ref<string | null>(null);
const confirming = ref<PlanTransition | null>(null);
const confirmTarget = ref<CompensationPlan | null>(null);
const confirmReason = ref('');
const acknowledgePreview = ref(false);
const busy = ref(false);

const verbTitle: Record<PlanTransition, string> = {
  submit: 'Submit for approval',
  approve: 'Approve compensation plan',
  reject: 'Reject compensation plan',
  cancel: 'Cancel compensation plan',
};

const verbDescription: Record<PlanTransition, string> = {
  submit: 'The plan moves to Pending approval. A different HR approver must approve it — the person who submits a compensation change can never approve it.',
  approve: 'Approval requires a fresh step-up verification and a different approver from the submitter. The server decides whether the plan becomes Active or Scheduled and supersedes any incumbent.',
  reject: 'Rejection returns the plan to Rejected. Any plan that is currently active is left untouched.',
  cancel: 'Only a draft or scheduled plan can be cancelled. An active plan is never cancelled here.',
};

/** A backdated plan needs the acknowledged, server-built impact preview before approval (F8). */
const requiresPreview = computed(
  () => confirming.value === 'approve' && confirmTarget.value?.is_backdated === true,
);

function openConfirm(verb: PlanTransition, plan: CompensationPlan): void {
  rememberFocus();
  confirming.value = verb;
  confirmTarget.value = plan;
  confirmReason.value = '';
  acknowledgePreview.value = false;
  actionError.value = null;
}

function closeConfirm(): void {
  confirming.value = null;
  confirmTarget.value = null;
  actionError.value = null;
  restoreFocus();
}

async function confirm(): Promise<void> {
  if (confirming.value === null || confirmTarget.value === null || busy.value) return;
  if (confirmReason.value.trim() === '') {
    actionError.value = 'A reason is required.';
    return;
  }
  if (requiresPreview.value && !acknowledgePreview.value) {
    actionError.value = 'Acknowledge the impact preview before approving a backdated change.';
    return;
  }
  busy.value = true;
  actionError.value = null;
  const verb = confirming.value;
  try {
    const updated = await store.transition(confirmTarget.value.id, verb, {
      change_reason: confirmReason.value,
      ...(verb === 'approve' ? { acknowledge_impact_preview: acknowledgePreview.value } : {}),
    });
    confirming.value = null;
    confirmTarget.value = null;
    restoreFocus();
    if (detail.value !== null) detail.value = updated;
    await announce(`Compensation plan is now ${updated.status_label}.`);
    await store.fetchPlans();
  } catch (err) {
    actionError.value = transitionMessage(err, verb);
  } finally {
    busy.value = false;
  }
}

/** Map the server's safe envelope to screen copy. Never renders SQLSTATE/constraint/class detail. */
function transitionMessage(err: unknown, verb: PlanTransition): string {
  if (!axios.isAxiosError(err) || !err.apiError) return 'Something went wrong.';
  switch (err.apiError.code) {
    case 'step_up_required':
    case 'approval_requires_fresh_step_up':
      return 'Approving a compensation change requires a fresh step-up verification. Verify again, then approve.';
    case 'maker_checker_violation':
      return 'The person who submitted a compensation change cannot approve it. A different HR approver must approve this plan.';
    case 'backdated_approval_requires_impact_preview':
      return 'A backdated approval requires the impact preview to be acknowledged.';
    case 'invalid_state_transition':
      return `This plan can no longer be ${verb}ed — its status changed. Reload and try again.`;
    case 'compensation_plan_overlap':
      return 'This plan overlaps an existing active or scheduled plan for the same staff member.';
    default:
      return err.apiError.message;
  }
}

/**
 * Field errors land on their controls; anything else becomes one safe summary message. A
 * server-owned field the request refused is reported against its own field by the backend.
 */
function applyApiError(err: unknown, target: Record<string, string[]>, fallback: string): void {
  if (axios.isAxiosError(err) && err.apiError) {
    Object.assign(target, err.apiError.fields);
    actionError.value = Object.keys(err.apiError.fields).length > 0 ? err.apiError.message : (err.apiError.message ?? fallback);
    return;
  }
  actionError.value = fallback;
}
</script>

<template>
  <section class="p-4 md:p-6">
    <!-- `text-heading` (not `text-brand-deep`) — the heading token is the adaptive one; brand-deep is
         deliberately NOT overridden in dark mode because it is the CTA-on-orange colour (ADR-009). -->
    <h1 class="font-display text-2xl font-bold text-heading">
      Compensation
    </h1>
    <p class="mt-1 max-w-3xl text-sm text-text-muted">
      Compensation plans and commission rules for staff in your branch. Everything here is a
      configured term that will apply from its effective date — no amounts are calculated here.
    </p>

    <!-- Async result announcement; focus moves here after a mutation succeeds. -->
    <p
      ref="statusRegion"
      role="status"
      tabindex="-1"
      data-testid="comp-status"
      class="mt-2 text-sm font-medium text-success focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {{ statusMessage }}
    </p>

    <div
      v-if="!canView"
      data-testid="no-permission"
      class="mt-6"
    >
      <SvCard padding="md">
        <p class="text-sm text-text-muted">
          You do not have access to compensation configuration.
        </p>
      </SvCard>
    </div>

    <template v-else>
      <div class="mt-4 flex flex-wrap gap-2">
        <SvButton
          v-if="canCreate"
          data-testid="open-rule-create"
          variant="secondary"
          @click="openRuleCreate"
        >
          New commission rule
        </SvButton>
        <SvButton
          v-if="canCreate"
          data-testid="open-plan-create"
          @click="openPlanCreate"
        >
          New compensation plan
        </SvButton>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <SvSelect
          id="comp-status-filter"
          label="Status"
          :model-value="store.filterStatus"
          :options="statusFilterOptions"
          @update:model-value="(store.filterStatus = $event), reload()"
        />
        <SvSelect
          id="comp-staff-filter"
          label="Staff member"
          :model-value="store.filterStaffProfile"
          :options="staffFilterOptions"
          @update:model-value="(store.filterStaffProfile = $event), reload()"
        />
        <SvSelect
          id="comp-model-filter"
          label="Compensation model"
          :model-value="store.filterModel"
          :options="modelFilterOptions"
          @update:model-value="(store.filterModel = $event), reload()"
        />
      </div>

      <SvStateBoundary
        class="mt-6"
        :state="boundaryState"
        :error-message="store.error ?? undefined"
        empty-message="No compensation plans match this filter."
        @retry="store.fetchPlans()"
      >
        <ul
          class="mt-2 flex flex-col gap-3"
          aria-label="Compensation plans"
        >
          <li
            v-for="plan in store.plans"
            :key="plan.id"
            data-testid="plan-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="flex flex-wrap items-center gap-2 font-semibold text-text">
                    <span class="break-words">{{ plan.staff_display_name ?? 'Staff member' }}</span>
                    <span
                      class="rounded-control px-2 py-0.5 text-xs font-medium"
                      :class="badgeClass(plan.status)"
                      data-testid="plan-status"
                    >{{ plan.status_label }}</span>
                    <span
                      v-if="plan.is_backdated"
                      data-testid="plan-backdated"
                      class="rounded-control bg-warning/15 px-2 py-0.5 text-xs font-medium text-text"
                    >Backdated</span>
                    <span
                      v-if="plan.status === 'pending_approval'"
                      class="rounded-control bg-cream px-2 py-0.5 text-xs text-brand-deep"
                    >Awaiting a different approver</span>
                  </p>
                  <p class="mt-1 text-sm text-text-muted">
                    {{ plan.compensation_model_label }} · {{ salaryTerms(plan) }} · {{ ruleTerms(plan) }}
                  </p>
                  <p class="mt-1 text-xs text-text-muted">
                    {{ preferredFeeCopy(plan) }}
                  </p>
                  <p class="mt-1 text-xs text-text-muted">
                    Effective from {{ plan.effective_from }}<span v-if="plan.effective_to"> to {{ plan.effective_to }}</span>
                  </p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <SvButton
                    variant="ghost"
                    :data-testid="`view-${plan.id}`"
                    @click="openDetail(plan)"
                  >
                    View
                  </SvButton>
                  <SvButton
                    v-if="canUpdateDraft && plan.capabilities.can_update_draft"
                    variant="secondary"
                    :data-testid="`edit-${plan.id}`"
                    @click="openPlanEdit(plan)"
                  >
                    Edit draft
                  </SvButton>
                  <SvButton
                    v-if="canSubmit && plan.capabilities.can_submit"
                    variant="secondary"
                    :data-testid="`submit-${plan.id}`"
                    @click="openConfirm('submit', plan)"
                  >
                    Submit
                  </SvButton>
                  <SvButton
                    v-if="canApprove && plan.capabilities.can_approve"
                    :data-testid="`approve-${plan.id}`"
                    @click="openConfirm('approve', plan)"
                  >
                    Approve
                  </SvButton>
                  <SvButton
                    v-if="canReject && plan.capabilities.can_reject"
                    variant="secondary"
                    :data-testid="`reject-${plan.id}`"
                    @click="openConfirm('reject', plan)"
                  >
                    Reject
                  </SvButton>
                  <SvButton
                    v-if="canCancel && plan.capabilities.can_cancel"
                    variant="destructive"
                    :data-testid="`cancel-${plan.id}`"
                    @click="openConfirm('cancel', plan)"
                  >
                    Cancel
                  </SvButton>
                  <span
                    v-if="plan.capabilities.is_terminal"
                    class="self-center text-xs text-text-muted"
                  >Read-only</span>
                </div>
              </div>
            </SvCard>
          </li>
        </ul>
      </SvStateBoundary>

      <!-- Draft commission rules available to a plan; editable only while draft (F7). -->
      <section
        v-if="canCreate && store.rules.length > 0"
        class="mt-8"
        aria-labelledby="comp-rules-heading"
      >
        <h2
          id="comp-rules-heading"
          class="font-display text-lg font-bold text-heading"
        >
          Commission rules
        </h2>
        <ul class="mt-3 flex flex-col gap-2">
          <li
            v-for="rule in store.rules"
            :key="rule.id"
            data-testid="rule-row"
          >
            <SvCard padding="sm">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-text">
                  {{ rule.calculation_type === 'percentage' ? `${((rule.percentage_basis_points ?? 0) / 100).toFixed(2)}%` : money(rule.fixed_amount_minor, rule.currency) }}
                  <span class="ml-2 text-text-muted">{{ rule.status_label }} · from {{ rule.effective_from }}</span>
                </p>
                <SvButton
                  v-if="canUpdateDraft && rule.is_editable"
                  variant="ghost"
                  :data-testid="`edit-rule-${rule.id}`"
                  @click="openRuleEdit(rule.id)"
                >
                  Edit draft
                </SvButton>
              </div>
            </SvCard>
          </li>
        </ul>
      </section>
    </template>

    <!-- ------------------------------------------------------------------ detail + history -->
    <SvModal
      :open="detail !== null"
      title="Compensation plan"
      description="Configured terms and the append-only change history for this plan."
      @close="closeDetail"
    >
      <div
        v-if="detail"
        class="flex flex-col gap-4"
      >
        <dl class="grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-text-muted">
              Staff member
            </dt>
            <dd class="text-sm text-text">
              {{ detail.staff_display_name ?? '—' }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Status
            </dt>
            <dd class="text-sm text-text">
              {{ detail.status_label }}
              <span v-if="detail.is_backdated"> · Backdated</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Compensation model
            </dt>
            <dd class="text-sm text-text">
              {{ detail.compensation_model_label }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Salary terms
            </dt>
            <dd class="text-sm text-text">
              {{ salaryTerms(detail) }}
              <span v-if="detail.salary_payout_day"> · payout day {{ detail.salary_payout_day }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Commission rule
            </dt>
            <dd class="text-sm text-text">
              {{ ruleTerms(detail) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Preferred-personnel fee
            </dt>
            <dd class="text-sm text-text">
              {{ preferredFeeCopy(detail) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Effective window
            </dt>
            <dd class="text-sm text-text">
              {{ detail.effective_from }}<span v-if="detail.effective_to"> → {{ detail.effective_to }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-xs text-text-muted">
              Reference
            </dt>
            <dd class="break-all text-sm text-text">
              {{ detail.id }}
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-text-muted">
              Change reason
            </dt>
            <dd class="text-sm text-text">
              {{ detail.change_reason ?? '—' }}
            </dd>
          </div>
        </dl>

        <section aria-labelledby="comp-history-heading">
          <h3
            id="comp-history-heading"
            class="text-sm font-semibold text-text"
          >
            History
          </h3>
          <p
            v-if="!canViewHistory"
            class="mt-1 text-sm text-text-muted"
          >
            You do not have access to the change history.
          </p>
          <p
            v-else-if="store.historyLoading"
            role="status"
            class="mt-1 text-sm text-text-muted"
          >
            Loading history…
          </p>
          <p
            v-else-if="store.history.length === 0"
            class="mt-1 text-sm text-text-muted"
            data-testid="history-empty"
          >
            No history recorded yet.
          </p>
          <ol
            v-else
            class="mt-2 flex flex-col gap-2"
          >
            <li
              v-for="event in store.history"
              :key="event.id"
              data-testid="history-event"
              class="border-l-2 border-border pl-3 text-sm"
            >
              <p class="text-text">
                {{ event.event_label }}
                <span
                  v-if="event.was_backdated"
                  class="ml-1 rounded-control bg-warning/15 px-1.5 py-0.5 text-xs font-medium text-text"
                >Backdated approval</span>
              </p>
              <p class="text-xs text-text-muted">
                {{ event.occurred_at }} · {{ event.actor_display_name ?? 'System' }}
              </p>
              <p
                v-if="event.change_reason"
                class="text-xs text-text-muted"
              >
                {{ event.change_reason }}
              </p>
            </li>
          </ol>
        </section>

        <div class="flex justify-end">
          <SvButton
            variant="secondary"
            @click="closeDetail"
          >
            Close
          </SvButton>
        </div>
      </div>
    </SvModal>

    <!-- ------------------------------------------------------------------ commission-rule draft -->
    <SvModal
      :open="ruleModalOpen"
      :title="ruleEditing === null ? 'New commission rule' : 'Edit commission rule draft'"
      description="Percentage and fixed terms are mutually exclusive. A rule is only editable while it is a draft."
      @close="closeRuleModal"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="saveRule"
      >
        <SvSelect
          id="comp-rule-type"
          label="Calculation type"
          :model-value="ruleForm.calculation_type"
          :options="[...COMMISSION_CALCULATION_TYPES]"
          :errors="ruleErrors.calculation_type"
          required
          @update:model-value="ruleForm.calculation_type = $event"
        />
        <SvInput
          v-if="isPercentage"
          id="comp-rule-bp"
          label="Percentage (basis points, 0–10000)"
          type="number"
          :model-value="ruleForm.percentage_basis_points"
          :errors="ruleErrors.percentage_basis_points"
          hint="10000 basis points = 100%."
          required
          @update:model-value="ruleForm.percentage_basis_points = $event"
        />
        <div
          v-else
          class="grid gap-4 sm:grid-cols-2"
        >
          <SvInput
            id="comp-rule-fixed"
            label="Fixed amount (major units)"
            type="number"
            :model-value="ruleForm.fixed_amount_major"
            :errors="ruleErrors.fixed_amount_minor"
            required
            @update:model-value="ruleForm.fixed_amount_major = $event"
          />
          <SvInput
            id="comp-rule-currency"
            label="Currency"
            :model-value="ruleForm.currency"
            :errors="ruleErrors.currency"
            required
            @update:model-value="ruleForm.currency = $event"
          />
        </div>
        <SvSelect
          id="comp-rule-basis"
          label="Commission basis"
          :model-value="ruleForm.calculation_basis"
          :options="[...COMMISSION_CALCULATION_BASES]"
          :errors="ruleErrors.calculation_basis"
          required
          @update:model-value="ruleForm.calculation_basis = $event"
        />
        <SvSelect
          id="comp-rule-applies-to"
          label="Applies to"
          :model-value="ruleForm.applies_to"
          :options="[...COMMISSION_APPLIES_TO]"
          :errors="ruleErrors.applies_to"
          required
          @update:model-value="ruleForm.applies_to = $event"
        />
        <SvInput
          v-if="ruleNeedsCategory"
          id="comp-rule-category"
          label="Service category reference"
          :model-value="ruleForm.service_category_id"
          :errors="ruleErrors.service_category_id"
          hint="The 26-character service-category identifier this rule is scoped to."
          required
          @update:model-value="ruleForm.service_category_id = $event"
        />

        <div class="flex items-start gap-2">
          <input
            id="comp-rule-preferred-fee"
            v-model="ruleForm.applies_to_preferred_personnel_fee"
            type="checkbox"
            class="mt-1 h-5 w-5 rounded-control border-border text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
          <label
            for="comp-rule-preferred-fee"
            class="text-sm text-text"
          >
            Preferred-personnel fee included in commission basis
            <span class="block text-xs text-text-muted">
              Leave unticked to exclude the preferred-personnel fee from the commission basis.
            </span>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <SvInput
            id="comp-rule-from"
            label="Effective from"
            type="date"
            :model-value="ruleForm.effective_from"
            :errors="ruleErrors.effective_from"
            required
            @update:model-value="ruleForm.effective_from = $event"
          />
          <SvInput
            id="comp-rule-to"
            label="Effective to (optional)"
            type="date"
            :model-value="ruleForm.effective_to"
            :errors="ruleErrors.effective_to"
            @update:model-value="ruleForm.effective_to = $event"
          />
        </div>
        <SvTextarea
          id="comp-rule-reason"
          label="Change reason"
          :model-value="ruleForm.change_reason"
          :errors="ruleErrors.change_reason"
          required
          @update:model-value="ruleForm.change_reason = $event"
        />

        <p
          v-if="actionError"
          data-testid="rule-error"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="closeRuleModal"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            data-testid="save-rule"
            :loading="savingRule"
          >
            Save draft
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- ------------------------------------------------------------------ compensation-plan draft -->
    <SvModal
      :open="planModalOpen"
      :title="planEditing === null ? 'New compensation plan' : 'Edit compensation plan draft'"
      description="A salary only plan carries no commission rule. A commission only plan carries no salary. Salary plus commission requires both."
      @close="closePlanModal"
    >
      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="savePlan"
      >
        <SvSelect
          id="comp-plan-staff"
          label="Staff member"
          :model-value="planForm.staff_profile_id"
          :options="staffOptions"
          :errors="planErrors.staff_profile_id"
          placeholder="Select a staff member"
          :disabled="planEditing !== null"
          required
          @update:model-value="planForm.staff_profile_id = $event"
        />
        <SvSelect
          id="comp-plan-model"
          label="Compensation model"
          :model-value="planForm.compensation_model"
          :options="[...COMPENSATION_MODELS]"
          :errors="planErrors.compensation_model"
          required
          @update:model-value="planForm.compensation_model = $event"
        />

        <template v-if="needsSalary">
          <div class="grid gap-4 sm:grid-cols-2">
            <SvInput
              id="comp-plan-salary"
              label="Salary amount (major units)"
              type="number"
              :model-value="planForm.salary_amount_major"
              :errors="planErrors.salary_amount_minor"
              required
              @update:model-value="planForm.salary_amount_major = $event"
            />
            <SvInput
              id="comp-plan-currency"
              label="Salary currency"
              :model-value="planForm.salary_currency"
              :errors="planErrors.salary_currency"
              required
              @update:model-value="planForm.salary_currency = $event"
            />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <SvSelect
              id="comp-plan-period"
              label="Salary period"
              :model-value="planForm.salary_period"
              :options="[...SALARY_PERIODS]"
              :errors="planErrors.salary_period"
              required
              @update:model-value="planForm.salary_period = $event"
            />
            <SvInput
              id="comp-plan-payout-day"
              label="Salary payout day (optional)"
              type="number"
              :model-value="planForm.salary_payout_day"
              :errors="planErrors.salary_payout_day"
              hint="Day of the month, 1–31."
              @update:model-value="planForm.salary_payout_day = $event"
            />
          </div>
        </template>

        <SvSelect
          v-if="needsRule"
          id="comp-plan-rule"
          label="Commission rule"
          :model-value="planForm.commission_rule_id"
          :options="draftRuleOptions"
          :errors="planErrors.commission_rule_id"
          required
          @update:model-value="planForm.commission_rule_id = $event"
        />
        <p
          v-else
          class="text-xs text-text-muted"
          data-testid="no-rule-hint"
        >
          A salary only plan cannot reference a commission rule.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
          <SvInput
            id="comp-plan-from"
            label="Effective from"
            type="date"
            :model-value="planForm.effective_from"
            :errors="planErrors.effective_from"
            required
            @update:model-value="planForm.effective_from = $event"
          />
          <SvInput
            id="comp-plan-to"
            label="Effective to (optional)"
            type="date"
            :model-value="planForm.effective_to"
            :errors="planErrors.effective_to"
            @update:model-value="planForm.effective_to = $event"
          />
        </div>
        <SvTextarea
          id="comp-plan-reason"
          label="Change reason"
          :model-value="planForm.change_reason"
          :errors="planErrors.change_reason"
          required
          @update:model-value="planForm.change_reason = $event"
        />

        <p
          v-if="actionError"
          data-testid="plan-error"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="closePlanModal"
          >
            Cancel
          </SvButton>
          <SvButton
            type="submit"
            data-testid="save-plan"
            :loading="savingPlan"
          >
            Save draft
          </SvButton>
        </div>
      </form>
    </SvModal>

    <!-- ------------------------------------------------------------------ transition confirmation -->
    <SvModal
      :open="confirming !== null"
      :title="confirming ? verbTitle[confirming] : ''"
      :description="confirming ? verbDescription[confirming] : ''"
      @close="closeConfirm"
    >
      <div class="flex flex-col gap-4">
        <div
          v-if="requiresPreview"
          data-testid="backdated-warning"
          class="rounded-control border border-warning/40 bg-warning/10 p-3 text-sm text-text"
          role="note"
        >
          <p class="font-semibold">
            Backdated approval
          </p>
          <p class="mt-1">
            This plan takes effect on {{ confirmTarget?.effective_from }}, before today's business date.
            Approving it applies the terms from that earlier date and supersedes any incumbent plan.
          </p>
          <p class="mt-2">
            Impact preview: {{ confirmTarget?.staff_display_name ?? 'This staff member' }} moves to
            {{ confirmTarget?.compensation_model_label }} from {{ confirmTarget?.effective_from }}.
            The recorded impact preview is built by the server when you approve.
          </p>
          <div class="mt-2 flex items-start gap-2">
            <input
              id="comp-ack-preview"
              v-model="acknowledgePreview"
              type="checkbox"
              class="mt-1 h-5 w-5 rounded-control border-border text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
            <label
              for="comp-ack-preview"
              class="text-sm text-text"
            >
              I have reviewed the impact preview for this backdated change.
            </label>
          </div>
        </div>

        <SvTextarea
          id="comp-confirm-reason"
          label="Reason"
          :model-value="confirmReason"
          required
          @update:model-value="confirmReason = $event"
        />

        <p
          v-if="actionError"
          data-testid="confirm-error"
          class="text-sm text-error"
          role="alert"
        >
          {{ actionError }}
        </p>

        <div class="flex justify-end gap-2">
          <SvButton
            variant="secondary"
            @click="closeConfirm"
          >
            Keep as is
          </SvButton>
          <SvButton
            :variant="confirming === 'cancel' ? 'destructive' : 'primary'"
            data-testid="confirm-transition"
            :loading="busy"
            @click="confirm"
          >
            {{ confirming ? verbTitle[confirming] : '' }}
          </SvButton>
        </div>
      </div>
    </SvModal>
  </section>
</template>
