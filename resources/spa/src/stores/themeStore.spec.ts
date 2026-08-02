import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import { useThemeStore } from '@/stores/themeStore';

vi.mock('@/services/apiClient', async () => {
  const actual = await vi.importActual<typeof import('@/services/apiClient')>('@/services/apiClient');

  return { ...actual, apiClient: { patch: vi.fn().mockResolvedValue({ data: {} }) } };
});

/**
 * Phase UI-04 — theme store contract (ADR-021; UI/UX plan §12.1–§12.2).
 *
 * Every scenario in `docs/frontend/audits/ui-04/theme-matrix.json` that can be exercised without
 * a browser is exercised here; the pre-hydration and no-flash cases are proven in
 * `tests/e2e/ui-04-theme.spec.ts`, which needs a real document.
 */
describe('themeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    localStorage.clear();
    document.documentElement.classList.remove('dark');
    document.documentElement.removeAttribute('data-sv-theme');
  });

  describe('anonymous default (UI01-THEME-001)', () => {
    it('renders light in a clean browser', () => {
      const theme = useThemeStore();

      expect(theme.theme).toBe('light');
      expect(theme.hasExplicitPreference).toBe(false);
      expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('still renders light when the operating system prefers dark', () => {
      // THE audited defect. matchMedia is stubbed to report a dark OS; the store must ignore it
      // entirely, because ADR-021 rule 2 forbids the OS from selecting the theme.
      const matchMedia = vi.fn().mockReturnValue({
        matches: true,
        media: '(prefers-color-scheme: dark)',
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      });
      vi.stubGlobal('matchMedia', matchMedia);

      const theme = useThemeStore();

      expect(theme.theme).toBe('light');
      expect(document.documentElement.classList.contains('dark')).toBe(false);
      // Not merely "the answer is light" — the store must never even ASK.
      expect(matchMedia).not.toHaveBeenCalled();

      vi.unstubAllGlobals();
    });

    it('falls back to light for a malformed stored value', () => {
      localStorage.setItem('servana.theme', 'system');

      const theme = useThemeStore();

      expect(theme.theme).toBe('light');
      expect(theme.hasExplicitPreference).toBe(false);
    });

    it('honours an explicit stored dark choice', () => {
      localStorage.setItem('servana.theme', 'dark');

      const theme = useThemeStore();

      expect(theme.theme).toBe('dark');
      expect(theme.hasExplicitPreference).toBe(true);
      expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('persists an explicit choice per browser', () => {
      const theme = useThemeStore();

      theme.setTheme('dark');

      expect(localStorage.getItem('servana.theme')).toBe('dark');
      expect(document.documentElement.classList.contains('dark')).toBe(true);

      theme.setTheme('light');

      expect(localStorage.getItem('servana.theme')).toBe('light');
      expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('toggles between exactly two values', () => {
      const theme = useThemeStore();

      theme.toggle();
      expect(theme.theme).toBe('dark');
      theme.toggle();
      expect(theme.theme).toBe('light');
    });

    it('survives storage being unavailable', () => {
      const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('QuotaExceededError');
      });

      const theme = useThemeStore();

      expect(() => theme.setTheme('dark')).not.toThrow();
      // The document still reflects the choice for this page even though it could not be stored.
      expect(document.documentElement.classList.contains('dark')).toBe(true);

      setItem.mockRestore();
    });
  });

  describe('server-rendered preference', () => {
    it('adopts the attribute the Laravel shell stamped', () => {
      document.documentElement.setAttribute('data-sv-theme', 'dark');

      const theme = useThemeStore();

      expect(theme.theme).toBe('dark');
      expect(theme.hasExplicitPreference).toBe(true);
    });

    it('lets the server attribute win over a conflicting stored value', () => {
      // The signed-in user's record is authoritative; the browser value is the fallback.
      localStorage.setItem('servana.theme', 'light');
      document.documentElement.setAttribute('data-sv-theme', 'dark');

      expect(useThemeStore().theme).toBe('dark');
    });
  });

  describe('authenticated synchronisation', () => {
    it('applies the user\'s stored server preference on bootstrap', () => {
      const theme = useThemeStore();

      theme.adoptServerPreference('dark', 'dark');

      expect(theme.theme).toBe('dark');
      expect(document.documentElement.classList.contains('dark')).toBe(true);
      // Mirrored locally so the next first paint on this device is already correct.
      expect(localStorage.getItem('servana.theme')).toBe('dark');
    });

    it('lets the server preference override this browser\'s choice', () => {
      const theme = useThemeStore();
      theme.setTheme('dark');

      theme.adoptServerPreference('light', 'light');

      expect(theme.theme).toBe('light');
      expect(localStorage.getItem('servana.theme')).toBe('light');
    });

    it('keeps this browser\'s explicit choice when the user has no server preference', () => {
      // Someone who chose dark on this device before signing in should not lose it just because
      // their account carries no preference yet.
      const theme = useThemeStore();
      theme.setTheme('dark');

      theme.adoptServerPreference(null, 'light');

      expect(theme.theme).toBe('dark');
    });

    it('uses the server\'s resolution when nobody has chosen anything', () => {
      const theme = useThemeStore();

      theme.adoptServerPreference(null, 'light');

      expect(theme.theme).toBe('light');
      expect(theme.hasExplicitPreference).toBe(false);
    });

    it('writes an explicit change to the user\'s own record', async () => {
      const theme = useThemeStore();

      await theme.setThemeForUser('dark');

      expect(apiClient.patch).toHaveBeenCalledWith('/auth/preferences', { theme_preference: 'dark' });
      expect(theme.theme).toBe('dark');
      expect(theme.syncError).toBeNull();
      expect(theme.syncing).toBe(false);
    });

    it('keeps the local theme but reports the failure when the server write fails', async () => {
      vi.mocked(apiClient.patch).mockRejectedValueOnce(new Error('network'));

      const theme = useThemeStore();
      await theme.setThemeForUser('dark');

      // Reverting the visible theme because a network call failed would be worse than keeping it.
      expect(theme.theme).toBe('dark');
      // …but the control must stop claiming it was saved.
      expect(theme.syncError).not.toBeNull();
      expect(theme.syncing).toBe(false);
    });
  });

  describe('logout isolation', () => {
    it('does not leak one user\'s server preference into the next session', () => {
      const theme = useThemeStore();
      // A signed-in user whose RECORD says dark, with nothing chosen on this device.
      document.documentElement.setAttribute('data-sv-theme', 'dark');
      theme.adoptServerPreference('dark', 'dark');
      localStorage.removeItem('servana.theme');

      theme.forgetUserPreference();

      expect(theme.theme).toBe('light');
      expect(theme.hasExplicitPreference).toBe(false);
      expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('keeps a deliberate anonymous browser choice across logout', () => {
      const theme = useThemeStore();
      theme.setTheme('dark');

      theme.forgetUserPreference();

      // ADR-021 §3: the per-browser choice is a real decision made on this device.
      expect(theme.theme).toBe('dark');
      expect(theme.hasExplicitPreference).toBe(true);
    });
  });
});
