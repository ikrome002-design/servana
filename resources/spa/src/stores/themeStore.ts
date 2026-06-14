import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Theme } from '@/types/enums';

const STORAGE_KEY = 'servana.theme';

export const useThemeStore = defineStore('theme', () => {
  function resolveInitial(): Theme {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored === 'dark' || stored === 'light') return stored;
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch {
      return 'light';
    }
  }

  const theme = ref<Theme>(resolveInitial());

  function setTheme(next: Theme): void {
    theme.value = next;
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // localStorage unavailable; in-memory only.
    }
    document.documentElement.classList.toggle('dark', next === 'dark');
  }

  function toggle(): void {
    setTheme(theme.value === 'dark' ? 'light' : 'dark');
  }

  // Sync class on store creation so it matches the initial resolved value.
  document.documentElement.classList.toggle('dark', theme.value === 'dark');

  return { theme, setTheme, toggle };
});
