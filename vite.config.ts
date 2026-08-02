/// <reference types="vitest/config" />
import { cpSync, existsSync, readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig, type Plugin } from 'vite';

const repoRoot = fileURLToPath(new URL('.', import.meta.url));
const outDir = fileURLToPath(new URL('./public/spa', import.meta.url));

interface AccountHostSource {
  domains: { local: string };
  accounts: { subdomain: string | null }[];
}

/**
 * The eight approved LOCAL account hosts, derived from the single account-host authority
 * (`config/account-hosts.json`, Phase UI-02 / ADR-016) rather than hard-coded here.
 */
function localAccountHosts(): string[] {
  const source = JSON.parse(
    readFileSync(fileURLToPath(new URL('./config/account-hosts.json', import.meta.url)), 'utf8'),
  ) as AccountHostSource;

  return source.accounts.map((account) =>
    account.subdomain === null ? source.domains.local : `${account.subdomain}.${source.domains.local}`,
  );
}

/**
 * Copy Laravel's `public/assets` tree into the SPA build output (Phase UI-02, UI01-ASSET-005).
 *
 * In production Nginx serves `/assets/*` from Laravel's own public root, which is the
 * authoritative copy. This copy exists so a standalone SPA origin — `vite preview`, which the
 * Playwright harness uses — resolves the same favicon and logo URLs as production instead of
 * 404ing on them. It is copied at build time from the one source, so the two cannot drift.
 */
function copyLaravelPublicAssets(): Plugin {
  return {
    name: 'servana-copy-laravel-public-assets',
    apply: 'build',
    closeBundle() {
      const source = fileURLToPath(new URL('./public/assets', import.meta.url));
      if (existsSync(source)) {
        cpSync(source, `${outDir}/assets`, { recursive: true });
      }
    },
  };
}

// The SPA is a standalone Vite app rooted at resources/spa (Plan §4.1: built by
// Vite, served by Nginx). It builds to public/spa, which is gitignored.
//
// Phase UI-02 (UI01-PROV-002): the emitted chunks live under `spa-assets/`, not
// `assets/`. `public/assets/` is Laravel's own public tree (brand assets, landing
// images), so a shared `/assets/` prefix made the SPA chunks and the brand assets
// fight for the same Nginx location — which is exactly how the deployed shell ended
// up requesting `/assets/*.js` and receiving Laravel's 404 HTML. One prefix per
// owner removes the collision by construction:
//
//   /spa-assets/*   fingerprinted Vite output  (public/spa/spa-assets)
//   /assets/*       Laravel public assets      (public/assets)
export default defineConfig({
  root: fileURLToPath(new URL('./resources/spa', import.meta.url)),
  base: '/',
  plugins: [vue(), copyLaravelPublicAssets()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/spa/src', import.meta.url)),
      // Approved role content (landing copy, FAQ, legal) is sourced verbatim
      // from the version-controlled docs via `?raw` imports — a single source of
      // truth, never hand-copied into frontend source (Plan §27.2; Phase 11).
      '@docs': fileURLToPath(new URL('./docs', import.meta.url)),
    },
  },
  server: {
    // The SPA root is resources/spa; allow reading the approved docs above it so
    // `@docs/**?raw` imports resolve in dev and test.
    fs: {
      allow: [repoRoot],
    },
    // Phase UI-02: an EXPLICIT allowlist of the eight local account hosts. Never
    // `allowedHosts: true` — that disables Vite's DNS-rebinding protection for the
    // dev server, and the eight-host model is exactly the situation people reach for
    // that switch in. `localhost`/`127.0.0.1` stay allowed for the default workflow.
    allowedHosts: [...localAccountHosts(), 'localhost', '127.0.0.1'],
    host: process.env['VITE_DEV_HOST'] ?? 'localhost',
    port: Number(process.env['VITE_DEV_PORT'] ?? 5173),
    // HMR must reach the browser on whichever account host the developer opened, so
    // its host/port/protocol are environment-aware rather than assumed to be
    // localhost. Leaving these undefined keeps Vite's own defaults.
    hmr: {
      host: process.env['VITE_HMR_HOST'] || undefined,
      port: process.env['VITE_HMR_PORT'] ? Number(process.env['VITE_HMR_PORT']) : undefined,
      protocol: process.env['VITE_HMR_PROTOCOL'] || undefined,
    },
  },
  preview: {
    port: Number(process.env['VITE_PREVIEW_PORT'] ?? 4173),
    allowedHosts: [...localAccountHosts(), 'localhost', '127.0.0.1'],
  },
  build: {
    outDir,
    // Distinct from Laravel's public/assets — see the note above.
    assetsDir: 'spa-assets',
    emptyOutDir: true,
    manifest: true,
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.{test,spec}.ts'],
    // Supplies the browser APIs jsdom omits; see the file for why this is not an assertion change.
    setupFiles: ['./vitest.setup.ts'],
  },
});
