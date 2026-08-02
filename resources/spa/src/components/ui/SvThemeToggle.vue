<script setup lang="ts">
/**
 * SvThemeToggle — the theme control (Phase UI-04; ADR-021 §6; UI/UX plan §11.1, §12.4).
 *
 * The single place a user changes the theme. It has one permanent home in the fixed footer
 * (ADR-024) and may also appear in the authorized preferences surface; both render this component,
 * so the two can never hold conflicting state — the store is the only state.
 *
 * Behaviour it guarantees:
 *  - accessible name describes the ACTION and the resulting theme, not the current icon;
 *  - `aria-pressed` reflects "dark is on", so a screen reader hears the state as well as the name;
 *  - 44×44 minimum target, keyboard operable as a native button;
 *  - the resulting theme is announced through a polite live region;
 *  - when the user is signed in the choice is written to their own record, and a FAILED write is
 *    reported rather than silently claimed as saved;
 *  - reduced motion is honoured by the global rule in `style.css` — no local animation.
 *
 * It never reads `prefers-color-scheme`. Nothing in Servana does (ADR-021 rule 2).
 */
import { computed } from 'vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { SvIconDarkTheme, SvIconLightTheme } from '@/design-system/icons';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';

withDefaults(
  defineProps<{
    /** `icon` for the footer/header; `switch` for a labelled preferences row. */
    variant?: 'icon' | 'switch';
  }>(),
  { variant: 'icon' },
);

const theme = useThemeStore();
const auth = useAuthStore();

/** Names the ACTION and its outcome. "Switch to dark theme", never "Theme" or "Moon". */
const actionLabel = computed(() =>
  theme.isDark ? 'Switch to light theme' : 'Switch to dark theme',
);

/** Announced after the change so the outcome is heard, not inferred from a repainted page. */
const announcement = computed(() => {
  if (theme.syncError !== null) {
    return theme.syncError;
  }

  return theme.isDark ? 'Dark theme on.' : 'Light theme on.';
});

async function onToggle(): Promise<void> {
  // A signed-in user's choice belongs on their record so it follows them across devices and
  // across all eight account hosts; an anonymous visitor's belongs to this browser only.
  if (auth.user !== null) {
    await theme.toggleForUser();

    return;
  }

  theme.toggle();
}
</script>

<template>
  <div class="inline-flex items-center gap-2">
    <SvIconButton
      v-if="variant === 'icon'"
      :icon="theme.isDark ? SvIconLightTheme : SvIconDarkTheme"
      :label="actionLabel"
      :pressed="theme.isDark"
      :loading="theme.syncing"
      data-testid="theme-toggle"
      @click="onToggle"
    />

    <button
      v-else
      type="button"
      role="switch"
      :aria-checked="theme.isDark"
      :aria-label="actionLabel"
      :aria-busy="theme.syncing || undefined"
      class="sv-focus-ring inline-flex min-h-sv-touch items-center gap-3 rounded-control px-3 py-2 text-sm font-medium text-sv-text hover:bg-sv-surface-subtle"
      data-testid="theme-toggle"
      @click="onToggle"
    >
      <component
        :is="theme.isDark ? SvIconLightTheme : SvIconDarkTheme"
        aria-hidden="true"
        class="h-5 w-5 shrink-0"
      />
      <span>Dark theme</span>
      <span
        aria-hidden="true"
        class="ml-auto inline-flex h-6 w-11 shrink-0 items-center rounded-pill border border-sv-border-input transition-colors duration-sv-fast"
        :class="theme.isDark ? 'bg-sv-brand' : 'bg-sv-surface-subtle'"
      >
        <span
          class="mx-0.5 h-5 w-5 rounded-pill bg-sv-surface-raised shadow-card transition-transform duration-sv-fast"
          :class="theme.isDark ? 'translate-x-5' : 'translate-x-0'"
        />
      </span>
    </button>

    <!--
      Polite, not assertive: the theme change is already visible, so this supplements the visual
      result for screen-reader users rather than interrupting them.
    -->
    <p
      aria-live="polite"
      class="sr-only"
      data-testid="theme-announcement"
    >
      {{ announcement }}
    </p>
  </div>
</template>
