<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { applyExperience } from '@/design-system/experience';
import { SvIconCheck } from '@/design-system/icons';
import { useNotificationStore } from '@/stores/notificationStore';
import { useOnboardingStore } from '@/stores/onboardingStore';
import { formatMoney } from '@/utils/money';

/**
 * Mandatory Merchant Administrator setup (UI/UX plan §6.4.1; Scope §3.2).
 *
 * The device draft is convenience only. It contains no token, credential, permission or server
 * response, is namespaced to the signed-in user, can be discarded, and is deleted after the one
 * transactional server completion. Server validation, plan-price resolution, entitlement setup,
 * invitations and the pending_setup → active transition remain the authority.
 */
const router = useRouter();
const notifications = useNotificationStore();
const onboarding = useOnboardingStore();
const form = onboarding.form;

const steps = [
  { key: 'plan', label: 'Plan and fees' },
  { key: 'profile', label: 'Business profile' },
  { key: 'branch', label: 'First branch' },
  { key: 'team', label: 'Invite team' },
  { key: 'review', label: 'Review' },
] as const;

const step = ref(0);
const errors = ref<Record<string, string[]>>({});

const planPriceOptions = computed(() => onboarding.subscriptionPlans.flatMap((plan) =>
  plan.prices.map((price) => ({
    value: `${plan.id}:${price.id}`,
    label: `${plan.name} — ${formatMoney(price.amount_minor, price.currency)} / ${price.billing_interval}`,
  })),
));

const selectedPlanPrice = computed({
  get: () => form.subscription_plan_ulid && form.subscription_plan_price_ulid
    ? `${form.subscription_plan_ulid}:${form.subscription_plan_price_ulid}`
    : '',
  set: (value: string) => {
    const [planId = '', priceId = ''] = value.split(':');
    form.subscription_plan_ulid = planId;
    form.subscription_plan_price_ulid = priceId;
  },
});

const selectedPlan = computed(() => onboarding.subscriptionPlans.find(
  (plan) => plan.id === form.subscription_plan_ulid,
) ?? null);
const selectedPrice = computed(() => selectedPlan.value?.prices.find(
  (price) => price.id === form.subscription_plan_price_ulid,
) ?? null);
const isLastStep = computed(() => step.value === steps.length - 1);

const currentStepValid = computed<boolean>(() => {
  switch (step.value) {
    case 0:
      return form.service_fee_tier !== ''
        && form.subscription_plan_ulid !== ''
        && form.subscription_plan_price_ulid !== '';
    case 1:
      return form.business_category.trim() !== '' && form.contact_phone.trim() !== '';
    case 2:
      return form.branch.name.trim() !== '' && form.branch.code.trim() !== '';
    case 3:
      return form.branch_manager_email.trim() !== '' && form.hr_email.trim() !== '';
    default:
      return true;
  }
});

function fieldErrors(key: string): string[] {
  return errors.value[key] ?? [];
}
function next(): void {
  if (currentStepValid.value && step.value < steps.length - 1) step.value += 1;
}
function back(): void {
  if (step.value > 0) step.value -= 1;
}
function discardDraft(): void {
  onboarding.clearDraft();
  onboarding.reset();
  step.value = 0;
  notifications.addToast({ type: 'success', message: 'Saved setup details discarded.' });
}

async function submit(): Promise<void> {
  errors.value = {};
  try {
    await onboarding.complete();
    notifications.addToast({ type: 'success', message: 'Setup complete. Welcome to Servana!' });
    onboarding.reset();
    await router.push({ name: 'merchant.dashboard' });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        errors.value = err.apiError.fields;
        const keys = Object.keys(err.apiError.fields);
        if (keys.some((key) => key === 'service_fee_tier' || key.startsWith('subscription_'))) step.value = 0;
        else if (keys.some((key) => ['business_category', 'contact_phone', 'contact_email', 'receipt_display_name', 'address', 'town'].includes(key))) step.value = 1;
        else if (keys.some((key) => key.startsWith('branch.'))) step.value = 2;
        else if (keys.some((key) => key === 'branch_manager_email' || key === 'hr_email')) step.value = 3;
        notifications.addToast({ type: 'error', message: 'Please correct the highlighted fields.' });
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong. Please try again.' });
  }
}

/*
 * COR-UI08-UI12-VIS-001: this mandatory Merchant Administrator page renders BEFORE the shell, so it
 * opts into the refined experience itself (and hands it back on leave; the shell re-applies it).
 */
applyExperience('refined');
onBeforeUnmount(() => applyExperience(null));

onMounted(() => { void onboarding.load(); });
watch(() => onboarding.form, () => onboarding.saveDraft(), { deep: true });
</script>

<template>
  <!--
    COR-UI08-UI12-VIS-001 — a spacious guided journey (the Login reference's split composition,
    translated). A dark brand panel carries the identity, the journey's purpose and the five REAL
    milestones as a vertical stepper (done / current / ahead), plus the device-draft recovery path;
    the working panel beside it shows one step at a time with its counter and its own guidance.
    Every field, rule, validation route and the single transactional submission are unchanged.
  -->
  <main class="sv-r-setup mx-auto grid min-h-screen w-full max-w-6xl gap-5 p-4 md:p-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)] lg:p-8">
    <header class="sv-r-feature flex flex-col">
      <div class="flex items-center gap-3">
        <img
          :src="'/assets/brand/Logo.png'"
          alt="Servana by Citrus"
          class="h-11 w-11 rounded-xl bg-white object-contain p-1"
        >
        <p class="sv-r-feature__eyebrow">
          Merchant setup
        </p>
      </div>
      <h1 class="mt-6 font-display text-3xl font-extrabold leading-tight text-white">
        Set up your business
      </h1>
      <p class="sv-r-feature__hint mt-3 text-sm leading-6">
        Complete these required steps before opening the operational dashboard. Your trial start
        remains anchored to account creation, not to the day you finish setup.
      </p>

      <ol
        v-if="!(onboarding.loading || onboarding.loadError)"
        class="mt-8 flex flex-col gap-1"
        aria-label="Setup progress"
      >
        <li
          v-for="(item, index) in steps"
          :key="item.key"
          class="flex min-h-[44px] items-center gap-3 rounded-control px-2 py-1.5 text-sm"
          :class="index === step ? 'bg-white/10 font-semibold text-white' : index < step ? 'text-white/85' : 'text-white/70'"
          :aria-current="index === step ? 'step' : undefined"
        >
          <span
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold"
            :class="index < step ? 'bg-sv-accent text-sv-text-on-brand' : index === step ? 'bg-white text-sv-text-on-brand' : 'border border-white/30 text-white/80'"
          >
            <SvIconCheck
              v-if="index < step"
              aria-hidden="true"
              class="h-4 w-4"
            />
            <template v-else>{{ index + 1 }}</template>
          </span>
          {{ item.label }}
        </li>
      </ol>

      <div class="mt-auto pt-8 text-xs text-white/75">
        <span aria-live="polite">Progress is saved on this signed-in device.</span>
        <button
          type="button"
          class="sv-focus-ring mt-1 block min-h-[44px] rounded-control text-left font-semibold text-white underline"
          @click="discardDraft"
        >
          Discard saved details
        </button>
      </div>
    </header>

    <div class="flex min-w-0 flex-col">
      <SvStateBoundary
        v-if="onboarding.loading || onboarding.loadError"
        :state="onboarding.loading ? 'loading' : 'error'"
        :error-message="onboarding.loadError ?? undefined"
        @retry="onboarding.load()"
      />

      <section
        v-else
        class="sv-r-panel flex flex-1 flex-col md:p-8"
      >
        <p class="sv-r-eyebrow sv-r-eyebrow--dot sv-r-tone-warm">
          Step {{ step + 1 }} of {{ steps.length }}
        </p>

        <div
          v-show="step === 0"
          class="mt-3"
        >
          <h2 class="sv-r-title-lg">
            Choose plan and fee presentation
          </h2>
          <p class="sv-r-lede mt-2">
            Prices come from the currently effective Servana catalogue. Future plan changes take
            effect next cycle and are not prorated.
          </p>
          <div class="mt-6 flex flex-col gap-4">
            <SvSelect
              id="subscription_plan_price"
              v-model="selectedPlanPrice"
              label="Plan and billing interval"
              placeholder="Select a plan"
              :options="planPriceOptions"
              required
              :errors="[...fieldErrors('subscription_plan_ulid'), ...fieldErrors('subscription_plan_price_ulid')]"
            />
            <SvSelect
              id="service_fee_tier"
              v-model="form.service_fee_tier"
              label="Service fee tier"
              placeholder="Select a tier"
              :options="onboarding.serviceFeeTiers"
              required
              :errors="fieldErrors('service_fee_tier')"
            />
          </div>
        </div>

        <div
          v-show="step === 1"
          class="mt-3"
        >
          <h2 class="sv-r-title-lg">
            Business profile and billing contact
          </h2>
          <p class="sv-r-lede mt-2">
            The contact phone is also the default billing/M-Pesa phone when Wallet collections become available.
          </p>
          <div class="mt-6 grid gap-4 md:grid-cols-2">
            <SvTextInput
              id="business_category"
              v-model="form.business_category"
              label="Business category"
              placeholder="Salon, Barbershop, Spa…"
              required
              :errors="fieldErrors('business_category')"
            />
            <SvTextInput
              id="contact_phone"
              v-model="form.contact_phone"
              label="Billing and contact phone"
              placeholder="+254 7XX XXX XXX"
              required
              :errors="fieldErrors('contact_phone')"
            />
            <SvTextInput
              id="contact_email"
              v-model="form.contact_email"
              label="Contact email"
              type="email"
              :errors="fieldErrors('contact_email')"
            />
            <SvTextInput
              id="receipt_display_name"
              v-model="form.receipt_display_name"
              label="Receipt display name"
              :errors="fieldErrors('receipt_display_name')"
            />
            <SvTextInput
              id="town"
              v-model="form.town"
              label="Town"
              :errors="fieldErrors('town')"
            />
            <SvTextInput
              id="address"
              v-model="form.address"
              label="Address"
              :errors="fieldErrors('address')"
            />
          </div>
        </div>

        <div
          v-show="step === 2"
          class="mt-3"
        >
          <h2 class="sv-r-title-lg">
            Create your first branch
          </h2>
          <p class="sv-r-lede mt-2">
            The server checks the selected plan entitlement before completing setup.
          </p>
          <div class="mt-6 grid gap-4 md:grid-cols-2">
            <SvTextInput
              id="branch_name"
              v-model="form.branch.name"
              label="Branch name"
              placeholder="Main Branch"
              required
              :errors="fieldErrors('branch.name')"
            />
            <SvTextInput
              id="branch_code"
              v-model="form.branch.code"
              label="Branch code"
              placeholder="MAIN"
              required
              :errors="fieldErrors('branch.code')"
            />
            <SvTextInput
              id="branch_town"
              v-model="form.branch.town"
              label="Town"
              :errors="fieldErrors('branch.town')"
            />
            <SvTextInput
              id="branch_address"
              v-model="form.branch.address"
              label="Address"
              :errors="fieldErrors('branch.address')"
            />
            <SvTextInput
              id="branch_phone"
              v-model="form.branch.phone"
              label="Branch phone"
              :errors="fieldErrors('branch.phone')"
            />
            <SvTextInput
              id="branch_email"
              v-model="form.branch.email"
              label="Branch email"
              type="email"
              :errors="fieldErrors('branch.email')"
            />
          </div>
        </div>

        <div
          v-show="step === 3"
          class="mt-3"
        >
          <h2 class="sv-r-title-lg">
            Invite the initial owner team
          </h2>
          <p class="sv-r-lede mt-2">
            Merchant Administrators may invite only a Branch Manager and Human Resource user here.
            They receive Magic Link instructions; no password is created.
          </p>
          <div class="mt-6 flex flex-col gap-4">
            <SvTextInput
              id="branch_manager_email"
              v-model="form.branch_manager_email"
              label="Branch Manager email"
              type="email"
              required
              :errors="fieldErrors('branch_manager_email')"
            />
            <SvTextInput
              id="hr_email"
              v-model="form.hr_email"
              label="Human Resource email"
              type="email"
              required
              :errors="fieldErrors('hr_email')"
            />
          </div>
        </div>

        <div
          v-show="step === 4"
          class="mt-3"
        >
          <h2 class="sv-r-title-lg">
            Review and finish
          </h2>
          <p class="sv-r-lede mt-2">
            Finishing submits one transaction. A failure creates no partial merchant setup.
          </p>
          <dl class="sv-r-facts sv-r-facts--2 mt-6 text-sm">
            <div>
              <dt>
                Plan
              </dt><dd>
                {{ selectedPlan?.name ?? '—' }} · {{ selectedPrice ? `${formatMoney(selectedPrice.amount_minor, selectedPrice.currency)} / ${selectedPrice.billing_interval}` : '—' }}
              </dd>
            </div>
            <div>
              <dt>
                Service fee tier
              </dt><dd>
                {{ form.service_fee_tier || '—' }}
              </dd>
            </div>
            <div>
              <dt>
                Business
              </dt><dd>
                {{ form.receipt_display_name || form.business_category }}
              </dd>
            </div>
            <div>
              <dt>
                Billing phone
              </dt><dd>
                {{ form.contact_phone }}
              </dd>
            </div>
            <div>
              <dt>
                First branch
              </dt><dd>
                {{ form.branch.name }} ({{ form.branch.code }})
              </dd>
            </div>
            <div>
              <dt>
                Initial team
              </dt><dd>
                {{ form.branch_manager_email }} · {{ form.hr_email }}
              </dd>
            </div>
          </dl>
        </div>

        <div class="mt-auto flex items-center justify-between gap-3 border-t border-sv-border pt-6">
          <SvButton
            variant="secondary"
            :disabled="step === 0"
            @click="back"
          >
            Back
          </SvButton>
          <SvButton
            v-if="!isLastStep"
            :disabled="!currentStepValid"
            @click="next"
          >
            Continue
          </SvButton>
          <SvButton
            v-else
            :loading="onboarding.submitting"
            @click="submit"
          >
            Finish setup
          </SvButton>
        </div>
      </section>
    </div>
  </main>
</template>