<script setup lang="ts">
/**
 * SvAccountContextSwitcher — the final presentation of UI-03's account switch (Phase UI-04).
 *
 * UI-04 owns the VISUAL and ACCESSIBLE presentation only. Every security property stays exactly
 * where UI-03 put it, and this component deliberately holds none of it:
 *
 *  - the context list is SERVER-DERIVED (`/auth/account-contexts`); nothing is computed here;
 *  - the identifier submitted is the server's OPAQUE id — no role, merchant, branch, host or
 *    permission is ever sent from the browser;
 *  - the target URL comes back from the server; this component never builds a host (ADR-017);
 *  - handoff issuance, single-use consumption, expiry, MFA and revocation are untouched;
 *  - account-scoped stores are reset before leaving, so nothing from the source account survives
 *    a bfcache restore or a failed navigation.
 *
 * What UI-04 adds: a real disclosure with keyboard support, current-context identification,
 * merchant/branch disambiguation, duplicate-submit prevention, and a live region for progress and
 * failure.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { SvIconCheck, SvIconSwitchAccount } from '@/design-system/icons';
import { useAccountContextStore } from '@/stores/accountContextStore';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { usePermissionStore } from '@/stores/permissionStore';

withDefaults(
  defineProps<{
    /** `menu` renders its own trigger; `inline` renders just the list, for embedding in a menu. */
    variant?: 'menu' | 'inline';
  }>(),
  { variant: 'menu' },
);

const accounts = useAccountContextStore();
const auth = useAuthStore();

const open = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

onMounted(async () => {
  if (!accounts.loaded) {
    await accounts.fetchContexts();
  }
});

/**
 * Drop every account-scoped store before the redirect.
 *
 * The target host loads a fresh page, so this is belt-and-braces — but a bfcache restore or a
 * failed navigation would otherwise leave the SOURCE account's merchant, permissions and identity
 * visible under the TARGET account's chrome, which is precisely the carryover ADR-018 forbids.
 */
function resetAccountStores(): void {
  usePermissionStore().$reset?.();
  useMerchantStore().$reset?.();
  auth.$reset();
}

function select(contextId: string): void {
  // The store blocks a second mint while one is in flight: a second handoff supersedes the first.
  if (accounts.switching) {
    return;
  }
  void accounts.switchTo(contextId, { resetStores: resetAccountStores });
}

async function openPanel(): Promise<void> {
  open.value = true;
  await nextTick();
  panelRef.value?.querySelector<HTMLElement>('button:not([disabled])')?.focus();
}

function closePanel(returnFocus = true): void {
  open.value = false;
  if (returnFocus) {
    triggerRef.value?.focus();
  }
}

function onPanelKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation();
    closePanel();
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (panelRef.value?.contains(target ?? null) === true || triggerRef.value?.contains(target ?? null) === true) {
    return;
  }
  closePanel(false);
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);

    return;
  }
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

/**
 * A context's disambiguating description.
 *
 * A user with the same role in two merchants sees two identical labels otherwise. Only fields the
 * SERVER supplied are used — nothing is derived.
 */
function describe(context: { merchant_name?: string | null; branch_name?: string | null }): string {
  return [context.merchant_name, context.branch_name].filter((part) => part !== null && part !== '').join(' · ');
}

/** The live-region text: progress, then failure. */
const status = computed(() => {
  if (accounts.switching) {
    return 'Switching account…';
  }

  return accounts.error ?? '';
});
</script>

<template>
  <!-- Renders nothing when the SERVER reported a single context: no misleading affordance. -->
  <div
    v-if="accounts.canSwitch"
    class="relative"
    data-testid="sv-account-context-switcher"
  >
    <button
      v-if="variant === 'menu'"
      ref="triggerRef"
      type="button"
      :aria-expanded="open"
      aria-controls="sv-account-context-panel"
      :disabled="accounts.switching"
      class="sv-focus-ring inline-flex min-h-sv-touch w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm text-sv-text hover:bg-sv-surface-subtle disabled:text-sv-disabled-fg"
      data-testid="sv-account-switch-trigger"
      @click="open ? closePanel(false) : openPanel()"
    >
      <SvIconSwitchAccount
        aria-hidden="true"
        class="h-5 w-5 shrink-0"
      />
      Switch account
    </button>

    <div
      v-if="open || variant === 'inline'"
      id="sv-account-context-panel"
      ref="panelRef"
      class="rounded-card border border-sv-border bg-sv-surface-raised py-1"
      :class="variant === 'menu' ? 'absolute right-0 z-sv-popover mt-1 w-max min-w-full max-w-[calc(100vw-2rem)] shadow-overlay' : ''"
      @keydown="onPanelKeydown"
    >
      <h2
        id="sv-account-context-label"
        class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-sv-text-muted"
      >
        Switch account context
      </h2>

      <ul aria-labelledby="sv-account-context-label">
        <li
          v-for="context in accounts.contexts"
          :key="context.context_id"
        >
          <button
            type="button"
            :disabled="accounts.switching || context.is_current"
            :aria-current="context.is_current ? 'true' : undefined"
            class="sv-focus-ring flex min-h-sv-touch w-full items-start gap-2 px-3 py-2 text-left text-sm text-sv-text hover:bg-sv-surface-subtle disabled:cursor-default disabled:bg-sv-selected-bg disabled:text-sv-selected-fg"
            :data-testid="`sv-account-context-${context.context_id}`"
            @click="select(context.context_id)"
          >
            <SvIconCheck
              v-if="context.is_current"
              aria-hidden="true"
              class="mt-0.5 h-4 w-4 shrink-0"
            />
            <span class="min-w-0">
              <span class="block font-medium">{{ context.display_name }}</span>
              <span
                v-if="describe(context) !== ''"
                class="block text-xs text-sv-text-muted"
              >{{ describe(context) }}</span>
              <!-- Stated in text, not carried by the tick alone. -->
              <span
                v-if="context.is_current"
                class="sr-only"
              >Current account</span>
            </span>
          </button>
        </li>
      </ul>

      <p
        aria-live="polite"
        class="px-3 py-1 text-xs"
        :class="accounts.error ? 'text-sv-error-fg' : 'text-sv-text-muted'"
        data-testid="sv-account-switch-status"
      >
        {{ status }}
      </p>
    </div>
  </div>
</template>
