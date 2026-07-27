<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import RoleNavigation from '@/components/navigation/RoleNavigation.vue';
import { navigationFor } from '@/navigation/roleNavigation';
import { useAuthStore } from '@/stores/authStore';
import { useMerchantStore } from '@/stores/merchantStore';
import { useThemeStore } from '@/stores/themeStore';
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
 */
const props = defineProps<{ identity: RoleIdentity }>();

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const merchant = useMerchantStore();
const theme = useThemeStore();

const entry = computed(() => ROLE_ENTRY[props.identity]);
const items = computed(() => navigationFor(props.identity));
const placement = computed(() => entry.value.navPlacement);

// Current page title from the active live nav item, falling back to the role.
const pageTitle = computed(() => {
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

const themeLabel = computed(() =>
  theme.theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme',
);

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'auth.login' });
}

const isHeaderNav = computed(() => placement.value === 'header');
</script>

<template>
  <div class="flex min-h-screen flex-col bg-bg text-text">
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
            <span
              aria-hidden="true"
              class="text-xl"
            >☰</span>
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

        <!-- Super Admin: primary navigation in the header (desktop inline). -->
        <nav
          v-if="isHeaderNav"
          aria-label="Platform primary navigation"
          class="hidden flex-1 justify-center md:flex"
          data-testid="header-primary-nav"
        >
          <RoleNavigation
            :items="items"
            variant="header"
          />
        </nav>

        <div class="flex items-center gap-1">
          <!-- Merchant/branch context for sidebar roles. -->
          <span
            v-if="!isHeaderNav && merchant.name"
            class="mr-2 hidden text-sm text-text-muted sm:inline"
            data-testid="merchant-context"
          >{{ merchant.name }}</span>

          <button
            type="button"
            class="inline-flex h-11 w-11 items-center justify-center rounded-control hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="isHeaderNav ? 'text-white hover:bg-white/10' : 'text-text'"
            :aria-label="themeLabel"
            data-testid="theme-toggle"
            @click="theme.toggle()"
          >
            <span aria-hidden="true">{{ theme.theme === 'dark' ? '☀' : '☾' }}</span>
          </button>

          <span
            v-if="auth.user"
            class="hidden px-2 text-sm font-medium sm:inline"
          >{{ auth.user.name }}</span>
          <button
            type="button"
            class="inline-flex min-h-[44px] items-center rounded-control px-3 py-2 text-sm font-medium hover:bg-surface-alt focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :class="isHeaderNav ? 'text-white hover:bg-white/10' : 'text-text'"
            data-testid="logout"
            @click="logout"
          >
            Log out
          </button>

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
            <span
              aria-hidden="true"
              class="text-xl"
            >☰</span>
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
              <span aria-hidden="true">✕</span>
            </button>
          </div>
          <RoleNavigation
            :items="items"
            @navigate="onNavigate"
          />
        </div>
      </div>
    </Teleport>
  </div>
</template>
