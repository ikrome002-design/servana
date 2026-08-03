<script setup lang="ts">
/**
 * PublicLandingLayout — the shell every public page shares (Phase UI-06; ADR-017, ADR-024).
 *
 * Owns the four things that must be identical on all eight hosts and every public route: the skip
 * link, the single `main` landmark, the fixed footer, and the space reserved for it.
 *
 * ## The account context
 *
 * The SERVER decides which account this browser is on and embeds the answer in the shell; this
 * layout only reads the already-resolved result. When it is missing, malformed, unknown or
 * disagrees with the address bar, the layout renders a safe boundary and NO account experience.
 * Showing one account's public page under another account's host is exactly the role confusion this
 * programme exists to correct, so guessing is never the answer.
 *
 * Resolving a host still grants nothing (ADR-017): it selects public content, and every protected
 * request is authorized against the database regardless.
 *
 * ## Footer reservation
 *
 * `sv-footer-reserve` must stay on the ROOT element. A leading comment node would make this
 * component a fragment, which silently moves the class off the mounted element and lets the fixed
 * footer sit on top of the last call to action — the defect ADR-024 catalogues.
 */
import { computed } from 'vue';
import SvFixedFooter from '@/components/ui/SvFixedFooter.vue';
import { accountContextResult } from '@/host/accountHostContext';
import { publicFaqLocation } from '@/router/publicRoutes';

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
  <div class="sv-footer-reserve flex min-h-screen flex-col bg-sv-surface-page text-sv-text">
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-sv-dialog focus:rounded-control focus:bg-sv-surface-raised focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:ring-2 focus:ring-sv-focus"
      data-testid="landing-skip-link"
    >
      Skip to main content
    </a>

    <slot
      v-if="context"
      name="header"
      :context="context"
    />

    <main
      id="main-content"
      tabindex="-1"
      class="min-w-0 flex-1 focus:outline-none"
    >
      <slot
        v-if="context"
        :context="context"
      />

      <!--
        Fail closed. No account experience, no content from any account, and a message that
        describes the situation without enumerating the approved hosts.
      -->
      <section
        v-else
        class="mx-auto w-full max-w-sv-readable px-4 py-16 text-center"
        data-servana-surface="account_context_unavailable"
        :data-account-context-failure="result.ok ? '' : result.failure"
        role="alert"
        data-testid="landing-context-boundary"
      >
        <h1 class="font-display text-2xl font-extrabold text-sv-text-heading">
          Servana is not available at this address
        </h1>
        <p class="mt-3 text-sv-text-secondary">
          {{ failureMessage }}
        </p>
      </section>
    </main>

    <!--
      The legal links are host-derived: the footer is given the resolved account, so one account
      can never receive another's documents. With no context there is no account, so the footer
      renders its identity, social and theme controls and no legal links at all.
    -->
    <SvFixedFooter
      :legal-role="context?.legalContentKey ?? null"
      :faq-to="context ? publicFaqLocation() : null"
    />
  </div>
</template>
