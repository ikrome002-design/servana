<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';
import { landingRouteName } from '@/router/destinations';

// Per-session MFA / step-up challenge (Plan §18, Phase R3). A confirmed user
// proves possession of their authenticator (or a one-time recovery code) to
// assert MFA for the session. UX only — the API is the security boundary.

const router = useRouter();
const auth = useAuthStore();
const notifications = useNotificationStore();

const useRecoveryCode = ref(false);
const form = useForm<{ code: string }>({ code: '' });

const submit = form.handleSubmit(async ({ code }) => {
  try {
    if (useRecoveryCode.value) {
      await auth.mfaRecoveryChallenge(code);
    } else {
      await auth.mfaChallenge(code);
    }

    if (auth.setupRequired()) {
      await router.replace({ name: 'onboarding.first-time-setup' });
    } else {
      await router.replace({ name: landingRouteName() });
    }
  } catch (err: unknown) {
    if (axios.isAxiosError(err) && err.apiError) {
      const { code: errorCode, message } = err.apiError;
      if (errorCode === 'validation_failed') {
        form.mergeServerErrors(err.apiError);
        return;
      }
      if (errorCode === 'mfa_invalid_code') {
        form.setFieldError('code', ['That code is invalid or has expired. Please try again.']);
        return;
      }
      if (errorCode === 'rate_limited') {
        notifications.addToast({
          type: 'warning',
          message: 'Too many attempts. Please wait a moment and try again.',
        });
        return;
      }
      notifications.addToast({ type: 'error', message });
      return;
    }
    notifications.addToast({ type: 'error', message: 'Something went wrong. Please try again.' });
  }
});

function toggleMode(): void {
  useRecoveryCode.value = !useRecoveryCode.value;
  form.values.code = '';
  delete form.errors.code;
}
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md"
  >
    <h1 class="font-display text-2xl font-extrabold text-brand-deep">
      Verify it’s you
    </h1>
    <p class="mt-2 text-sm text-text-muted">
      <template v-if="useRecoveryCode">
        Enter one of your one-time recovery codes.
      </template>
      <template v-else>
        Enter the 6-digit code from your authenticator app.
      </template>
    </p>

    <form
      class="mt-6 flex flex-col gap-4"
      novalidate
      @submit.prevent="submit"
    >
      <SvInput
        id="mfa-challenge-code"
        v-model="form.values.code"
        :label="useRecoveryCode ? 'Recovery code' : '6-digit code'"
        :placeholder="useRecoveryCode ? 'XXXXX-XXXXX' : '123456'"
        required
        :errors="form.errors.code"
      />

      <SvButton
        type="submit"
        variant="primary"
        :loading="form.submitting.value"
      >
        Verify
      </SvButton>
    </form>

    <button
      type="button"
      class="mt-4 text-sm font-semibold text-brand-deep underline"
      @click="toggleMode"
    >
      {{ useRecoveryCode ? 'Use my authenticator app instead' : 'Use a recovery code instead' }}
    </button>
  </SvCard>
</template>
