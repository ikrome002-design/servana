<script setup lang="ts">
/**
 * Switch account context (Phase UI-03; ADR-018; UI/UX plan §5.3).
 *
 * FUNCTIONAL ONLY. UI-04 owns the final profile control and its visual design; this exists so the
 * switching flow is real, reachable and keyboard-operable now, and so the security behaviour can
 * be proven in a browser rather than asserted.
 *
 * Contract it keeps:
 *  - renders nothing unless the SERVER reported more than one context (no misleading affordance);
 *  - lists only server-provided contexts, and names the merchant/branch when one is present, so a
 *    multi-merchant user can tell two same-role entries apart;
 *  - navigates to the server's own URL — it never builds a host;
 *  - blocks double submission, because a second mint supersedes the first token;
 *  - announces progress and failure through a live region;
 *  - clears account-specific state before leaving, so nothing from the source account survives.
 */
import { onMounted } from 'vue';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { usePermissionStore } from '@/stores/permissionStore';

const accounts = useAccountContextStore();
const auth = useAuthStore();

onMounted(async () => {
  if (!accounts.loaded) {
    await accounts.fetchContexts();
  }
});

/**
 * Drop every account-scoped store before the redirect.
 *
 * The target host loads a fresh page, so this is belt-and-braces — but a bfcache restore or a
 * failed navigation would otherwise leave the source account's merchant, permissions and identity
 * visible under the target account's chrome, which is precisely the carryover ADR-018 forbids.
 */
function resetAccountStores(): void {
  usePermissionStore().$reset?.();
  useMerchantStore().$reset?.();
  auth.$reset();
}

function onSelect(contextId: string): void {
  void accounts.switchTo(contextId, { resetStores: resetAccountStores });
}
</script>

<template>
  <div
    v-if="accounts.canSwitch"
    class="sv-account-switcher"
  >
    <h2
      id="sv-account-switcher-label"
      class="sv-account-switcher__label"
    >
      Switch account context
    </h2>

    <ul aria-labelledby="sv-account-switcher-label">
      <li
        v-for="context in accounts.contexts"
        :key="context.context_id"
      >
        <button
          type="button"
          :disabled="accounts.switching || context.is_current"
          :aria-current="context.is_current ? 'true' : undefined"
          @click="onSelect(context.context_id)"
        >
          {{ context.display_name }}
          <span v-if="context.merchant_name"> — {{ context.merchant_name }}</span>
          <span v-if="context.branch_name"> ({{ context.branch_name }})</span>
        </button>
      </li>
    </ul>

    <p
      aria-live="polite"
      class="sv-account-switcher__status"
    >
      <span v-if="accounts.switching">Switching account…</span>
      <span v-else-if="accounts.error">{{ accounts.error }}</span>
    </p>
  </div>
</template>
