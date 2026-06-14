<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import { useForm } from '@/composables/useForm';
import { apiClient, primeCsrfCookie } from '@/services/apiClient';
import { useNotificationStore } from '@/stores/notificationStore';

// Merchant Administrator self-registration (Scope §3.1/§3.2). No password, no
// KYC — the business owner registers, the tenant is created, and a Magic Link
// is emailed to continue. The success state is uniform (no account enumeration).
const notifications = useNotificationStore();
const submitted = ref(false);

const form = useForm<{ owner_name: string; email: string; business_name: string }>({
  owner_name: '',
  email: '',
  business_name: '',
});

const submit = form.handleSubmit(async (values) => {
  try {
    await primeCsrfCookie();
    await apiClient.post('/merchant-registration/self-register', values);
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
      <h1 class="font-display text-2xl font-extrabold text-brand-deep">
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
        <SvInput
          id="owner_name"
          v-model="form.values.owner_name"
          label="Your name"
          placeholder="Paul Nderitu"
          required
          :errors="form.errors.owner_name"
        />
        <SvInput
          id="business_name"
          v-model="form.values.business_name"
          label="Business name"
          placeholder="Glow Salon &amp; Spa"
          required
          :errors="form.errors.business_name"
        />
        <SvInput
          id="email"
          v-model="form.values.email"
          label="Email address"
          type="email"
          placeholder="you@business.co.ke"
          required
          :errors="form.errors.email"
        />

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
          class="font-semibold text-brand-deep underline"
        >
          Sign in
        </RouterLink>
      </p>
    </template>

    <template v-else>
      <h1 class="font-display text-2xl font-extrabold text-brand-deep">
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
        class="mt-6 inline-block font-semibold text-brand-deep underline"
      >
        Back to sign in
      </RouterLink>
    </template>
  </SvCard>
</template>
