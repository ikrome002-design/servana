<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import { useNotificationStore } from '@/stores/notificationStore';
import { useOnboardingStore } from '@/stores/onboardingStore';

// First-time setup wizard (Scope §3.2 steps 1–7). A 4-step stepper collects the
// tier, profile, first branch, and the initial Branch + HR emails, then submits
// ONE transactional payload. The server enforces step completion; the client
// validation here is UX only. No KYC / Super Admin approval exists anywhere.
const router = useRouter();
const notifications = useNotificationStore();
const onboarding = useOnboardingStore();
const form = onboarding.form;

const steps = [
  { key: 'tier', label: 'Service fee tier' },
  { key: 'profile', label: 'Business profile' },
  { key: 'branch', label: 'First branch' },
  { key: 'staff', label: 'Invite team' },
] as const;

const step = ref(0);
const errors = ref<Record<string, string[]>>({});

const tierOptions = [
  { value: 'customer_centric', label: 'Customer Centric — client pays only the service price' },
  { value: 'split_tier', label: 'Split Tier — client covers half the platform fee' },
  { value: 'business_centric', label: 'Business Centric — client covers the full platform fee' },
];

const isLastStep = computed(() => step.value === steps.length - 1);

// Client-side step gating (UX only; the server enforces completion too).
const currentStepValid = computed<boolean>(() => {
  const f = form;
  switch (step.value) {
    case 0:
      return f.service_fee_tier !== '';
    case 1:
      return f.business_category.trim() !== '' && f.contact_phone.trim() !== '';
    case 2:
      return f.branch.name.trim() !== '' && f.branch.code.trim() !== '';
    case 3:
      return f.branch_manager_email.trim() !== '' && f.hr_email.trim() !== '';
    default:
      return true;
  }
});

function fieldErrors(key: string): string[] {
  return errors.value[key] ?? [];
}

function next(): void {
  if (!currentStepValid.value) return;
  if (step.value < steps.length - 1) step.value += 1;
}

function back(): void {
  if (step.value > 0) step.value -= 1;
}

async function submit(): Promise<void> {
  errors.value = {};
  try {
    await onboarding.complete();
    notifications.addToast({ type: 'success', message: 'Setup complete. Welcome to Servana!' });
    onboarding.reset();
    await router.push({ name: 'merchant.landing' });
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      if (err.apiError.code === 'validation_failed') {
        errors.value = err.apiError.fields;
        // Jump back to the earliest step that has an error.
        if (fieldErrors('service_fee_tier').length) step.value = 0;
        else if (
          fieldErrors('business_category').length ||
          fieldErrors('contact_phone').length
        ) {
          step.value = 1;
        } else if (Object.keys(err.apiError.fields).some((k) => k.startsWith('branch'))) {
          step.value = 2;
        }
        notifications.addToast({ type: 'error', message: 'Please correct the highlighted fields.' });
        return;
      }
      notifications.addToast({ type: 'error', message: err.apiError.message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong. Please try again.' });
  }
}
</script>

<template>
  <section class="mx-auto w-full max-w-2xl p-4 md:p-6">
    <h1 class="font-display text-2xl font-extrabold text-heading">
      Set up your business
    </h1>
    <p class="mt-1 text-sm text-text-muted">
      A few quick steps to get your account ready.
    </p>

    <!-- Stepper -->
    <ol
      class="mt-6 flex flex-wrap gap-2"
      aria-label="Setup progress"
    >
      <li
        v-for="(s, i) in steps"
        :key="s.key"
        class="flex items-center gap-2 rounded-control border px-3 py-2 text-sm"
        :class="i === step
          ? 'border-primary bg-surface-alt font-semibold text-heading'
          : 'border-border text-text-muted'"
        :aria-current="i === step ? 'step' : undefined"
      >
        <span
          class="flex h-6 w-6 items-center justify-center rounded-full text-xs"
          :class="i <= step ? 'bg-primary text-brand-deep' : 'bg-surface-alt text-text-muted'"
        >{{ i + 1 }}</span>
        {{ s.label }}
      </li>
    </ol>

    <SvCard
      as="div"
      padding="lg"
      class="mt-6"
    >
      <!-- Step 1: service fee tier -->
      <div v-show="step === 0">
        <h2 class="font-display text-lg font-bold text-heading">
          Choose your service fee tier
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          This affects how the Citrus platform fee appears on client invoices. You can
          change it later.
        </p>
        <div class="mt-4">
          <SvSelect
            id="service_fee_tier"
            v-model="form.service_fee_tier"
            label="Service fee tier"
            placeholder="Select a tier"
            :options="tierOptions"
            required
            :errors="fieldErrors('service_fee_tier')"
          />
        </div>
      </div>

      <!-- Step 2: business profile -->
      <div v-show="step === 1">
        <h2 class="font-display text-lg font-bold text-heading">
          Business profile
        </h2>
        <div class="mt-4 flex flex-col gap-4">
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
            label="Contact phone"
            placeholder="+254 7XX XXX XXX"
            required
            :errors="fieldErrors('contact_phone')"
          />
          <SvTextInput
            id="contact_email"
            v-model="form.contact_email"
            label="Contact email"
            type="email"
            placeholder="info@business.co.ke"
            :errors="fieldErrors('contact_email')"
          />
          <SvTextInput
            id="receipt_display_name"
            v-model="form.receipt_display_name"
            label="Receipt display name"
            help="Shown on client invoices and receipts. Defaults to your business name."
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

      <!-- Step 3: first branch -->
      <div v-show="step === 2">
        <h2 class="font-display text-lg font-bold text-heading">
          Your first branch
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          Every business needs at least one branch. You can add more later.
        </p>
        <div class="mt-4 flex flex-col gap-4">
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
            help="A short code (letters/numbers) used on invoice numbers."
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
            id="branch_phone"
            v-model="form.branch.phone"
            label="Branch phone"
            :errors="fieldErrors('branch.phone')"
          />
        </div>
      </div>

      <!-- Step 4: invite initial staff -->
      <div v-show="step === 3">
        <h2 class="font-display text-lg font-bold text-heading">
          Invite your team
        </h2>
        <p class="mt-1 text-sm text-text-muted">
          Add the email addresses for your Branch Manager and HR. They’ll be assigned to
          your branch and emailed a welcome message explaining Magic Link sign-in.
        </p>
        <div class="mt-4 flex flex-col gap-4">
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
            label="HR email"
            type="email"
            required
            :errors="fieldErrors('hr_email')"
          />
        </div>
      </div>

      <!-- Navigation -->
      <div class="mt-8 flex items-center justify-between">
        <SvButton
          variant="secondary"
          :disabled="step === 0"
          @click="back"
        >
          Back
        </SvButton>

        <SvButton
          v-if="!isLastStep"
          variant="primary"
          :disabled="!currentStepValid"
          @click="next"
        >
          Continue
        </SvButton>
        <SvButton
          v-else
          variant="primary"
          :disabled="!currentStepValid"
          :loading="onboarding.submitting"
          @click="submit"
        >
          Finish setup
        </SvButton>
      </div>
    </SvCard>
  </section>
</template>
