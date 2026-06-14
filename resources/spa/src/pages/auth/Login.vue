<script setup lang="ts">
import axios from 'axios';
import { useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvInput from '@/components/ui/SvInput.vue';
import { useForm } from '@/composables/useForm';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';

const router = useRouter();
const auth = useAuthStore();
const notifications = useNotificationStore();

const form = useForm<{ email: string }>({ email: '' });

const submit = form.handleSubmit(async ({ email }) => {
  try {
    await auth.requestMagicLink(email);
    // Uniform outcome: always advance to the check-email screen on success.
    await router.push({ name: 'auth.check-email', query: { email } });
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
          message: 'Too many requests. Please wait a moment and try again.',
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
    <h1 class="font-display text-2xl font-extrabold text-brand-deep">
      Sign in to Servana
    </h1>
    <p class="mt-2 text-sm text-text-muted">
      Enter your email and we’ll send you a secure sign-in link. No password needed.
    </p>

    <form
      class="mt-6 flex flex-col gap-4"
      novalidate
      @submit.prevent="submit"
    >
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
        Send sign-in link
      </SvButton>
    </form>

    <p class="mt-4 text-sm text-text-muted">
      New to Servana?
      <RouterLink
        :to="{ name: 'auth.register' }"
        class="font-semibold text-brand-deep underline"
      >
        Create a business account
      </RouterLink>
    </p>
  </SvCard>
</template>
