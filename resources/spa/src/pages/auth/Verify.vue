<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const merchant = useMerchantStore();

type ViewState = 'loading' | 'error';
const state = ref<ViewState>('loading');

// Uniform failure copy — never reveals whether the token was missing, expired,
// already used, or belonged to an ineligible account (Plan §9.1).
const ERROR_MESSAGE =
  'This sign-in link is invalid or has expired. Please request a new one.';

async function verify(): Promise<void> {
  const token = typeof route.query.token === 'string' ? route.query.token : '';
  if (token === '') {
    state.value = 'error';
    return;
  }

  try {
    await auth.verifyMagicLink(token);
    // Route by tenant state: a pending owner goes to setup, an active merchant
    // to the dashboard, anyone else to the safe home (full role-aware routing
    // arrives in Phase 11).
    if (auth.setupRequired()) {
      await router.replace({ name: 'onboarding.first-time-setup' });
    } else if (merchant.isActive()) {
      await router.replace({ name: 'merchant.dashboard' });
    } else {
      await router.replace({ name: 'home' });
    }
  } catch {
    state.value = 'error';
  }
}

function backToLogin(): void {
  void router.push({ name: 'auth.login' });
}

onMounted(verify);
</script>

<template>
  <SvCard
    as="section"
    padding="lg"
    class="w-full max-w-md"
  >
    <h1 class="font-display text-2xl font-extrabold text-brand-deep">
      Signing you in
    </h1>

    <div class="mt-6">
      <SvStateBoundary
        :state="state"
        :error-message="ERROR_MESSAGE"
        @retry="backToLogin"
      >
        <!-- success branch is unused: a verified token redirects away -->
        <p class="text-sm text-text-muted">
          One moment…
        </p>
      </SvStateBoundary>
    </div>
  </SvCard>
</template>
