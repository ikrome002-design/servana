<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';
import { useForm } from '@/composables/useForm';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';
import { useNotificationStore } from '@/stores/notificationStore';

// Merchant Administrator self-registration (Scope §3.1/§3.2). No password, no
// KYC — the business owner registers, the tenant is created, and a Magic Link
// is emailed to continue. The success state is uniform (no account enumeration).
const notifications = useNotificationStore();
const route = useRoute();
const submitted = ref(false);

// Phase 21R-A referral capture (Plan §58A.1, §12.1 item 5). `?ref=SERVANA-XXXXX`
// pre-fills the field and is remembered as the capture channel; typing into the
// field afterwards makes it a manual entry. The code is OPTIONAL and a badly
// shaped one NEVER blocks submission — the server stores it as `invalid_format`
// evidence and simply never forwards it. No referrer identity is shown anywhere,
// because Servana does not hold one.
const referralFromUrl = ((): string => {
  const value = route.query.ref;
  return typeof value === 'string' ? value.trim() : '';
})();

const referralChannel = ref<'query_param' | 'manual_entry'>(
  referralFromUrl === '' ? 'manual_entry' : 'query_param',
);

const showReferralNotice = ref(referralFromUrl !== '');

const form = useForm<{
  owner_name: string;
  email: string;
  business_name: string;
  referral_code: string;
}>({
  owner_name: '',
  email: '',
  business_name: '',
  referral_code: referralFromUrl,
});

// Local shape hint only. It never gates submission (the field is not `required`
// and carries no validation error), so a referral the SPA does not recognise is
// still submitted and still becomes durable evidence server-side.
const referralLooksValid = computed(
  () => form.values.referral_code.trim() === '' || /^SERVANA-[A-Za-z0-9]{5,16}$/.test(form.values.referral_code.trim()),
);

const onReferralInput = (): void => {
  // Editing the pre-filled value means the user typed it, whatever the URL said.
  referralChannel.value = 'manual_entry';
  showReferralNotice.value = false;
};

const submit = form.handleSubmit(async (values) => {
  try {
    await primeCsrfCookie();

    const referralCode = values.referral_code.trim();

    await apiClient.post('/merchant-registration/self-register', {
      owner_name: values.owner_name,
      email: values.email,
      business_name: values.business_name,
      // Omit the referral fields entirely when no code was given, so an
      // unreferred registration is byte-identical to the pre-21R-A request.
      ...(referralCode === ''
        ? {}
        : { referral_code: referralCode, referral_channel: referralChannel.value }),
    });

    submitted.value = true;
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code, message } = err.apiError;
      if (code === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      if (code === 'rate_limited') {
        notifications.addToast({
          type: 'warning',
          message: 'Too many attempts. Please wait a moment and try again.',
        });
        return;
      }
      notifications.addToast({ type: 'error', message });
      return;
    }
    notifications.addToast({
      type: 'error',
      message: 'Something went wrong. Please try again.',
    });
  }
});
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md"
  >
    <template v-if="!submitted">
      <h1 class="font-display text-2xl font-extrabold text-heading">
        Create your business account
      </h1>
      <p class="mt-2 text-sm text-text-muted">
        Register your business on Servana. We’ll email you a secure sign-in link to
        continue — no password needed.
      </p>

      <form
        class="mt-6 flex flex-col gap-4"
        novalidate
        @submit.prevent="submit"
      >
        <SvTextInput
          id="owner_name"
          v-model="form.values.owner_name"
          label="Your name"
          placeholder="Paul Nderitu"
          required
          :errors="form.errors.owner_name"
        />
        <SvTextInput
          id="business_name"
          v-model="form.values.business_name"
          label="Business name"
          placeholder="Glow Salon &amp; Spa"
          required
          :errors="form.errors.business_name"
        />
        <SvTextInput
          id="email"
          v-model="form.values.email"
          label="Email address"
          type="email"
          placeholder="you@business.co.ke"
          required
          :errors="form.errors.email"
        />

        <div>
          <SvTextInput
            id="referral_code"
            v-model="form.values.referral_code"
            label="Referral code (optional)"
            placeholder="SERVANA-XXXXX"
            help="If someone referred you to Servana, enter their code. You can leave this blank."
            :errors="form.errors.referral_code"
            @update:model-value="onReferralInput"
          />
          <p
            v-if="showReferralNotice"
            class="mt-2 text-sm text-text-muted"
            data-testid="referral-applied-notice"
          >
            Referral code applied: {{ form.values.referral_code }}
            <button
              type="button"
              class="ml-2 font-semibold text-heading underline"
              data-testid="referral-dismiss"
              @click="showReferralNotice = false"
            >
              Dismiss
            </button>
          </p>
          <p
            v-else-if="!referralLooksValid"
            class="mt-2 text-sm text-text-muted"
            data-testid="referral-format-hint"
            role="status"
          >
            That doesn’t look like a Servana referral code, but you can still continue —
            we’ll simply carry on without it.
          </p>
        </div>

        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Create account
        </SvButton>
      </form>

      <p class="mt-4 text-sm text-text-muted">
        Already have an account?
        <RouterLink
          :to="{ name: 'auth.login' }"
          class="font-semibold text-heading underline"
        >
          Sign in
        </RouterLink>
      </p>
    </template>

    <template v-else>
      <h1 class="font-display text-2xl font-extrabold text-heading">
        Check your email
      </h1>
      <p
        class="mt-2 text-sm text-text-muted"
        data-testid="register-success"
      >
        If this is a new business, we’ve sent a secure sign-in link to continue
        setting up your account. Open it on this device to get started.
      </p>
      <RouterLink
        :to="{ name: 'auth.login' }"
        class="mt-6 inline-block font-semibold text-heading underline"
      >
        Back to sign in
      </RouterLink>
    </template>
  </SvCard>
</template>
