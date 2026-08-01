<script setup lang="ts">
/**
 * Role-safe access-denied state (Phase UI-03; UI/UX plan §5.4).
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *  - it never says WHICH account or resource was refused, so it cannot confirm that one exists;
 *  - it never redirects to another account, because bouncing a user toward a broader surface is
 *    the exact failure §5.4 prohibits — and bouncing them toward a narrower one still discloses
 *    which account they hold;
 *  - it offers only sign-out and the accounts the SERVER already said they may enter.
 *
 * UI-04 owns the final visual treatment of shared feedback states. This is the functional surface.
 */
import { onMounted, ref } from 'vue';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const accounts = useAccountContextStore();
const heading = ref<HTMLElement | null>(null);

onMounted(async () => {
  // Move focus to the heading so a screen-reader user is told what happened rather than being
  // left at the top of a page whose content silently changed.
  heading.value?.focus();

  if (auth.isAuthenticated() && !accounts.loaded) {
    await accounts.fetchContexts();
  }
});

async function signOut(): Promise<void> {
  await auth.logout();
  window.location.assign('/auth/login');
}
</script>

<template>
  <main
    class="sv-access-denied"
    role="main"
  >
    <h1
      ref="heading"
      tabindex="-1"
    >
      You do not have access to this page
    </h1>

    <p>
      Your account does not have access to this part of Servana. If you think this is wrong, ask
      the person who manages your Servana access.
    </p>

    <div class="sv-access-denied__actions">
      <button
        type="button"
        @click="signOut"
      >
        Sign out
      </button>
    </div>

    <section
      v-if="accounts.otherContexts.length > 0"
      aria-labelledby="sv-access-denied-accounts"
    >
      <h2 id="sv-access-denied-accounts">
        Your other accounts
      </h2>
      <ul>
        <li
          v-for="context in accounts.otherContexts"
          :key="context.context_id"
        >
          <button
            type="button"
            :disabled="accounts.switching"
            @click="accounts.switchTo(context.context_id)"
          >
            {{ context.display_name }}
            <span v-if="context.merchant_name"> — {{ context.merchant_name }}</span>
          </button>
        </li>
      </ul>
    </section>
  </main>
</template>
