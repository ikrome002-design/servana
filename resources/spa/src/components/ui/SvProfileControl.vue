<script setup lang="ts">
/**
 * SvProfileControl — the identity unit in the shell header (Phase UI-04; UI/UX plan §14.3).
 *
 * Presents the signed-in person as ONE unit — avatar, name, account label, and merchant/branch
 * context — and opens an authorized menu containing the account switch and logout.
 *
 * Boundaries it keeps:
 *  - it displays only what the authorized `/me` response already returned. No email, no phone, no
 *    internal id: none of those are needed to identify yourself to yourself;
 *  - the avatar fallback is generated LOCALLY from initials. No external avatar service is
 *    contacted — that would leak the user's identity to a third party on every page load;
 *  - it renders only links the caller supplies. Profile, security and preferences routes do not
 *    exist yet, so they are typed props and are simply absent rather than shipped dead;
 *  - it owns no theme or account state; both come from their own stores.
 *
 * Interaction: opens on CLICK (never hover-only), Escape closes, outside click closes, and focus
 * returns to the trigger.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import SvAccountContextSwitcher from '@/components/ui/SvAccountContextSwitcher.vue';
import { SvIconCheck, SvIconLogout, SvIconPreferences, SvIconProfile, SvIconSecurity } from '@/design-system/icons';

const props = withDefaults(
  defineProps<{
    /** Display name from the authorized bootstrap. */
    name: string;
    /** The account/role label, e.g. "Human Resource". */
    accountLabel: string;
    /** Merchant and/or branch context, when the authorized response supplied one. */
    contextLabel?: string | null;
    /** Only supplied once the route exists. Absent props render no link. */
    profileTo?: RouteLocationRaw | null;
    securityTo?: RouteLocationRaw | null;
    preferencesTo?: RouteLocationRaw | null;
    /**
     * The guided setup companion (Phase UI-08 §5.4.2). The UI/UX contract requires it to be
     * reopenable AFTER dismissal, and a dismissed page cannot offer its own way back — so the
     * account menu carries the route. Optional, like every other link here: an account whose owner
     * phase has not yet delivered the route passes nothing and the entry simply does not render.
     */
    getStartedTo?: RouteLocationRaw | null;
  }>(),
  { contextLabel: null, profileTo: null, securityTo: null, preferencesTo: null, getStartedTo: null },
);

const emit = defineEmits<{ logout: [] }>();

const open = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const menuRef = ref<HTMLElement | null>(null);

/**
 * Up to two initials, generated locally.
 *
 * Deliberately not a Gravatar or similar: an external avatar service receives a hash of the
 * user's email on every page load, which is an identity disclosure the product never agreed to.
 */
const initials = computed(() =>
  props.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join(''),
);

const links = computed(() =>
  [
    { key: 'profile', label: 'Profile', to: props.profileTo, icon: SvIconProfile },
    { key: 'security', label: 'Security', to: props.securityTo, icon: SvIconSecurity },
    { key: 'preferences', label: 'Preferences', to: props.preferencesTo, icon: SvIconPreferences },
    { key: 'get-started', label: 'Get started', to: props.getStartedTo, icon: SvIconCheck },
  ].filter((link) => link.to !== null),
);

async function openMenu(): Promise<void> {
  open.value = true;
  await nextTick();
  menuRef.value?.querySelector<HTMLElement>('a, button')?.focus();
}

function closeMenu(returnFocus = true): void {
  open.value = false;
  if (returnFocus) {
    triggerRef.value?.focus();
  }
}

function onMenuKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation();
    closeMenu();
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (menuRef.value?.contains(target ?? null) === true || triggerRef.value?.contains(target ?? null) === true) {
    return;
  }
  closeMenu(false);
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
</script>

<template>
  <div
    class="relative min-w-0"
    data-testid="sv-profile-control"
  >
    <button
      ref="triggerRef"
      type="button"
      :aria-expanded="open"
      aria-controls="sv-profile-menu"
      aria-haspopup="true"
      class="sv-focus-ring inline-flex min-w-0 max-w-full min-h-sv-touch items-center gap-2 rounded-control px-2 py-1 text-left hover:bg-sv-surface-subtle"
      data-testid="sv-profile-trigger"
      @click="open ? closeMenu(false) : openMenu()"
    >
      <!--
        The initials chip sits on a SURFACE, so it takes the surface's own text colour.
        `text-on-brand` is Brand Deep (#4A2208) and belongs only on the brand colour: it is fixed
        across themes, so pairing it with `surface-warm` — a pale cream in light but a dark slate
        in dark — read as 1.07:1 in dark mode on every screen carrying the shell. `text-primary`
        flips with the theme, which is what a surface pairing requires. Gated by the
        surface-warm/text-primary contrast requirement in both themes.
      -->
      <span
        aria-hidden="true"
        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-pill bg-sv-surface-warm text-xs font-semibold text-sv-text"
        data-testid="sv-profile-initials"
      >{{ initials }}</span>

      <!--
        Identity is one unit: name above, account/context below. Hidden below tablet for space.

        The width is CAPPED. `truncate` only engages once the element has a bounded width, and an
        inline-flex trigger otherwise sizes to its content — so a long name made the trigger as wide
        as its text (562px was observed) and pushed the header past the viewport at exactly the
        768px tablet boundary, which is where `md:` first reveals this block. The cap plus `min-w-0`
        on the shrinkable ancestors lets the text ellipsise instead of dictating the header width.
      -->
      <!--
        The identity text INHERITS its colour and must not name one. This control sits in the shell
        header, which is `bg-surface` for seven accounts but `bg-brand-deep text-white` for the
        Super Administrator's header navigation (AppShell). Hard-coding `text-sv-text` (#1F2933)
        put dark text on the deep-brown bar at 1.07:1. Inheriting resolves to the page text colour
        on a surface and to white on the brand bar, so one component is correct on both; the
        secondary line keeps its hierarchy through opacity, which scales with whatever it inherits
        instead of pinning a second colour that would have the same problem.
      -->
      <span class="hidden min-w-0 max-w-sv-profile-identity md:block">
        <span class="block truncate text-sm font-medium">{{ name }}</span>
        <span class="block truncate text-xs opacity-80">
          {{ accountLabel }}<template v-if="contextLabel"> · {{ contextLabel }}</template>
        </span>
      </span>

      <!-- On mobile the trigger is the avatar alone, so it still needs a full accessible name. -->
      <span class="sr-only md:hidden">
        {{ name }}, {{ accountLabel }}<template v-if="contextLabel">, {{ contextLabel }}</template>
      </span>
    </button>

    <div
      v-if="open"
      id="sv-profile-menu"
      ref="menuRef"
      class="absolute right-0 z-sv-popover mt-1 w-max min-w-[14rem] max-w-[calc(100vw-2rem)] rounded-card border border-sv-border bg-sv-surface-raised py-1 shadow-overlay"
      data-testid="sv-profile-menu"
      @keydown="onMenuKeydown"
    >
      <!-- The full identity, readable when the trigger's text is hidden on mobile. -->
      <div class="border-b border-sv-border px-3 py-2">
        <p class="text-sm font-medium text-sv-text">
          {{ name }}
        </p>
        <p class="text-xs text-sv-text-muted">
          {{ accountLabel }}<template v-if="contextLabel">
            · {{ contextLabel }}
          </template>
        </p>
      </div>

      <RouterLink
        v-for="link in links"
        :key="link.key"
        :to="link.to ?? ''"
        class="sv-focus-ring flex min-h-sv-touch items-center gap-2 px-3 py-2 text-sm text-sv-text hover:bg-sv-surface-subtle"
        :data-testid="`sv-profile-${link.key}`"
        @click="closeMenu(false)"
      >
        <component
          :is="link.icon"
          aria-hidden="true"
          class="h-5 w-5 shrink-0"
        />
        {{ link.label }}
      </RouterLink>

      <div class="border-t border-sv-border">
        <SvAccountContextSwitcher variant="menu" />
      </div>

      <div class="border-t border-sv-border">
        <button
          type="button"
          class="sv-focus-ring flex min-h-sv-touch w-full items-center gap-2 px-3 py-2 text-left text-sm text-sv-text hover:bg-sv-surface-subtle"
          data-testid="sv-profile-logout"
          @click="emit('logout')"
        >
          <SvIconLogout
            aria-hidden="true"
            class="h-5 w-5 shrink-0"
          />
          Log out
        </button>
      </div>
    </div>
  </div>
</template>
