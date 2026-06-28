/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

// The SPA is a standalone Vite app rooted at resources/spa (Plan §4.1: built by
// Vite, served by Nginx). It builds to public/spa, which is gitignored.
export default defineConfig({
  root: fileURLToPath(new URL('./resources/spa', import.meta.url)),
  base: '/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/spa/src', import.meta.url)),
      // Approved role content (landing copy, FAQ, legal) is sourced verbatim
      // from the version-controlled docs via `?raw` imports — a single source of
      // truth, never hand-copied into frontend source (Plan §27.2; Phase 11).
      '@docs': fileURLToPath(new URL('./docs', import.meta.url)),
    },
  },
  // The SPA root is resources/spa; allow reading the approved docs above it so
  // `@docs/**?raw` imports resolve in dev and test.
  server: {
    fs: {
      allow: [fileURLToPath(new URL('.', import.meta.url))],
    },
  },
  build: {
    outDir: fileURLToPath(new URL('./public/spa', import.meta.url)),
    emptyOutDir: true,
    manifest: true,
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.{test,spec}.ts'],
  },
});
