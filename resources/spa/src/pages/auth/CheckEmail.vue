<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import { useAuthStore } from '@/stores/authStore';
import { useNotificationStore } from '@/stores/notificationStore';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const notifications = useNotificationStore();

const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''));
const resending = ref(false);

async function resend(): Promise<void> {
  if (email.value === '' || resending.value) {
    return;
  }
  resending.value = true;
  try {
    await auth.requestMagicLink(email.value);
    notifications.addToast({ type: 'success', message: 'Sign-in link sent again.' });
  } catch {
    // Uniform behaviour — never reveal account state on resend either.
    notifications.addToast({ type: 'success', message: 'Sign-in link sent again.' });
  } finally {
    resending.value = false;
  }
}

function backToLogin(): void {
  void router.push({ name: 'auth.login' });
}
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md text-center"
  >
    <div
      aria-hidden="true"
      class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-cream text-3xl"
    >
      ✉️
    </div>
    <h1 class="font-display text-2xl font-extrabold text-brand-deep">
      Check your email
    </h1>
    <p class="mt-2 text-sm text-text-muted">
      If an active account matches
      <span
        v-if="email"
        class="font-medium text-text"
      >{{ email }}</span>
      <span v-else>that address</span>, we’ve sent a secure sign-in link. It expires in 15 minutes
      and can be used once.
    </p>

    <div class="mt-6 flex flex-col gap-3">
      <SvButton
        variant="secondary"
        :loading="resending"
        @click="resend"
      >
        Resend link
      </SvButton>
      <SvButton
        variant="ghost"
        @click="backToLogin"
      >
        Back to sign in
      </SvButton>
    </div>
  </SvCard>
</template>
