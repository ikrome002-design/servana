<script setup lang="ts">
/**
 * Public account entry — FOUNDATION ONLY (Phase UI-02; ADR-016, ADR-017).
 *
 * Proves that each of the eight account hosts resolves to the correct account experience.
 * It is deliberately NOT the finished landing page: no hero copy, features, problem/solution
 * sections, testimonials, pricing, curated imagery or final CTAs. Those are UI-06's, sourced
 * verbatim from the approved role content, and inventing them here would be fabricating
 * product claims.
 *
 * The account context is resolved by the SERVER and validated at boot. If it is missing or
 * disagrees with the address bar we render a safe boundary instead of guessing — showing one
 * account's experience under another account's host would be exactly the role confusion this
 * programme is correcting.
 */
import { computed } from 'vue';

import { accountContextResult } from '@/host/accountHostContext';
import { useAppStore } from '@/stores/app';

const app = useAppStore();

// Brand assets are served by Nginx from the repo public/ dir (Plan §4.1), not
// bundled by Vite. Bind as a runtime URL so the bundler leaves it untouched.
const logoUrl = '/assets/brand/Logo.png';

const result = computed(() => accountContextResult());
const context = computed(() => (result.value.ok ? result.value.context : null));

/** Operator-facing explanation for each failure. Never names the approved hosts. */
const failureMessage = computed(() => {
  if (result.value.ok) {
    return null;
  }
  switch (result.value.failure) {
    case 'host_mismatch':
      return 'This address does not match the account this page was served for. Please reopen Servana from your usual web address.';
    case 'unknown_account':
      return 'This account type is not recognised by this version of Servana. Please refresh, then contact your administrator if it continues.';
    default:
      return 'Servana could not determine which account this address belongs to. Please refresh, then contact your administrator if it continues.';
  }
});
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-bg p-6 text-text">
    <section
      v-if="context"
      class="w-full max-w-md rounded-card bg-surface p-8 text-center shadow-card"
      data-servana-surface="foundation_only"
      :data-account-key="context.accountKey"
    >
      <img
        :src="logoUrl"
        alt="Servana by Citrus"
        class="mx-auto mb-6 h-12 w-auto"
      >
      <h1 class="font-display text-2xl font-extrabold text-heading">
        {{ app.name }}
      </h1>
      <p class="mt-2 text-text-muted">
        {{ app.tagline }}
      </p>
      <p
        class="mt-6 text-base font-semibold text-heading"
        data-testid="account-display-name"
      >
        {{ context.displayName }}
      </p>
      <p class="mt-6 text-sm text-text-muted">
        Sign in with your Magic Link to reach your role dashboard.
      </p>
      <RouterLink
        :to="{ name: 'auth.login' }"
        class="mt-6 inline-flex min-h-[44px] items-center rounded-control bg-primary px-5 py-2 text-sm font-semibold text-brand-deep hover:bg-orange-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      >
        Sign in
      </RouterLink>
    </section>

    <section
      v-else
      class="w-full max-w-md rounded-card bg-surface p-8 text-center shadow-card"
      data-servana-surface="account_context_unavailable"
      :data-account-context-failure="result.ok ? '' : result.failure"
      role="alert"
    >
      <h1 class="font-display text-xl font-extrabold text-heading">
        Servana is not available at this address
      </h1>
      <p class="mt-3 text-sm text-text-muted">
        {{ failureMessage }}
      </p>
    </section>
  </main>
</template>
