import { defineStore } from 'pinia';

// Placeholder app store proving the Pinia wiring (Plan §6.1). Real stores
// (auth, merchant, branch, permission, theme, notification) arrive in Phase 4.
export const useAppStore = defineStore('app', {
  state: () => ({
    name: 'Servana by Citrus',
    tagline: 'Serve Better. Run Smarter. Grow Steadily.',
  }),
});
