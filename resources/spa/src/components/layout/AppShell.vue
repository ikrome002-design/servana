<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import HeaderGroupNavigation from '@/components/navigation/HeaderGroupNavigation.vue';
import RoleNavigation from '@/components/navigation/RoleNavigation.vue';
import SvFixedFooter from '@/components/ui/SvFixedFooter.vue';
import SvNotificationsControl from '@/components/ui/SvNotificationsControl.vue';
import SvProfileControl from '@/components/ui/SvProfileControl.vue';
import { SvIconClose, SvIconMenu } from '@/design-system/icons';
import { flattenNavigation, navigationTree } from '@/navigation/navigationFilter';
import { navigationFor } from '@/navigation/roleNavigation';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { ROLE_ENTRY, type RoleIdentity } from '@/types/roles';

/**
 * Authenticated role shell (Phase 11, Plan §26–§30). Enforces the mandatory
 * navigation-placement rule in ONE place:
 *  - Super Administrator (navPlacement 'header'): primary navigation lives in
 *    the header, collapsing to an accessible disclosure on narrow widths. No
 *    primary sidebar.
 *  - All merchant roles (navPlacement 'sidebar'): primary navigation lives in a
 *    desktop sidebar/rail and a mobile drawer; the header carries only utility
 *    controls (identity, context, theme, profile/logout, drawer trigger).
 *
 * Provides: skip link, landmarks, current-route indication, a focusable main,
 * 44px targets, light/dark support, and drawer focus management (focus returns
 * to the trigger on close). Visibility is UX only — the API is the boundary.
 *
 * Phase UI-04 additions:
 *  - the identity/theme/logout cluster became SvProfileControl, which also hosts the UI-03
 *    account switch, so ONE control carries the whole identity unit (UI/UX plan §14.3);
 *  - SvFixedFooter renders on every authenticated page and the shell root carries
 *    `sv-footer-reserve`, which allocates exactly the footer's responsive height. ONE token drives
 *    both the footer and the reserved space, so they cannot disagree — two values that drift
 *    apart is how "the footer covers the submit button" defects appear (ADR-024). The class must
 *    stay on the ROOT element, and the template must keep a single root: a leading comment node
 *    makes the component a fragment, which silently moves the class off the mounted element;
 *  - the account label comes from ROLE_ENTRY, so Human Resource presents as itself rather than
 *    under the Branch identity (UI01-NAV-002).
 */
const props = defineProps<{ identity: RoleIdentity }>();

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const merchant = useMerchantStore();

const entry = computed(() => ROLE_ENTRY[props.identity]);
const items = computed(() => navigationFor(props.identity));
const placement = computed(() => entry.value.navPlacement);

/**
 * Phase UI-08: the header account renders the GROUPED navigation tree rather than the flat list.
 * `navigationTree()` is the same filtered value the drawer renders, so the two surfaces cannot
 * disagree about what a user may see. Sidebar accounts keep the flat `navigationFor()` list until
 * their own owner phase (UI-09 … UI-15) reconciles them.
 */
const navigationNodes = computed(() =>
  navigationTree(props.identity, { permissions: auth.permissions }),
);

// Current page title from the active live nav entry, falling back to the role.
const pageTitle = computed(() => {
  const fromTree = flattenNavigation(navigationNodes.value).find((n) => n.routeName === route.name);
  if (fromTree) return fromTree.label;

  const active = items.value.find((i) => i.routeName === route.name);
  return active?.label ?? entry.value.label;
});

// Mobile drawer (sidebar roles) / header disclosure (super admin).
const navOpen = ref(false);
const triggerRef = ref<HTMLButtonElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);

async function openNav(): Promise<void> {
  navOpen.value = true;
  await nextTick();
  panelRef.value?.querySelector<HTMLElement>('a, button')?.focus();
}
function closeNav(): void {
  navOpen.value = false;
  // Return focus to the trigger that opened the panel (keyboard a11y).
  triggerRef.value?.focus();
}
function toggleNav(): void {
  if (navOpen.value) closeNav();
  else void openNav();
}
function onNavigate(): void {
  if (navOpen.value) closeNav();
}
function onPanelKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') closeNav();
}

// Close the drawer/disclosure on route change.
watch(
  () => route.fullPath,
  () => {
    navOpen.value = false;
  },
);

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'auth.login' });
}

const isHeaderNav = computed(() => placement.value === 'header');
</script>

<template>
  <div class="sv-footer-reserve flex min-h-screen flex-col bg-bg text-text">
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:ring-2 focus:ring-primary"
    >
      Skip to main content
    </a>

    <header
      class="border-b border-border px-4 py-3 md:px-6"
      :class="isHeaderNav ? 'bg-brand-deep text-white' : 'bg-surface'"
    >
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <!-- Sidebar roles: mobile drawer trigger (hidden on desktop). -->
          <button
            v-if="!isHeaderNav"
            ref="triggerRef"
            type="button"
            class="inline-flex h-11 w-11 items-center justify-center rounded-control text-text hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary lg:hidden"
            :aria-expanded="navOpen"
            aria-controls="role-nav-drawer"
            aria-label="Open navigation menu"
            data-testid="nav-drawer-trigger"
            @click="toggleNav"
          >
            <SvIconMenu
              aria-hidden="true"
              class="h-6 w-6"
            />
          </button>
          <img
            :src="'/assets/brand/Logo.png'"
            alt="Servana by Citrus"
            class="h-8 w-auto"
          >
          <span
            class="text-sm font-medium"
            :class="isHeaderNav ? 'opacity-90' : 'text-text-muted'"
          >{{ pageTitle }}</span>
        </div>

        <!--
          Super Admin: primary navigation in the header, from tablet up (ADR-018). Grouped
          disclosures with a CSS-declared overflow — never a desktop left rail.
        -->
        <div
          v-if="isHeaderNav"
          class="hidden min-w-0 flex-1 justify-center md:flex"
        >
          <HeaderGroupNavigation :nodes="navigationNodes" />
        </div>

        <div class="flex items-center gap-1">
          <!-- Merchant/branch context for sidebar roles. -->
          <span
            v-if="!isHeaderNav && merchant.name"
            class="mr-2 hidden text-sm text-text-muted sm:inline"
            data-testid="merchant-context"
          >{{ merchant.name }}</span>

          <SvNotificationsControl />

          <!--
            `get-started-to` is what makes the setup companion reopenable after dismissal: the
            dismissed page cannot offer its own way back, so the account menu carries the route
            (UI/UX plan §5.4.2). Only the header account passes it today; each sidebar account's
            owner phase supplies its own.
          -->
          <SvProfileControl
            v-if="auth.user"
            :name="auth.user.name"
            :account-label="entry.label"
            :context-label="merchant.name ?? null"
            :get-started-to="isHeaderNav ? { name: entry.getStartedRouteName } : null"
            @logout="logout"
          />

          <!-- Super Admin: header-nav disclosure on mobile. -->
          <button
            v-if="isHeaderNav"
            ref="triggerRef"
            type="button"
            class="inline-flex h-11 w-11 items-center justify-center rounded-control text-white hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary md:hidden"
            :aria-expanded="navOpen"
            aria-controls="role-nav-drawer"
            aria-label="Open navigation menu"
            data-testid="nav-drawer-trigger"
            @click="toggleNav"
          >
            <SvIconMenu
              aria-hidden="true"
              class="h-6 w-6"
            />
          </button>
        </div>
      </div>
    </header>

    <div class="flex flex-1">
      <!-- Sidebar roles: desktop persistent primary navigation. -->
      <nav
        v-if="!isHeaderNav"
        aria-label="Primary navigation"
        class="hidden w-64 shrink-0 border-r border-border bg-surface p-3 lg:block"
        data-testid="sidebar-primary-nav"
      >
        <RoleNavigation :items="items" />
      </nav>

      <!--
        `min-w-0` is load-bearing (Plan §28). As a flex item, `main` defaults to
        `min-width: auto`, so it cannot shrink below its content's min-content width — one wide
        child (an unbreakable machine token, an intrinsically sized control, a table) then widens
        the WHOLE document instead of being contained here. Without it, page-level horizontal
        overflow is reachable from any screen.
      -->
      <main
        id="main-content"
        tabindex="-1"
        class="min-w-0 flex-1 p-4 focus:outline-none md:p-6"
      >
        <slot />
      </main>
    </div>

    <SvFixedFooter :legal-role="identity" />

    <!-- Off-canvas drawer (mobile sidebar roles / mobile super-admin disclosure). -->
    <Teleport to="body">
      <div
        v-if="navOpen"
        class="fixed inset-0 z-50"
        :class="isHeaderNav ? 'md:hidden' : 'lg:hidden'"
      >
        <div
          class="absolute inset-0 bg-black/50"
          aria-hidden="true"
          @click="closeNav"
        />
        <div
          id="role-nav-drawer"
          ref="panelRef"
          role="dialog"
          aria-modal="true"
          aria-label="Navigation"
          class="absolute inset-y-0 left-0 w-72 max-w-[85%] overflow-y-auto bg-surface p-4 shadow-xl"
          @keydown="onPanelKeydown"
        >
          <div class="mb-3 flex items-center justify-between">
            <span class="font-display text-sm font-semibold text-heading">{{ entry.label }}</span>
            <button
              type="button"
              class="inline-flex h-11 w-11 items-center justify-center rounded-control text-text hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              aria-label="Close navigation menu"
              data-testid="nav-drawer-close"
              @click="closeNav"
            >
              <SvIconClose
                aria-hidden="true"
                class="h-5 w-5"
              />
            </button>
          </div>
          <!--
            The drawer renders the SAME filtered tree the header does, as always-open labelled
            sections. Two different sources here is exactly how "an item is missing on mobile"
            defects appear.
          -->
          <HeaderGroupNavigation
            v-if="isHeaderNav"
            :nodes="navigationNodes"
            variant="stacked"
            @navigate="onNavigate"
          />
          <RoleNavigation
            v-else
            :items="items"
            @navigate="onNavigate"
          />
        </div>
      </div>
    </Teleport>
  </div>
</template>
