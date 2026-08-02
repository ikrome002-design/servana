import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import type { Theme } from '@/types/enums';

/**
 * Servana theme state (Phase UI-04; ADR-021; UI/UX plan §12.1–§12.4).
 *
 * The rules this store exists to keep, in order of precedence:
 *
 *  1. **Light is the default.** A browser with no explicit Servana preference renders light.
 *  2. **`prefers-color-scheme` is never consulted.** The operating system does not select the
 *     theme — that was UI01-THEME-001, and the media query appears nowhere in this file, in the
 *     pre-hydration bootstrap, or in the generated token CSS.
 *  3. **Anonymous:** an explicit choice persists per browser under the canonical Servana key. A
 *     malformed or stale stored value falls back to light rather than throwing.
 *  4. **Authenticated:** the SERVER record is authoritative. It is applied on bootstrap and after
 *     an account switch, and an explicit change is written back to the user's own record so it
 *     follows them across devices and across all eight account hosts.
 *
 * The vocabulary is closed at `light | dark`. There is deliberately no `system`/`auto`.
 */
const STORAGE_KEY = 'servana.theme';

/** The theme served when nobody has expressed a preference (ADR-021 rule 2). */
const DEFAULT_THEME: Theme = 'light';

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark';
}

/** Apply the theme to the document. The single place the `dark` class is ever toggled. */
function applyToDocument(theme: Theme): void {
  document.documentElement.classList.toggle('dark', theme === 'dark');
}

export const useThemeStore = defineStore('theme', () => {
  /**
   * Read the explicit preference this browser already carries.
   *
   * Precedence matches the pre-hydration bootstrap exactly, so hydration cannot change the theme
   * that was already painted:
   *   1. the server-rendered `data-sv-theme` attribute (a signed-in user's stored choice);
   *   2. the explicit per-browser choice in local storage;
   *   3. light.
   */
  function resolveInitial(): Theme {
    try {
      const stamped = document.documentElement.getAttribute('data-sv-theme');
      if (isTheme(stamped)) {
        return stamped;
      }
      const stored = localStorage.getItem(STORAGE_KEY);
      if (isTheme(stored)) {
        return stored;
      }
    } catch {
      // Storage unavailable (private mode, disabled cookies). Light, which is the default anyway.
    }

    return DEFAULT_THEME;
  }

  const theme = ref<Theme>(resolveInitial());

  /** True once a choice has actually been expressed in this browser or by the signed-in user. */
  const hasExplicitPreference = ref<boolean>(false);

  /** Set while a server write is in flight, so a control can avoid claiming a persisted state. */
  const syncing = ref(false);

  /** Non-null when the last server write failed. The local choice still applies. */
  const syncError = ref<string | null>(null);

  const isDark = computed(() => theme.value === 'dark');

  function writeLocal(next: Theme): void {
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // In-memory only for this page. The document class below is still applied.
    }
  }

  /**
   * Apply a theme locally and persist it per browser.
   *
   * This never talks to the server: a public landing page has no session, and the anonymous
   * per-browser preference is the whole contract there.
   */
  function setTheme(next: Theme): void {
    theme.value = next;
    hasExplicitPreference.value = true;
    writeLocal(next);
    applyToDocument(next);
  }

  /**
   * Apply a theme AND persist it to the signed-in user's own record.
   *
   * The local value is applied first so the control responds immediately, then written through.
   * If the server write fails the local theme is kept — reverting the visible theme because a
   * network call failed would be worse — but `syncError` is set so the control can stop claiming
   * the choice was saved. Nothing here is optimistic about the SERVER's state.
   */
  async function setThemeForUser(next: Theme): Promise<void> {
    setTheme(next);
    syncing.value = true;
    syncError.value = null;

    try {
      await apiClient.patch('/auth/preferences', { theme_preference: next });
    } catch {
      syncError.value = 'We couldn’t save your theme preference. It applies on this device only.';
    } finally {
      syncing.value = false;
    }
  }

  /**
   * Adopt the server's answer for the signed-in user (ADR-021 §3).
   *
   * Called from the `/me` bootstrap, which every authenticated page and every account switch runs
   * — so a preference set on one account host is applied on the next host's bootstrap without any
   * cross-host storage, and without the theme ever travelling through a cookie or a URL.
   *
   * `preference` is the user's EXPLICIT choice (null when they have never chosen). `resolved` is
   * the server's applied answer. When the user has no explicit choice, an explicit per-browser
   * choice still wins — a person who picked dark on this device before signing in should not have
   * it silently dropped — but a stored SERVER choice always wins over the browser value.
   */
  function adoptServerPreference(preference: Theme | null, resolved: Theme): void {
    if (isTheme(preference)) {
      theme.value = preference;
      hasExplicitPreference.value = true;
      writeLocal(preference);
      applyToDocument(preference);

      return;
    }

    if (hasExplicitPreference.value) {
      // Keep this browser's explicit choice; the user simply has no server-side preference yet.
      applyToDocument(theme.value);

      return;
    }

    theme.value = resolved;
    applyToDocument(resolved);
  }

  /**
   * Forget the signed-in user's preference on logout.
   *
   * Only the in-memory value is user-specific; the per-browser value is a deliberate choice made
   * on this device and survives by design (ADR-021 §3). What must NOT survive is one user's
   * SERVER preference leaking into the next user's session, which is why the explicit-preference
   * flag is recomputed from local storage alone.
   */
  function forgetUserPreference(): void {
    let stored: string | null;
    try {
      stored = localStorage.getItem(STORAGE_KEY);
    } catch {
      stored = null;
    }

    const next = isTheme(stored) ? stored : DEFAULT_THEME;
    hasExplicitPreference.value = isTheme(stored);
    theme.value = next;
    syncError.value = null;
    applyToDocument(next);
  }

  function toggle(): void {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
  }

  /** Toggle and persist to the signed-in user's record. */
  async function toggleForUser(): Promise<void> {
    await setThemeForUser(theme.value === 'dark' ? 'light' : 'dark');
  }

  // Establish the flag from what is actually stored, and make the document agree with the
  // resolved value. The bootstrap script has normally already done this; doing it again is
  // idempotent and covers the case where the store is created in a test or a fresh mount.
  try {
    hasExplicitPreference.value = isTheme(localStorage.getItem(STORAGE_KEY))
      || isTheme(document.documentElement.getAttribute('data-sv-theme'));
  } catch {
    hasExplicitPreference.value = isTheme(document.documentElement.getAttribute('data-sv-theme'));
  }
  applyToDocument(theme.value);

  return {
    theme,
    isDark,
    hasExplicitPreference,
    syncing,
    syncError,
    setTheme,
    setThemeForUser,
    adoptServerPreference,
    forgetUserPreference,
    toggle,
    toggleForUser,
  };
});
