<script setup lang="ts">
import { useAppStore } from '@/stores/app';

const app = useAppStore();

// Brand assets are served by Nginx from the repo public/ dir (Plan §4.1), not
// bundled by Vite. Bind as a runtime URL so the bundler leaves it untouched.
const logoUrl = '/assets/brand/Logo.png';
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-bg p-6 text-text">
    <section
      class="w-full max-w-md rounded-card bg-surface p-8 text-center shadow-card"
    >
      <img
        :src="logoUrl"
        alt="Servana by Citrus"
        class="mx-auto mb-6 h-12 w-auto"
      >
      <h1 class="font-display text-2xl font-extrabold text-brand-deep">
        {{ app.name }}
      </h1>
      <p class="mt-2 text-text-muted">
        {{ app.tagline }}
      </p>
      <p class="mt-6 text-sm text-text-muted">
        Phase 1 scaffold — design system and role layouts arrive in Phase 4.
      </p>
    </section>
  </main>
</template>
