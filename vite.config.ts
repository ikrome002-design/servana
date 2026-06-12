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
