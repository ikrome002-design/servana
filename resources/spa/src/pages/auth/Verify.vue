<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SvCard from '@/components/ui/SvCard.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { useAuthStore } from '@/stores/authStore';
import { landingRouteName } from '@/router/destinations';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

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
    // Route by tenant state: a pending owner goes to setup; everyone else goes
    // to their role-specific landing (Phase 11). The MFA gate and merchant-active
    // guard still run on the destination — the API remains the security boundary.
    if (auth.setupRequired()) {
      await router.replace({ name: 'merchant.setup' });
    } else {
      await router.replace({ name: landingRouteName() });
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
    <h1 class="font-display text-2xl font-extrabold text-heading">
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
