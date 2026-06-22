<script setup lang="ts">
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { useNotificationStore } from '@/stores/notificationStore';

// Mandatory-MFA TOTP enrollment (Plan §18, Phase R3). The secret and otpauth
// provisioning URI are shown once for the authenticator; we never write them to
// localStorage/sessionStorage or log them. Recovery codes are shown once after
// confirmation. Frontend checks are UX only — the API is the security boundary.

const router = useRouter();
const auth = useAuthStore();
const merchant = useMerchantStore();
const notifications = useNotificationStore();

type Step = 'loading' | 'scan' | 'recovery' | 'error';
const step = ref<Step>('loading');

const secret = ref('');
const otpauthUri = ref('');
const recoveryCodes = ref<string[]>([]);
const acknowledged = ref(false);

const form = useForm<{ code: string }>({ code: '' });

async function startEnrollment(): Promise<void> {
  try {
    const result = await auth.startMfaEnrollment();
    secret.value = result.secret;
    otpauthUri.value = result.otpauth_uri;
    step.value = 'scan';
  } catch {
    step.value = 'error';
  }
}

const confirm = form.handleSubmit(async ({ code }) => {
  try {
    recoveryCodes.value = await auth.confirmMfaEnrollment(code);
    step.value = 'recovery';
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

async function finish(): Promise<void> {
  if (merchant.isActive()) {
    await router.replace({ name: 'merchant.dashboard' });
  } else {
    await router.replace({ name: 'home' });
  }
}

onMounted(startEnrollment);
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md"
  >
    <h1 class="font-display text-2xl font-extrabold text-brand-deep">
      Set up two-factor authentication
    </h1>
    <p class="mt-2 text-sm text-text-muted">
      Your role requires an authenticator app for sign-in. Add Servana to an app
      like Google Authenticator or 1Password.
    </p>

    <p
      v-if="step === 'loading'"
      class="mt-6 text-sm text-text-muted"
    >
      Preparing your setup…
    </p>

    <p
      v-else-if="step === 'error'"
      role="alert"
      class="mt-6 text-sm text-error"
    >
      We could not start setup. Please reload the page and try again.
    </p>

    <div
      v-else-if="step === 'scan'"
      class="mt-6 flex flex-col gap-4"
    >
      <div>
        <p class="text-sm font-medium text-text">
          Scan or enter this setup key
        </p>
        <p class="mt-1 break-all rounded-control border border-border bg-surface px-3 py-2 font-mono text-sm text-text">
          {{ secret }}
        </p>
        <p class="mt-1 break-all text-xs text-text-muted">
          Or add this link in your authenticator: <span class="font-mono">{{ otpauthUri }}</span>
        </p>
      </div>

      <form
        class="flex flex-col gap-4"
        novalidate
        @submit.prevent="confirm"
      >
        <SvInput
          id="mfa-code"
          v-model="form.values.code"
          label="6-digit code"
          placeholder="123456"
          required
          :errors="form.errors.code"
        />
        <SvButton
          type="submit"
          variant="primary"
          :loading="form.submitting.value"
        >
          Confirm and continue
        </SvButton>
      </form>
    </div>

    <div
      v-else-if="step === 'recovery'"
      class="mt-6 flex flex-col gap-4"
    >
      <div>
        <p class="text-sm font-medium text-text">
          Save your recovery codes
        </p>
        <p class="mt-1 text-xs text-text-muted">
          Each code works once if you lose your authenticator. Store them
          somewhere safe — they will not be shown again.
        </p>
        <ul class="mt-2 grid grid-cols-2 gap-2 rounded-control border border-border bg-surface p-3 font-mono text-sm text-text">
          <li
            v-for="recoveryCode in recoveryCodes"
            :key="recoveryCode"
          >
            {{ recoveryCode }}
          </li>
        </ul>
      </div>

      <label class="flex items-center gap-2 text-sm text-text">
        <input
          v-model="acknowledged"
          type="checkbox"
          class="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-primary"
        >
        I have saved my recovery codes
      </label>

      <SvButton
        variant="primary"
        :disabled="!acknowledged"
        @click="finish"
      >
        Continue
      </SvButton>
    </div>
  </SvCard>
</template>
