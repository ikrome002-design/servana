<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
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

onMounted(() => { void onboarding.load(); });
watch(() => onboarding.form, () => onboarding.saveDraft(), { deep: true });
</script>

<template>
  <main class="mx-auto w-full max-w-3xl p-4 md:p-6">
    <header>
      <p class="text-sm font-semibold text-text-muted">Merchant setup</p>
      <h1 class="font-display text-2xl font-extrabold text-heading">Set up your business</h1>
      <p class="mt-1 text-sm text-text-muted">
        Complete these required steps before opening the operational dashboard. Your trial start
        remains anchored to account creation, not to the day you finish setup.
      </p>
    </header>

    <SvStateBoundary
      v-if="onboarding.loading || onboarding.loadError"
      class="mt-6"
      :state="onboarding.loading ? 'loading' : 'error'"
      :error-message="onboarding.loadError ?? undefined"
      @retry="onboarding.load()"
    />

    <template v-else>
      <ol class="mt-6 grid grid-cols-2 gap-2 md:grid-cols-5" aria-label="Setup progress">
        <li
          v-for="(item, index) in steps"
          :key="item.key"
          class="flex min-h-[44px] items-center gap-2 rounded-control border px-3 py-2 text-sm"
          :class="index === step ? 'border-primary bg-surface-alt font-semibold text-heading' : 'border-border text-text-muted'"
          :aria-current="index === step ? 'step' : undefined"
        >
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs text-brand-deep">{{ index + 1 }}</span>
          {{ item.label }}
        </li>
      </ol>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-text-muted">
        <span aria-live="polite">Progress is saved on this signed-in device.</span>
        <button type="button" class="sv-focus-ring min-h-[44px] rounded-control px-3 underline" @click="discardDraft">
          Discard saved details
        </button>
      </div>

      <SvCard as="section" padding="lg" class="mt-4">
        <div v-show="step === 0">
          <h2 class="font-display text-lg font-bold text-heading">Choose plan and fee presentation</h2>
          <p class="mt-1 text-sm text-text-muted">
            Prices come from the currently effective Servana catalogue. Future plan changes take
            effect next cycle and are not prorated.
          </p>
          <div class="mt-4 flex flex-col gap-4">
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

        <div v-show="step === 1">
          <h2 class="font-display text-lg font-bold text-heading">Business profile and billing contact</h2>
          <p class="mt-1 text-sm text-text-muted">The contact phone is also the default billing/M-Pesa phone when Wallet collections become available.</p>
          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <SvTextInput id="business_category" v-model="form.business_category" label="Business category" placeholder="Salon, Barbershop, Spa…" required :errors="fieldErrors('business_category')" />
            <SvTextInput id="contact_phone" v-model="form.contact_phone" label="Billing and contact phone" placeholder="+254 7XX XXX XXX" required :errors="fieldErrors('contact_phone')" />
            <SvTextInput id="contact_email" v-model="form.contact_email" label="Contact email" type="email" :errors="fieldErrors('contact_email')" />
            <SvTextInput id="receipt_display_name" v-model="form.receipt_display_name" label="Receipt display name" :errors="fieldErrors('receipt_display_name')" />
            <SvTextInput id="town" v-model="form.town" label="Town" :errors="fieldErrors('town')" />
            <SvTextInput id="address" v-model="form.address" label="Address" :errors="fieldErrors('address')" />
          </div>
        </div>

        <div v-show="step === 2">
          <h2 class="font-display text-lg font-bold text-heading">Create your first branch</h2>
          <p class="mt-1 text-sm text-text-muted">The server checks the selected plan entitlement before completing setup.</p>
          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <SvTextInput id="branch_name" v-model="form.branch.name" label="Branch name" placeholder="Main Branch" required :errors="fieldErrors('branch.name')" />
            <SvTextInput id="branch_code" v-model="form.branch.code" label="Branch code" placeholder="MAIN" required :errors="fieldErrors('branch.code')" />
            <SvTextInput id="branch_town" v-model="form.branch.town" label="Town" :errors="fieldErrors('branch.town')" />
            <SvTextInput id="branch_address" v-model="form.branch.address" label="Address" :errors="fieldErrors('branch.address')" />
            <SvTextInput id="branch_phone" v-model="form.branch.phone" label="Branch phone" :errors="fieldErrors('branch.phone')" />
            <SvTextInput id="branch_email" v-model="form.branch.email" label="Branch email" type="email" :errors="fieldErrors('branch.email')" />
          </div>
        </div>

        <div v-show="step === 3">
          <h2 class="font-display text-lg font-bold text-heading">Invite the initial owner team</h2>
          <p class="mt-1 text-sm text-text-muted">
            Merchant Administrators may invite only a Branch Manager and Human Resource user here.
            They receive Magic Link instructions; no password is created.
          </p>
          <div class="mt-4 flex flex-col gap-4">
            <SvTextInput id="branch_manager_email" v-model="form.branch_manager_email" label="Branch Manager email" type="email" required :errors="fieldErrors('branch_manager_email')" />
            <SvTextInput id="hr_email" v-model="form.hr_email" label="Human Resource email" type="email" required :errors="fieldErrors('hr_email')" />
          </div>
        </div>

        <div v-show="step === 4">
          <h2 class="font-display text-lg font-bold text-heading">Review and finish</h2>
          <p class="mt-1 text-sm text-text-muted">Finishing submits one transaction. A failure creates no partial merchant setup.</p>
          <dl class="mt-4 grid gap-4 text-sm md:grid-cols-2">
            <div><dt class="text-text-muted">Plan</dt><dd class="font-semibold text-heading">{{ selectedPlan?.name ?? '—' }} · {{ selectedPrice ? `${formatMoney(selectedPrice.amount_minor, selectedPrice.currency)} / ${selectedPrice.billing_interval}` : '—' }}</dd></div>
            <div><dt class="text-text-muted">Service fee tier</dt><dd class="font-semibold text-heading">{{ form.service_fee_tier || '—' }}</dd></div>
            <div><dt class="text-text-muted">Business</dt><dd class="font-semibold text-heading">{{ form.receipt_display_name || form.business_category }}</dd></div>
            <div><dt class="text-text-muted">Billing phone</dt><dd class="font-semibold text-heading">{{ form.contact_phone }}</dd></div>
            <div><dt class="text-text-muted">First branch</dt><dd class="font-semibold text-heading">{{ form.branch.name }} ({{ form.branch.code }})</dd></div>
            <div><dt class="text-text-muted">Initial team</dt><dd class="font-semibold text-heading">{{ form.branch_manager_email }} · {{ form.hr_email }}</dd></div>
          </dl>
        </div>

        <div class="mt-8 flex items-center justify-between gap-3">
          <SvButton variant="secondary" :disabled="step === 0" @click="back">Back</SvButton>
          <SvButton v-if="!isLastStep" :disabled="!currentStepValid" @click="next">Continue</SvButton>
          <SvButton v-else :loading="onboarding.submitting" @click="submit">Finish setup</SvButton>
        </div>
      </SvCard>
    </template>
  </main>
</template>
