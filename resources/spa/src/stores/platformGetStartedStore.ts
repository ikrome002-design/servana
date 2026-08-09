import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { components, paths } from '@/types/generated/api';

/**
 * Super Administrator Get Started (Phase UI-08, contract page §5.4.2).
 *
 * ## Completion is SERVER evidence, not a checkbox
 *
 * The Development Plan requires a guided companion with persisted completion, and the UI/UX plan
 * fixes the dependency order. The decisive rule is that a client-only boolean must never claim a
 * server-owned step is done: an administrator who ticked "billing mode configured" on one laptop
 * would otherwise see a configured platform that is not configured at all.
 *
 * So each step reports the evidence that actually proves it, read from the SHIPPED endpoints:
 *
 * ```text
 * 1 billing mode                     GET /platform/billing-settings   billing_mode is set
 * 2 plans, entitlements, prices      GET /platform/plans              an active plan with both
 * 3 trial / grace / overdue          GET /platform/billing-settings   a settings version exists
 * 4 preferred-personnel + SMS        GET /platform/preferred-personnel-fee-rules
 *                                    GET /platform/sms-billing-settings
 * 5 Wallet + R&E readiness           blocked by External Gate W — never completable
 * 6 registration monitoring review   GET /platform/registration-monitor + a human acknowledgement
 * 7 MFA                              GET /auth/mfa                    enrolled and confirmed
 * ```
 *
 * NO new endpoint, NO new permission and NO new table. Step 6 is the single training-only step the
 * contract permits a user to mark; it reuses the existing `getStartedStore` persistence and the
 * existing `review-registration-monitoring` item id, so dismissal, resume and reopen keep working
 * exactly as they already do for every other account.
 */
type BillingSettingsResponse = paths['/api/v1/platform/billing-settings']['get']['responses'][200]['content']['application/json'];
type PlansResponse = paths['/api/v1/platform/plans']['get']['responses'][200]['content']['application/json'];
type PreferredFeeResponse = paths['/api/v1/platform/preferred-personnel-fee-rules']['get']['responses'][200]['content']['application/json'];
type SmsSettingsResponse = paths['/api/v1/platform/sms-billing-settings']['get']['responses'][200]['content']['application/json'];
type RegistrationResponse = paths['/api/v1/platform/registration-monitor']['get']['responses'][200]['content']['application/json'];
type MfaResponse = paths['/api/v1/auth/mfa']['get']['responses'][200]['content']['application/json'];

export type SubscriptionPlan = components['schemas']['SubscriptionPlanResource'];

/**
 * A step is `complete` only on real evidence, `blocked_by_gate` when an external dependency makes
 * verification impossible, and `incomplete` otherwise. There is deliberately no "assumed" state.
 */
export type StepState = 'complete' | 'incomplete' | 'blocked_by_gate';

export interface GetStartedStep {
  /** Stable id — reuses the shipped checklist ids so existing persistence still applies. */
  id: string;
  order: number;
  label: string;
  /** What the platform owner must do. */
  description: string;
  state: StepState;
  /** The evidence sentence shown under the step. Always states WHY the state is what it is. */
  evidence: string;
  /** Route name to open next. Null when there is nothing to open. */
  routeName: string | null;
  /** The exact gate, when blocked. */
  gate: string | null;
  /** Only a training-only step may be marked complete by hand. */
  manuallyCompletable: boolean;
  /** A dependency that is not yet satisfied, shown as a warning rather than a hard block. */
  dependencyWarning: string | null;
}

export const EXTERNAL_GATE_W = 'external_gate_w';

const GATE_W_LABEL = 'External Gate W — Wallet by Citrus collections readiness (Plan §80.2)';

/** Narrow an unknown map the published contract widened to `string`. */
function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

export const usePlatformGetStartedStore = defineStore('platformGetStarted', () => {
  const billingSettings = ref<BillingSettingsResponse['data'] | null>(null);
  const plans = ref<SubscriptionPlan[]>([]);
  const preferredFeeRuleCount = ref(0);
  const smsRuleConfigured = ref(false);
  const registrationCount = ref(0);
  const mfaEnrolled = ref(false);
  const mfaConfirmed = ref(false);

  const loading = ref(false);
  const error = ref<string | null>(null);
  const lastRefreshed = ref<string | null>(null);

  /** Ids the user acknowledged for training-only steps — supplied by the caller's persistence. */
  const acknowledged = ref<ReadonlySet<string>>(new Set());

  let sequence = 0;
  const isCurrent = (token: number): boolean => token === sequence;

  function $reset(): void {
    billingSettings.value = null;
    plans.value = [];
    preferredFeeRuleCount.value = 0;
    smsRuleConfigured.value = false;
    registrationCount.value = 0;
    mfaEnrolled.value = false;
    mfaConfirmed.value = false;
    loading.value = false;
    error.value = null;
    lastRefreshed.value = null;
    acknowledged.value = new Set();
    sequence += 1;
  }

  function setAcknowledged(ids: readonly string[]): void {
    acknowledged.value = new Set(ids);
  }

  /**
   * Read every evidence source. `allSettled` is deliberate: one endpoint the user cannot read must
   * degrade THAT step to `incomplete`, not blank the whole guide — a platform owner who lacks the
   * SMS key still needs to see the other six steps.
   */
  async function load(): Promise<void> {
    const token = ++sequence;
    loading.value = true;
    error.value = null;

    const [settings, planList, preferred, sms, registrations, mfa] = await Promise.allSettled([
      apiClient.get<BillingSettingsResponse>('/platform/billing-settings'),
      apiClient.get<PlansResponse>('/platform/plans'),
      apiClient.get<PreferredFeeResponse>('/platform/preferred-personnel-fee-rules'),
      apiClient.get<SmsSettingsResponse>('/platform/sms-billing-settings'),
      apiClient.get<RegistrationResponse>('/platform/registration-monitor'),
      apiClient.get<MfaResponse>('/auth/mfa'),
    ]);

    if (!isCurrent(token)) return;

    billingSettings.value = settings.status === 'fulfilled' ? settings.value.data.data : null;
    plans.value = planList.status === 'fulfilled' ? planList.value.data.data : [];
    preferredFeeRuleCount.value =
      preferred.status === 'fulfilled' ? (preferred.value.data.meta?.total ?? 0) : 0;
    smsRuleConfigured.value =
      sms.status === 'fulfilled' && sms.value.data.data.current !== null;
    registrationCount.value =
      registrations.status === 'fulfilled' ? (registrations.value.data.meta?.total ?? 0) : 0;

    if (mfa.status === 'fulfilled') {
      const state = asRecord(asRecord(mfa.value.data.data).mfa);
      mfaEnrolled.value = state.enrolled === true || String(state.enrolled) === 'true';
      mfaConfirmed.value = state.confirmed === true || String(state.confirmed) === 'true';
    } else {
      mfaEnrolled.value = false;
      mfaConfirmed.value = false;
    }

    // Every source failing is a real error; a single denied source is not.
    const allFailed = [settings, planList, preferred, sms, registrations, mfa].every(
      (result) => result.status === 'rejected',
    );
    if (allFailed) error.value = 'Unable to load your platform setup progress.';

    loading.value = false;
    lastRefreshed.value = new Date().toISOString();
  }

  // -------------------------------------------------------------------------------------------
  // Derived evidence
  // -------------------------------------------------------------------------------------------

  const billingModeConfigured = computed(() => {
    const mode = billingSettings.value?.billing_mode;
    return typeof mode === 'string' && mode !== '';
  });

  const settingsVersionExists = computed(() => {
    const from = billingSettings.value?.effective_from;
    return typeof from === 'string' && from !== '';
  });

  const activePlansWithPriceAndEntitlement = computed(() =>
    plans.value.filter(
      (plan) =>
        plan.status === 'active'
        && Array.isArray(plan.prices) && plan.prices.length > 0
        && Array.isArray(plan.entitlements) && plan.entitlements.length > 0,
    ),
  );

  const commercialRulesConfigured = computed(
    () => preferredFeeRuleCount.value > 0 && smsRuleConfigured.value,
  );

  const steps = computed<GetStartedStep[]>(() => {
    const planCount = activePlansWithPriceAndEntitlement.value.length;
    const anyPlan = plans.value.length;

    return [
      {
        id: 'configure-billing-mode',
        order: 1,
        label: 'Configure the active billing mode',
        description: 'Choose how merchant subscriptions are charged, and the currency and interval that apply by default.',
        state: billingModeConfigured.value ? 'complete' : 'incomplete',
        evidence: billingModeConfigured.value
          ? `An effective billing-settings version is in force with mode "${String(billingSettings.value?.billing_mode)}".`
          : 'No effective billing-settings version defines a billing mode yet.',
        routeName: 'platform.billing-settings',
        gate: null,
        manuallyCompletable: false,
        dependencyWarning: null,
      },
      {
        id: 'configure-plans-entitlements',
        order: 2,
        label: 'Create and verify plan entitlements and effective prices',
        description: 'Every active plan needs its server-enforced entitlements and at least one effective price.',
        state: planCount > 0 ? 'complete' : 'incomplete',
        evidence: planCount > 0
          ? `${planCount} active plan(s) carry both an effective price and at least one entitlement.`
          : anyPlan > 0
            ? `${anyPlan} plan(s) exist, but none is active with both an effective price and an entitlement.`
            : 'No subscription plan has been created yet.',
        routeName: 'platform.billing-plans',
        gate: null,
        manuallyCompletable: false,
        dependencyWarning: billingModeConfigured.value
          ? null
          : 'Plan prices cannot be considered complete while no active billing mode and interval exist.',
      },
      {
        id: 'configure-free-period-grace',
        order: 3,
        label: 'Configure trial, grace, overdue and suspension thresholds',
        description: 'Set how long a trial runs, how long read-only grace lasts, and when an overdue account is suspended.',
        state: settingsVersionExists.value ? 'complete' : 'incomplete',
        evidence: settingsVersionExists.value
          ? `An effective settings version defines a ${String(billingSettings.value?.default_trial_days)}-day trial and ${String(billingSettings.value?.grace_days)} grace day(s).`
          : 'No effective settings version defines trial, grace, overdue or suspension behaviour.',
        routeName: 'platform.billing-settings',
        gate: null,
        manuallyCompletable: false,
        dependencyWarning: null,
      },
      {
        id: 'configure-preferred-personnel-fee',
        order: 4,
        label: 'Configure preferred-personnel fee and SMS billing rules',
        description: 'Both are effective-dated commercial rules that apply across every merchant.',
        state: commercialRulesConfigured.value ? 'complete' : 'incomplete',
        evidence: commercialRulesConfigured.value
          ? 'An effective preferred-personnel fee rule and an effective SMS billing rule are both in force.'
          : `Preferred-personnel fee rules: ${preferredFeeRuleCount.value}. SMS billing rule in force: ${smsRuleConfigured.value ? 'yes' : 'no'}.`,
        routeName: 'platform.billing-preferred-personnel-fees',
        gate: null,
        manuallyCompletable: false,
        dependencyWarning: null,
      },
      {
        /*
         * The gated step. It can never be completed — not by evidence, because none exists, and not
         * by hand, because clicking a checkbox would assert that an unreachable integration is
         * verified. That is precisely the false claim the contract forbids.
         */
        id: 'configure-mpesa',
        order: 5,
        label: 'Verify Wallet and Refer & Earn integration readiness',
        description: 'Machine-account connectivity, signed-webhook verification and reconciliation operations.',
        state: 'blocked_by_gate',
        evidence: `Cannot be verified: ${GATE_W_LABEL} is closed, so Servana holds no Wallet connectivity, webhook or reconciliation evidence to check.`,
        routeName: null,
        gate: EXTERNAL_GATE_W,
        manuallyCompletable: false,
        dependencyWarning: null,
      },
      {
        id: 'review-registration-monitoring',
        order: 6,
        label: 'Review merchant registration monitoring',
        description: 'Confirm you have seen how self-registrations arrive and where duplicate or abusive patterns surface.',
        state: acknowledged.value.has('review-registration-monitoring') ? 'complete' : 'incomplete',
        evidence: acknowledged.value.has('review-registration-monitoring')
          ? `You marked this reviewed. ${registrationCount.value} registration(s) are currently visible.`
          : `${registrationCount.value} registration(s) are currently visible. This step is a review, so you mark it complete yourself.`,
        routeName: 'platform.merchant-registrations',
        gate: null,
        // The one training-only step: the platform cannot observe "a human read this".
        manuallyCompletable: true,
        dependencyWarning: null,
      },
      {
        id: 'enroll-mfa',
        order: 7,
        label: 'Enrol and verify multi-factor authentication',
        description: 'Platform administration requires MFA on every privileged action.',
        state: mfaEnrolled.value && mfaConfirmed.value ? 'complete' : 'incomplete',
        evidence: mfaEnrolled.value && mfaConfirmed.value
          ? 'Your account is enrolled in MFA and the enrolment is confirmed.'
          : mfaEnrolled.value
            ? 'Your account is enrolled in MFA but the enrolment is not yet confirmed.'
            : 'Your account is not enrolled in MFA.',
        routeName: 'platform.account',
        gate: null,
        manuallyCompletable: false,
        dependencyWarning: null,
      },
    ];
  });

  /** Progress counts the steps that CAN be completed; a gated step is never counted against you. */
  const progress = computed(() => {
    const completable = steps.value.filter((step) => step.state !== 'blocked_by_gate');
    const complete = completable.filter((step) => step.state === 'complete').length;
    const total = completable.length;

    return {
      complete,
      total,
      blocked: steps.value.length - total,
      percent: total === 0 ? 0 : Math.round((complete / total) * 100),
    };
  });

  /** The next step to open — the first incomplete one that has somewhere to go. */
  const nextStep = computed<GetStartedStep | null>(
    () => steps.value.find((step) => step.state === 'incomplete' && step.routeName !== null) ?? null,
  );

  const allCompletableDone = computed(() => progress.value.total > 0 && progress.value.complete === progress.value.total);

  return {
    billingSettings,
    plans,
    preferredFeeRuleCount,
    smsRuleConfigured,
    registrationCount,
    mfaEnrolled,
    mfaConfirmed,
    loading,
    error,
    lastRefreshed,
    acknowledged,
    steps,
    progress,
    nextStep,
    allCompletableDone,
    $reset,
    setAcknowledged,
    load,
  };
});
